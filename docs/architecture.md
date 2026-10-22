# Architecture

This document explains how the service is built and why: the data model, how events are processed,
how concurrency and security are handled, the main tradeoffs, and the known limitations. The HTTP
contract itself is described in [`api.md`](api.md).

## Overview

```
              HTTP Basic                                   Bearer token
  CMS ──────────────────────► POST /cms/events             Users / Admins ──────► /api/v1/contents
                                   │                                                   │
                     throttle (per IP) → Basic auth                     Sanctum auth → throttle (per consumer)
                                   │                                                   │
                     ReceiveCmsEventsRequest                          route binding scoped to the role
                     (validates the whole batch)                      → ContentPolicy (admin actions)
                                   │                                                   │
                     CmsEventProcessor                                ContentController /
                     (one transaction, advisory locks,                ContentDisablementController
                      version precedence)                                              │
                                   │                                                   │
                                   └──────────────► contents (PostgreSQL) ◄────────────┘
```

The two surfaces share the `contents` table but write disjoint sets of columns: the event processor
writes only the columns that mirror the CMS, and the administrator endpoints write only the local
override columns.

## Data model

### `contents`

One row per CMS entity, holding its current state. Deleted content keeps its row as a tombstone.

| Column | Type | Owner | Purpose |
|---|---|---|---|
| `id` | `bigint` PK | local | Internal key; used as the pagination cursor. |
| `external_id` | `varchar(255)`, unique | CMS | The event `id`; the public identifier in the API. |
| `version` | `bigint`, `CHECK (version > 0)` | CMS | Latest accepted version. |
| `status` | `published` \| `unpublished` \| `deleted` | CMS | Result of the latest accepted event. |
| `payload` | `jsonb`, nullable | CMS | Content as supplied by the CMS; `NULL` only for tombstones. |
| `source_updated_at` | `timestamptz` | CMS | Timestamp of the latest accepted event. Informational. |
| `disabled_at` | `timestamptz`, nullable | local | Local disable override. |
| `disabled_by` | FK `users`, nullable, `ON DELETE SET NULL` | local | Administrator who set the override. |
| `created_at`, `updated_at` | `timestamptz` | local | Row bookkeeping. |

Integrity is enforced in the database as well as in the model:

- `UNIQUE (external_id)` keeps one row per entity even if two writers race past the application locks.
- `CHECK (version > 0)` mirrors the positive-version rule.
- `CHECK ((status = 'deleted') = (payload IS NULL))` guarantees that a tombstone never holds content
  and that visible content always does.

Indexes are shaped after the queries that actually run:

- `contents_visible_to_users_index` — partial index on `(id) WHERE status = 'published' AND disabled_at IS NULL`.
  It contains exactly the rows a user may see, already in cursor order, so the most frequent query
  (a user paging through content) is an index range scan regardless of how many unpublished,
  disabled or deleted rows exist.
- `(status, id)` — administrator listings, which filter by status and page by id.
- `UNIQUE (external_id)` — single-content lookups and the batch lookup of the event processor.

`payload` is `jsonb` because the content is arbitrary JSON that the service never interprets. It is
handled as a PHP `stdClass` instead of an associative array so that an empty object `{}` survives the
round trip instead of turning into an empty list `[]`.

### `users` and `personal_access_tokens`

API consumers are `users` rows with a `role` (`user` or `admin`) and no password: they authenticate
only with tokens. `role` is excluded from mass assignment and is set explicitly by the command that
creates the consumer. Tokens live in Sanctum's `personal_access_tokens` table, stored as SHA-256
hashes with a per-token `expires_at`.

The CMS is not a row in any table: its credentials come from the environment.

### Why the override lives on `contents`

The local override could live in its own `content_overrides` table, which would make the ownership
split structural. It was kept on `contents` because the user listing needs it in every query: on the
same row it is covered by the partial index above, while a separate table would add a join to every
read. The ownership split is kept by discipline instead: the event processor never assigns the
override columns, and Eloquent only writes the attributes that changed, so a concurrent disable and
event batch update disjoint columns and neither overwrites the other.

## Event processing

### Validation

`ReceiveCmsEventsRequest` decodes the raw request body itself rather than using Laravel's parsed
input. The global middleware that trims strings and converts empty strings to `null` would otherwise
alter CMS content, and the parsed input cannot tell `{}` from `[]`. The whole batch is validated
before anything is processed: 1 to 100 events, known types, a strict JSON integer version, an
RFC 3339 timestamp with time zone, and an object payload for `publish`/`unpublish`. A single invalid
event rejects the whole batch with `422`, and the response lists the offending fields by index.
Valid events are turned into immutable `CmsEvent` objects, so nothing past this point depends on HTTP.

