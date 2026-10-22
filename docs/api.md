# API contract

The service exposes two independent HTTP surfaces with separate credentials:

| Surface | Base path | Caller | Authentication |
|---|---|---|---|
| CMS webhook | `/cms` | The CMS | HTTP Basic (credentials from the environment) |
| Consumer API | `/api/v1` | Users and administrators | Bearer token (Laravel Sanctum personal access token) |

Neither credential works on the other surface: the CMS cannot read consumer resources and a consumer
token cannot deliver events. There are no unauthenticated endpoints, including health checks.

A machine-readable description of both surfaces is available in [`openapi.yaml`](openapi.yaml)
(OpenAPI 3.1). The JSON files in [`examples/`](examples) are ready-to-send webhook batches.

## Conventions

- Request and response bodies are JSON (`application/json`). Errors are always rendered as JSON,
  whatever the `Accept` header says.
- Every response carries an `X-Request-Id` header (UUIDv7). The same id appears in every log entry
  written while handling the request; for the webhook it is also the `batch_id`.
- Timestamps in responses are UTC in RFC 3339 / ISO 8601 Zulu form (`2026-01-01T10:00:00Z`).
- Rate-limited responses include `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a `429` also
  includes `Retry-After` (seconds).

### Error format

```json
{ "message": "Human-readable summary." }
```

Validation errors (`422`) add an `errors` object keyed by the offending field:

```json
{
  "message": "The events.1.version field must be an integer. (and 1 more error)",
  "errors": {
    "events.1.version": ["The events.1.version field must be an integer."],
    "events.1.payload": ["The events.1.payload field must be a JSON object."]
  }
}
```

---

## CMS webhook

### `POST /cms/events`

Delivers a batch of content events. The batch is validated as a whole and then applied in a single
database transaction, in the order received. The response is sent only after the transaction has
committed, so a `200` means every event in the batch has been durably applied or deliberately ignored.

#### Authentication

HTTP Basic with the credentials configured in `CMS_WEBHOOK_USERNAME` and `CMS_WEBHOOK_PASSWORD`.
While either variable is empty the endpoint rejects every request (fail closed).

```bash
curl -i -u "$CMS_WEBHOOK_USERNAME:$CMS_WEBHOOK_PASSWORD" \
  -H 'Content-Type: application/json' \
  --data @docs/examples/batch.json \
  http://localhost:8000/cms/events