### Algorithm

`CmsEventProcessor::process()` runs the batch in a single transaction:

1. Collect the distinct `external_id`s of the batch.
2. Acquire a transaction-scoped advisory lock for each of them (see [Concurrency](#concurrency)).
3. Load all existing rows for those ids with one `WHERE external_id IN (...)` query, keyed by id.
   This happens **after** locking, so under `READ COMMITTED` it sees whatever a concurrent batch for
   the same ids committed while this one was waiting.
4. Walk the events **in the order received**. Each event goes to the in-memory row for its id
   (a new, unsaved row when the id is unknown), so several events for the same entity within one
   batch see each other's effects:
   - unknown content, or `version` greater than the stored one → apply and save;
   - equal version → ignore as `duplicate`;
   - lower version → ignore as `stale`.
5. Commit. Only then are the per-event and per-batch log entries written, so the log never reports
   an event as applied when its transaction was rolled back.

Applying an event (`Content::applyEvent()`) sets `version`, `status`, `payload` (removed for
`delete`) and `source_updated_at`, and never touches the override columns. The model refuses to apply
an event whose version does not increase, so the precedence rule holds even if the model is used
outside the processor.

This design covers each delivery anomaly with the same rule:

| Situation | Handling |
|---|---|
| Re-delivery of an applied event | Equal version → `duplicate`. Deliveries are idempotent, so the CMS can retry freely. |
| Out-of-order delivery | Lower version → `stale`. The state is always that of the highest version seen. |
| Skipped versions | Only "greater than" is required, never "exactly one more". |
| Unpublish of a version never received (v3 published, v4 unpublished) | `4 > 3` → applied: status `unpublished`, payload of v4. No assumption of contiguous versions or of having seen a publish first. |
| First event for an entity has any version or type | A new row is created at that version, including a tombstone for a `delete`. |
| Older `publish`/`unpublish` after a `delete` | The tombstone keeps the delete version → `stale`; deleted content cannot be restored by an old event. |
| Same version with a different type | `duplicate`: a version identifies one state of the entity. |

The event `timestamp` is stored but never compared: clocks on the CMS side may be skewed or events
may be stamped at different stages, while the version is the CMS's own ordering of changes.

### Failures

If anything throws inside the transaction, the whole batch is rolled back. The failure is logged as
`cms.batch.failed` with the batch id, the exception class, its code (the SQLSTATE for database
errors) and the file and line where it originated, and the CMS receives a `500` asking it to retry.

The exception message is deliberately left out of the log, and the exception returned to the HTTP
layer does not carry the original as its previous exception. PostgreSQL error messages quote the
failing row (`Failing row contains (...)`), which would put CMS content into the logs through either
the processor or the framework's exception reporter. The cost is that the full stack trace of the
original error is lost; the origin (file and line) and SQLSTATE are usually enough to diagnose it, and
the error can be reproduced by replaying the batch.

### Synchronous processing

Batches are processed synchronously, inside the request.

- **Atomicity and rejection are straightforward.** Validation and application happen in one place;
  the batch is either fully applied or not at all, and the CMS knows which by the status code.
- **The `2xx` means "durably applied".** It is sent after the commit, so the CMS knows exactly which
  deliveries to retry. Combined with idempotency by version, at-least-once delivery from the CMS
  produces exactly-once effects here.
- **Bounded cost.** A batch has at most 100 events and costs one lock statement per distinct id, a
  single lookup query and one write per applied event, so the latency is low.
- **No extra infrastructure.** No queue workers to operate, monitor or keep in order.

The tradeoff is that the CMS waits on this service's database: a slow or unavailable database turns
into slow or failed deliveries (which the CMS retries), and a burst of deliveries is absorbed by the
web workers rather than smoothed by a queue. If that became a problem, the next step would be an
inbox: persist the validated batch and return `202`, then apply batches with workers that keep the
per-entity ordering. The precedence rule would not change, because it already tolerates any delivery
order.

## Concurrency

Two deliveries that touch the same content may run at the same time. Row locks
(`SELECT ... FOR UPDATE`) cannot serialize them when the content does not exist yet, because there is
no row to lock, so both would try to insert it.

Instead, each batch takes a PostgreSQL transaction-scoped advisory lock (`pg_advisory_xact_lock`) per
distinct `external_id` before reading anything. A lock exists independently of any row, is released
automatically at commit or rollback, and serializes exactly the batches that share an entity; batches
for different content proceed in parallel.

- **Lock keys** are 64-bit integers derived from the id with `xxh64`. Two ids that hash to the same
  key only cause extra serialization, never an incorrect result.
- **Deadlock freedom.** Every batch acquires its keys in ascending key order (sorting the keys, not the
  ids, keeps this true when ids collide), one statement per key so the order is guaranteed. Two
  batches can therefore never wait on each other in a cycle.
- **Guard rail.** Requesting locks outside a transaction throws, because a transaction-scoped lock
  taken outside one would be released immediately.

The locking strategy sits behind the `ContentLockManager` interface; `PostgresAdvisoryLockManager` is
the only implementation and is bound in the service provider.

Administrator overrides do not take these locks: they update only the override columns, and
PostgreSQL's row lock orders the two `UPDATE`s, so neither side loses a write.

## Authentication and authorization

### CMS

HTTP Basic, with the username and password read from `CMS_WEBHOOK_USERNAME` and
`CMS_WEBHOOK_PASSWORD`.

- **Fail closed.** If either value is empty, every request is rejected and an `ERROR` is logged.
- **Constant-time comparison.** Both the username and the password are compared through their SHA-256
  digests with `hash_equals`, so the comparison time depends on neither the secret's content nor its
  length. Both comparisons always run.
- **Brute-force cap.** The webhook rate limiter (per IP) runs before authentication.
- **Failures are logged** with the reason (`missing_credentials`, `invalid_credentials`,
  `credentials_not_configured`) and client IP, never with the submitted credentials.

### Consumers

Consumers use Laravel Sanctum personal access tokens sent as `Authorization: Bearer`. Sanctum is
configured in token-only mode (no cookie-based stateful authentication), so requests are stateless
and no session or CSRF surface exists.

Consumers are created, and their tokens issued and revoked, through Artisan commands
(`consumers:create`, `consumers:token`, `consumers:revoke`) instead of a login endpoint:

- there is no password to store, reset or brute-force, and no public endpoint to attack;
- the token is shown once and stored only as a SHA-256 hash;
- each token has an expiry (90 days by default, at most 365) and can be revoked immediately.

The role belongs to the consumer (`users.role`), not to the token. Token abilities were not used:
the two roles are coarse and fixed, and abilities would duplicate them in a second place that could
disagree.

Unauthenticated requests always get a `401` JSON response, also when the client does not send
`Accept: application/json` (Laravel would otherwise try to redirect to a login route).

### Credential separation

The CMS credentials exist only in the environment; consumer credentials exist only in the database.
The webhook only accepts Basic credentials and the API only accepts Sanctum tokens, so neither works
on the other surface. A test asserts that every registered route requires one of the two
authentication middlewares, which also guards against framework or package routes (such as health
checks or storage routes) being exposed by accident.

### Browser access (CORS)

No browser origin may call the API unless it is listed in `CORS_ALLOWED_ORIGINS`; the list is empty
by default. CORS only matters for scripts running in a browser, and since tokens are never attached
automatically by browsers, an open policy would not let another site act on a consumer's behalf.
It would, however, make it easy to ship a token inside a public front-end, where any visitor can read
it. Keeping CORS closed unless an origin is explicitly trusted makes that a deliberate choice. Allowed
origins get only the methods and headers the API uses (`GET`, `PUT`, `DELETE`; `Accept`,
`Authorization`, `Content-Type`), can read the `X-Request-Id` and rate-limit headers, and never
receive credentials support. The CMS webhook is outside the CORS paths entirely.

### Authorization

Visibility is defined once, in `Content::visibleTo(User)`:

- user: `status = 'published' AND disabled_at IS NULL`;
- admin: `status IN ('published', 'unpublished')`;
- nobody sees `deleted`.

The listing applies this scope, and so does the route binding of `{content}`: a content outside the
caller's visibility does not resolve and yields `404` on every route, including the administrator
routes. Only after the content has been resolved does `ContentPolicy::manageDisablement` decide the
`403` for users. As a result, a user can never learn whether unpublished, disabled or deleted content
exists, and the `404` body is generic (it does not leak the model name or the id).

There is no route that writes CMS-managed data; any write method on `/contents` returns `405`.

## API design

- **Versioned** under `/api/v1`, with Eloquent API resources shaping every response.
- **Public identifier** is the CMS `id`, which is what consumers know; the internal key is not exposed
  as an identifier.
- **Cursor pagination** ordered by the internal id. It needs no `COUNT(*)`, uses the indexes above
  directly, and stays stable while the CMS keeps writing (offset pagination would skip or repeat rows
  when content is inserted). The cost is that pages cannot be addressed by number and no total is
  returned.
- **Role-dependent representation.** `disabled` and `disabled_at` are returned only to
  administrators; for users disabled content simply does not exist.
- **`source_updated_at` instead of `updated_at`.** The local `updated_at` also changes when an
  administrator toggles the override, so it would not mean "last synchronized from the CMS".
- **Disable as a sub-resource.** `PUT` and `DELETE` on `/contents/{id}/disabled` are naturally
  idempotent and keep the override clearly separate from the CMS-managed resource, which stays
  read-only.
- **Explicit errors for filters.** A user sending administrator filters receives `422` instead of
  having them silently ignored, so a client never believes it filtered when it did not.
- **Rate limiting per consumer**, so one consumer cannot exhaust another's quota.

## Observability

CMS activity is written to a dedicated `cms` log channel (daily files, one JSON object per line), so it
can be shipped and queried separately. Every entry carries the request id in `extra.request_id`, and
batch entries also carry it as `batch_id` — the same value returned to the CMS in the response and in
the `X-Request-Id` header.

| Message | Level | Context |
|---|---|---|
| `cms.event.applied` | info | `batch_id`, `external_id`, `type`, `version`, `previous_version` (when the content existed), `outcome` |
| `cms.event.ignored` | info | the same, plus `reason` (`duplicate` / `stale`) and the stored version as `previous_version` |
| `cms.batch.processed` | info | `batch_id`, `event_count`, `applied_count`, `ignored_count`, `duration_ms` |
| `cms.batch.rejected` | warning | `event_count`, `invalid_fields` (field paths only, never their values) |
| `cms.batch.failed` | error | `batch_id`, `event_count`, `exception_class`, `exception_code`, `origin` |
| `cms.auth.failed` | warning / error | `reason`, `client_ip` |

Administrator actions are written to the default application log as `content.disabled` and
`content.enabled` with `external_id` and `admin_id`, only when the state actually changes.

Example (`storage/logs/cms-YYYY-MM-DD.log`):

```json
{"message":"cms.event.ignored","context":{"batch_id":"01a163ae-1d12-7286-b74f-77fdcdf17b39","external_id":"article-1","type":"publish","version":3,"previous_version":5,"outcome":"ignored","reason":"stale"},"level":200,"level_name":"INFO","channel":"cms","datetime":"2026-10-22T12:55:17.578924+00:00","extra":{"request_id":"01a163ae-1d12-7286-b74f-77fdcdf17b39"}}
```

What is never logged: payloads or any other CMS content, exception messages from the processing path,
the `Authorization` header, webhook credentials and tokens.

## Code organization

| Concern | Where |
|---|---|
| HTTP validation and mapping to domain objects | `app/Http/Requests` |
| Webhook authentication | `app/Http/Middleware/AuthenticateCmsWebhook`, `app/Auth/CmsWebhookCredentials` |
| Batch orchestration (transaction, locks, ordering, logging) | `app/Services/Cms/CmsEventProcessor` |
| Transition rule and visibility | `app/Models/Content` (`acceptsVersion`, `applyEvent`, `visibleTo`) |
| Event type semantics | `app/Enums/CmsEventType` (`resultingStatus`, `carriesPayload`) |
| Concurrency control | `app/Contracts/ContentLockManager`, `app/Infrastructure/PostgresAdvisoryLockManager` |
| Immutable values crossing layers | `app/Data` (`CmsEvent`, `EventResult`, `BatchResult`) |
| Response shapes | `app/Http/Resources` |
| Authorization of administrator actions | `app/Policies/ContentPolicy` |
| Structured log entries | `app/Logging` |
| Consumer and token management | `app/Console/Commands` |

Controllers only connect these pieces. Adding an event type means adding a case to `CmsEventType`
with its resulting status; the processor does not change. A repository layer over Eloquent was not
introduced: the queries are few, already named (scopes) and tied to PostgreSQL features on purpose.

## Testing

The suite uses PHPUnit against a real PostgreSQL database, because several guarantees (partial index
predicates, check constraints, `jsonb`, advisory locks, transaction semantics) depend on it.

- **Feature tests** cover both HTTP surfaces end to end: authentication, validation, every precedence
  scenario, atomic rollback, visibility per role, overrides surviving later events, `405` for writes,
  pagination, rate limits, logs (asserting both the context and the absence of payloads) and the
  Artisan commands.
- **Concurrency tests** open a second database connection to prove that a lock held by one
  transaction blocks another transaction on the same id and not on a different one.
- **Unit tests** cover the rules that need no framework: enums, the event value object and the
  credential comparison.
- **Isolation from development data.** `phpunit.xml` forces the `cms_webhook_test` database, and the
  base test case refuses to run if the configured database name does not end with `_test`. Tests also
  write no log files.

## Decisions and tradeoffs

| Decision | Alternative | Why |
|---|---|---|
| Precedence only by version; a newer version after a delete is applied | Treat delete as final | The CMS is the source of truth; refusing a newer version would leave the service out of sync forever. Older events still cannot restore deleted content. See the [README](../README.md#design-decisions). |
| Delete as a tombstone row | Hard delete | A deleted row would forget its version and an older `publish` would recreate it. |
| Synchronous processing in one transaction | Queue + workers | Trivial atomicity, `2xx` only after commit, no extra infrastructure; costs latency under load. |
| Advisory locks per id, ordered by key | `SELECT ... FOR UPDATE`; table lock; serializable isolation with retries | Works for rows that do not exist yet, serializes only batches that conflict, cannot deadlock. |
| Override columns on `contents` | Separate override table | Keeps the user listing on one partial index without a join. |
| `payload` as `jsonb` handled as an object | Associative array; normalized columns | Content is arbitrary and opaque; keeps `{}` distinct from `[]`. |
| Sanctum tokens issued from the CLI | Login endpoint; OAuth2 | Stateless, standard in Laravel, no password surface; OAuth2 would be heavy for two fixed roles. |
| `404` for content outside the caller's visibility, `403` only for visible content | `403` everywhere | Never reveals that hidden content exists. |
| CORS closed unless origins are configured | Framework default allowing every origin | Least privilege; avoids encouraging tokens in public front-ends. |
| Cursor pagination | Offset pagination | Stable under concurrent writes, no `COUNT(*)`; no page numbers. |
| Exception message never logged on batch failure | Log the message | PostgreSQL messages include the failing row; loses the original stack trace. |

## Known limitations

- **Synchronous latency.** Delivery latency depends on this service's database; there is no buffering
  of bursts (see [Synchronous processing](#synchronous-processing)).
- **Tombstones are kept forever.** Purging them would allow an older delayed event to recreate deleted
  content, so no purge exists. Each tombstone is one small row without payload.
- **Only the latest state is kept.** There is no history of versions or payloads; the logs record what
  happened to each event, but not the content.
- **Payload fidelity.** `jsonb` normalizes the stored JSON: object keys are reordered and duplicate
  keys keep only the last value. Payloads are returned semantically equal to what was received, not
  byte-for-byte identical.
- **Very large integers.** Integers in a payload beyond the signed 64-bit range lose precision when decoded by PHP.
- **Version reuse is not detected.** Two events with the same version and different content are
  treated as a duplicate; the service trusts that the CMS never reuses a version.
- **Unauthenticated API requests are not rate limited.** Authentication runs before the per-consumer
  limiter, so requests without a valid token are rejected with `401` without consuming a quota. Token
  entropy makes guessing impractical, but a reverse proxy or WAF should still limit abusive clients.
- **Proxies.** Both the webhook rate limit and the logged client IP use the connecting address.
  Behind a load balancer, trusted proxies must be configured so the real client address is used;
  otherwise every delivery shares one quota.
- **Token management is CLI-only.** There is no self-service endpoint, no token listing per consumer
  and no fine-grained scopes; revocation removes all tokens of a consumer at once.
- **Lost authorship on admin deletion.** Deleting an administrator keeps its overrides but clears
  `disabled_by`.
- **Cursor contents.** The cursor is opaque to clients but is a base64-encoded internal id, not an
  encrypted value. It reveals arrival order, which is not sensitive here.
- **Single database.** Advisory locks rely on all writers sharing one PostgreSQL primary; this holds
  for this deployment model, which uses no read replicas.