```

#### Request body

A JSON array with **1 to 100** events:

```json
[
  {
    "type": "publish",
    "id": "content-123",
    "version": 2,
    "timestamp": "2026-01-01T10:00:00Z",
    "payload": { "title": "Example content", "description": "Some content" }
  },
  {
    "type": "delete",
    "id": "content-789",
    "version": 5,
    "timestamp": "2026-01-01T10:10:00Z"
  }
]
```

| Field | Type | Rules |
|---|---|---|
| `type` | string | Required. `publish`, `unpublish` or `delete`. |
| `id` | string | Required. CMS identifier of the content, at most 255 characters. |
| `version` | integer | Required. JSON integer `>= 1`. Strings (`"2"`), floats (`2.0`) and booleans are rejected. |
| `timestamp` | string | Required. RFC 3339 date-time with a time zone (`Z` or `±hh:mm`), optional fraction of a second. |
| `payload` | object | Required for `publish` and `unpublish`, must be a JSON object (`{}` is accepted). Ignored for `delete`. |

Unknown fields on an event are ignored. The payload is stored as received, without interpretation.

#### Event semantics

| Event | Effect on the stored content | Visible to users | Visible to administrators |
|---|---|---|---|
| `publish` | Status `published`, payload replaced | Yes, unless locally disabled | Yes |
| `unpublish` | Status `unpublished`, payload replaced | No | Yes |
| `delete` | Status `deleted`, payload removed, version kept as a tombstone | No | No |

#### Precedence

Precedence is decided **only by `version`**; `timestamp` is stored for information and never compared.
For each event, in the order received:

- No content with that `id` exists yet: the event is **applied**, whatever its version and type.
  A `delete` received first creates a tombstone.
- `version` is greater than the stored version: the event is **applied**. Versions do not have to be
  consecutive, so an `unpublish` for version 4 is applied on top of version 3 even if version 4 was
  never published or received.
- `version` equals the stored version: the event is **ignored** with reason `duplicate`, even if its
  type differs (a re-delivery or a repeated event within the same batch).
- `version` is lower than the stored version: the event is **ignored** with reason `stale`. This is
  what prevents an older `publish` or `unpublish` from restoring deleted content.

An event with a newer version than a tombstone is applied and makes the content available again; see
[Design decisions](../README.md#design-decisions) for the reasoning. Events never change the local
disable override set by administrators.

Ignoring an event is not an error: a batch made only of duplicates still returns `200`, which lets the
CMS retry deliveries safely.

#### Responses

**`200 OK`** — the batch was applied. `events` lists one result per received event, in order.
`reason` is present only for ignored events. The payload is never echoed back.

```json
{
  "data": {
    "batch_id": "01a163ae-1d12-7286-b74f-77fdcdf17b39",
    "received": 2,
    "applied": 1,
    "ignored": 1,
    "events": [
      { "id": "article-1", "type": "publish", "version": 5, "outcome": "applied" },
      { "id": "article-1", "type": "publish", "version": 3, "outcome": "ignored", "reason": "stale" }
    ]
  }
}
```

**`401 Unauthorized`** — missing or invalid credentials, or credentials not configured on the server.
Includes `WWW-Authenticate: Basic realm="cms-webhook", charset="UTF-8"`.

```json
{ "message": "Invalid CMS webhook credentials." }
```

**`422 Unprocessable Content`** — the body is not a JSON array of 1 to 100 valid events. Errors are
keyed by event index (`events.3.version`). **Nothing is applied**, not even the valid events of the
batch.

**`429 Too Many Requests`** — more than `CMS_WEBHOOK_RATE_LIMIT_PER_MINUTE` deliveries (default 120)
from the same IP address within a minute. The limit is checked before authentication, so it also caps
credential guessing. Retry after `Retry-After` seconds.

**`500 Internal Server Error`** — the batch failed while being applied (for example, the database
became unavailable). The transaction was rolled back and **nothing is applied**; the delivery can be
retried as is.

```json
{
  "message": "The batch could not be processed and no events were applied. Retry the delivery.",
  "batch_id": "01a163ae-1d12-7286-b74f-77fdcdf17b39"
}
```

#### Delivery guidance for the CMS

- Retry on `429`, `500` and network failures; retries are safe because already applied events are
  reported as `duplicate`.
- Do not retry a `422` unchanged: the batch is malformed.
- Batches may be delivered concurrently. Batches that touch the same content are serialized, and the
  version rule makes the final state independent of which batch commits first.

---

## Consumer API

### Authentication

Every request needs a bearer token:

```http
Authorization: Bearer 1|ExampleTokenValueShownOnceByTheConsumersTokenCommand
```

Tokens are issued from the command line by whoever operates the service (there is no login endpoint):

```bash
php artisan consumers:create "Reporting App" reporting@example.com          # role: user
php artisan consumers:create "Editorial Admin" editor@example.com --admin   # role: admin
php artisan consumers:token reporting@example.com --name=production --expires-in-days=30
php artisan consumers:revoke reporting@example.com
```

`consumers:token` prints the token once; only its SHA-256 hash is stored. Tokens expire after
`API_TOKEN_TTL_DAYS` days (default 90) unless `--expires-in-days` (1 to 365) says otherwise.
`consumers:revoke` deletes every token of the consumer immediately.

The role belongs to the consumer, not to the token, so every token of an administrator has
administrator access.

### Roles and visibility

| Content state | `user` | `admin` |
|---|---|---|
| Published | Yes | Yes |
| Published and locally disabled | No | Yes |
| Unpublished (disabled or not) | No | Yes |
| Deleted | No | No |

Content that a consumer is not allowed to see is reported as `404 Not Found` on every endpoint,
exactly as if it did not exist, so its existence is never revealed. A `403 Forbidden` is returned only
when the consumer can see the content but may not perform the action (a user calling an
administrator endpoint).

### Content resource

```json
{
  "id": "content-123",
  "version": 2,
  "status": "published",
  "payload": { "title": "Example content", "description": "Some content" },
  "source_updated_at": "2026-01-01T10:00:00Z",
  "disabled": true,
  "disabled_at": "2026-10-22T12:55:32Z"
}
```

| Field | Type | Description |
|---|---|---|
| `id` | string | CMS identifier of the content. |
| `version` | integer | Latest version accepted from the CMS. |
| `status` | string | `published` or `unpublished` (unpublished is only ever returned to administrators). |
| `payload` | object | Content as last supplied by the CMS. |
| `source_updated_at` | string | `timestamp` of the event that produced the current version. |
| `disabled` | boolean | **Administrators only.** Whether the content is locally disabled. |
| `disabled_at` | string \| null | **Administrators only.** When it was disabled. |

Users never see the local override fields: content that is disabled is simply absent for them.

### `GET /api/v1/contents`

Lists the contents visible to the consumer, ordered by the time they were first received, with cursor
pagination.

| Query parameter | Who | Description |
|---|---|---|
| `per_page` | all | Page size, 1 to 100. Default 15. |
| `cursor` | all | Opaque cursor taken from `meta.next_cursor` / `meta.prev_cursor` (or the `links`). |
| `filter[status]` | admin | `published` or `unpublished`. |
| `filter[disabled]` | admin | `true`/`false` (or `1`/`0`). |

Filters are rejected with `422` when sent by a user (they would otherwise be silently ignored and
mislead the caller), and unknown filter keys are rejected for administrators.

```bash
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/v1/contents?per_page=2"
curl -g -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/api/v1/contents?filter[status]=unpublished&filter[disabled]=false"
```

(`-g` stops `curl` from treating the brackets as a URL pattern; other clients may need them encoded
as `%5B` and `%5D`.)

**`200 OK`**

```json
{
  "data": [
    {
      "id": "content-123",
      "version": 2,
      "status": "published",
      "payload": { "title": "Example content", "description": "Some content" },
      "source_updated_at": "2026-01-01T10:00:00Z"
    },
    {
      "id": "article-1",
      "version": 5,
      "status": "published",
      "payload": { "title": "Fifth revision" },
      "source_updated_at": "2026-01-02T09:05:00Z"
    }
  ],
  "links": {
    "first": null,
    "last": null,
    "prev": null,
    "next": "http://localhost:8000/api/v1/contents?per_page=2&cursor=eyJpZCI6MiwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ"
  },
  "meta": {
    "path": "http://localhost:8000/api/v1/contents",
    "per_page": 2,
    "next_cursor": "eyJpZCI6MiwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ",
    "prev_cursor": null
  }
}
```

`next` is `null` on the last page. Pages do not report a total count and cannot be addressed by
number; a cursor stays valid while contents are being added or changed.

**`422`** — invalid `per_page`, or a filter sent by a user / an invalid filter sent by an administrator.

### `GET /api/v1/contents/{id}`

Returns one content by its CMS identifier.

```bash
curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/contents/content-123
```

**`200 OK`** — `{ "data": { ...content resource... } }`

**`404 Not Found`** — the content does not exist, was deleted, or is not visible to the consumer.

```json
{ "message": "Resource not found." }
```

### `PUT /api/v1/contents/{id}/disabled` — administrators

Locally disables the content: users stop seeing it, administrators keep seeing it with
`"disabled": true`. The CMS data (`version`, `status`, `payload`) is not modified, and later CMS events
do not clear the override.

Idempotent: disabling content that is already disabled keeps the original `disabled_at`.

```bash
curl -X PUT -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/api/v1/contents/content-123/disabled
```

**`200 OK`** — `{ "data": { ...content resource with "disabled": true... } }`

### `DELETE /api/v1/contents/{id}/disabled` — administrators

Re-enables the content. Idempotent.

**`200 OK`** — `{ "data": { ...content resource with "disabled": false, "disabled_at": null... } }`

Both disablement endpoints return:

- **`403 Forbidden`** when called by a user for content the user can see.
- **`404 Not Found`** for content that does not exist, is deleted, or is not visible to the caller.

### Errors common to every consumer endpoint

| Status | When |
|---|---|
| `401 Unauthorized` | Missing, invalid, expired or revoked token (`{"message": "Unauthenticated."}`). |
| `405 Method Not Allowed` | Any attempt to create, update or delete content (`POST`, `PUT`, `PATCH`, `DELETE` on `/contents` or `/contents/{id}`). CMS-managed data is read-only for consumers. |
| `429 Too Many Requests` | More than `API_RATE_LIMIT_PER_MINUTE` requests (default 60) from the same consumer within a minute. Each consumer has its own quota. |
