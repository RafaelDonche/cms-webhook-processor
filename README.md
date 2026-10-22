# CMS Webhook Processor

A Laravel service that receives content events from an external CMS through a webhook, keeps a
synchronized representation of that content in PostgreSQL, and exposes it through a secure REST API
with role-based visibility and local administrative overrides.

- **Webhook** `POST /cms/events` (HTTP Basic): batches of 1 to 100 `publish`, `unpublish` and `delete`
  events, validated as a whole and applied atomically in the order received.
- **Correct under real delivery conditions**: duplicates, out-of-order delivery and skipped versions
  are resolved by version precedence; deleted content cannot be restored by older events; concurrent
  deliveries are serialized per content.
- **Consumer API** `/api/v1/contents` (bearer tokens): users see published content, administrators
  also see unpublished and locally disabled content and can disable or re-enable it without touching
  CMS data.
- **Observability**: structured JSON logs for every applied, ignored and rejected event, correlated by
  a request id returned to the caller — without logging content or credentials.

## Documentation

| Document | Contents |
|---|---|
| [docs/api.md](docs/api.md) | Webhook and consumer API contract: authentication, endpoints, parameters, responses, errors, `curl` examples |
| [docs/openapi.yaml](docs/openapi.yaml) | The same contract as an OpenAPI 3.1 description |
| [docs/architecture.md](docs/architecture.md) | Data model, event processing, concurrency, security, tradeoffs and known limitations |
| [docs/examples/](docs/examples) | Ready-to-send webhook batches used in the walkthrough below |

## Tech stack

- PHP 8.3+ and Laravel 13
- PostgreSQL 15+
- Laravel Sanctum for consumer API tokens
- PHPUnit, Laravel Pint and Larastan (level 8)

## Requirements

- PHP 8.3+ with the `pdo_pgsql` extension
- Composer 2
- A running PostgreSQL server

## Setup

1. Create two databases, one for development and one for the test suite:

   ```sql
   CREATE DATABASE cms_webhook;
   CREATE DATABASE cms_webhook_test;
   ```

2. Create the `.env` file and fill in the database credentials (`DB_USERNAME`, `DB_PASSWORD`) and the
   webhook credentials (`CMS_WEBHOOK_USERNAME`, `CMS_WEBHOOK_PASSWORD`):

   ```bash
   cp .env.example .env
   ```

   Use a long random value for the webhook password, for example:

   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

3. Install the dependencies, generate the application key and run the migrations:

   ```bash
   composer setup
   ```

4. Optionally, seed demo data: an administrator (`admin@example.com`), a user (`user@example.com`) and
   contents in every state (published, unpublished, disabled and deleted):

   ```bash
   php artisan db:seed
   ```

## Configuration

All settings are read from environment variables (`.env`). Besides the standard Laravel ones:

| Variable | Default | Description |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `127.0.0.1`, `5432`, `cms_webhook`, —, — | PostgreSQL connection. |
| `CMS_WEBHOOK_USERNAME` | empty | HTTP Basic username the CMS must send. |
| `CMS_WEBHOOK_PASSWORD` | empty | HTTP Basic password the CMS must send. While either credential is empty, the webhook rejects every request. |
| `CMS_WEBHOOK_RATE_LIMIT_PER_MINUTE` | `120` | Webhook deliveries accepted per minute from one IP address. |
| `API_RATE_LIMIT_PER_MINUTE` | `60` | Consumer API requests accepted per minute from one consumer. |
| `API_TOKEN_TTL_DAYS` | `90` | Lifetime of newly issued consumer tokens when no explicit lifetime is given. |
| `CORS_ALLOWED_ORIGINS` | empty | Comma-separated origins allowed to call the consumer API from a browser (e.g. `https://admin.example.com`). Empty means no browser origin is allowed; server-to-server clients are unaffected. |
| `LOG_LEVEL` | `debug` | Minimum level written to the logs. |
| `LOG_DAILY_DAYS` | `14` | Days of CMS log files kept (`storage/logs/cms-YYYY-MM-DD.log`). |

`.env.example` is set up for local development. In production set `APP_ENV=production` and
`APP_DEBUG=false` (otherwise error responses include stack traces), and keep the webhook credentials in
your secret store.

## Running

```bash
composer dev
```

The service is available at `http://localhost:8000`. It has no unauthenticated endpoints.

## Managing API consumers

Consumers and their tokens are managed from the command line; there is no login endpoint.

```bash
php artisan consumers:create "Reporting App" reporting@example.com          # role: user
php artisan consumers:create "Editorial Admin" editor@example.com --admin   # role: admin

php artisan consumers:token reporting@example.com                            # expires in API_TOKEN_TTL_DAYS
php artisan consumers:token editor@example.com --name=ci --expires-in-days=7

php artisan consumers:revoke reporting@example.com                           # revokes every token
```

The token is printed only once and stored as a hash. Send it as `Authorization: Bearer <token>`.

## Walkthrough

With the server running, the demo data seeded and the shell variables below set, these requests show
the main behaviors. Every response carries an `X-Request-Id` header that also appears in the logs.

```bash
export CMS_AUTH="$CMS_WEBHOOK_USERNAME:$CMS_WEBHOOK_PASSWORD"   # values from your .env
export ADMIN_TOKEN=$(php artisan consumers:token admin@example.com | tail -n 1)
export USER_TOKEN=$(php artisan consumers:token user@example.com | tail -n 1)

deliver() {
  curl -s -u "$CMS_AUTH" -H 'Content-Type: application/json' --data @"docs/examples/$1" \
    http://localhost:8000/cms/events
  echo
}
```

**Deliver a batch, then deliver it again.** The first delivery applies the three events; the second
reports all of them as `duplicate`, so retries are safe.

```bash
deliver batch.json
deliver batch.json
```

**Out-of-order delivery.** Version 3 arrives after version 5 and is ignored as `stale`.

```bash
deliver out-of-order.json
```

**Unpublish of a version that was never published.** Version 3 is published, then version 4 arrives
as an `unpublish`. The content becomes unpublished with the payload of version 4: users stop seeing
it, administrators see the new payload.

```bash
deliver unpublish-unseen-version.json
curl -s -H "Authorization: Bearer $USER_TOKEN"  http://localhost:8000/api/v1/contents/article-2   # 404
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" http://localhost:8000/api/v1/contents/article-2   # version 4, unpublished
```

**Delete, then an older publish.** The older `publish` is ignored as `stale` and the content stays
unavailable to everyone.

```bash
deliver delete-then-older.json
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" http://localhost:8000/api/v1/contents/article-3   # 404
```

**Invalid batch.** One invalid event rejects the whole batch with `422`; the valid event in it is not
applied either.

```bash
deliver invalid.json
```

**Local override.** An administrator disables published content and users lose access until it is
re-enabled. CMS events never clear the override.

```bash
curl -s -X PUT    -H "Authorization: Bearer $ADMIN_TOKEN" http://localhost:8000/api/v1/contents/content-123/disabled
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $USER_TOKEN" http://localhost:8000/api/v1/contents/content-123   # 404
curl -s -X DELETE -H "Authorization: Bearer $ADMIN_TOKEN" http://localhost:8000/api/v1/contents/content-123/disabled
```

**Listing.** Users get published, enabled content; administrators can filter.

```bash
curl -s -H "Authorization: Bearer $USER_TOKEN"  "http://localhost:8000/api/v1/contents?per_page=5"
curl -s -g -H "Authorization: Bearer $ADMIN_TOKEN" "http://localhost:8000/api/v1/contents?filter[status]=unpublished"
```

The CMS log (`storage/logs/cms-YYYY-MM-DD.log`) records one JSON line per event and per batch.

## Testing and quality

```bash
composer test      # PHPUnit suite
composer lint      # Code style check (Pint)
composer format    # Fix code style (Pint)
composer analyse   # Static analysis (Larastan)
composer check     # lint + analyse + test
```

The test suite always runs against the `cms_webhook_test` database (forced in `phpunit.xml`) with the
database credentials from `.env`. As a safeguard, the base test case refuses to run when the configured
database name does not end with `_test`, so the development database can never be wiped. Tests do not
write to the log files.

GitHub Actions runs code style, static analysis and the test suite against PostgreSQL 15 on PHP 8.3
and 8.4 for every push to `main` and every pull request (`.github/workflows/ci.yml`).

## Design decisions

The reasoning behind the data model, synchronous processing, locking, authentication and API design is
in [docs/architecture.md](docs/architecture.md#decisions-and-tradeoffs). In short:

- **Precedence by version only.** The event `timestamp` is stored but never compared.
- **Deletes leave a tombstone** that keeps the deleted version, so older events cannot restore content.
- **Synchronous, atomic batches.** The `200` is sent after the commit; retries are idempotent.
- **Per-content advisory locks**, acquired in a fixed order, serialize concurrent deliveries without
  deadlocks, including for content that does not exist yet.
- **Bearer tokens issued from the CLI** (Laravel Sanctum) for consumers; HTTP Basic from the
  environment for the CMS; neither works on the other surface.
- **Hidden content is reported as `404`**, never `403`, so its existence is not revealed.

One decision changes observable behavior and deserves a closer look:

### A newer version after a delete restores the content

Event precedence is decided exclusively by the version number. A `delete` event leaves a tombstone
that keeps the deleted version, so any event with an **older or equal** version is ignored and can
never restore the content. An event with a **newer** version, however, is applied:

| Arrival order | Event | Result |
|---|---|---|
| 1 | `publish` v3 | Applied: published, version 3 |
| 2 | `delete` v5 | Applied: deleted, payload removed, version 5 |
| 3 | `publish` v4 (delayed delivery) | Ignored: 4 is older than 5 |
| 4 | `publish` v6 | Applied: published again, version 6 |

Event 4 happened in the CMS after the deletion, for example because the content was restored there.
Since the CMS is the source of truth, ignoring it would leave this service permanently out of sync,
with no way to recover other than editing the database by hand. Applying it keeps a single,
predictable precedence rule: the highest version always wins.

A local disable override set by an administrator is not part of the CMS data, so it survives this
cycle: content that was disabled before the deletion comes back still disabled.

## Known limitations

Listed with their reasoning in [docs/architecture.md](docs/architecture.md#known-limitations). The most
relevant: webhook latency depends on the database because processing is synchronous; tombstones are
never purged; only the latest state of each content is kept; `jsonb` does not preserve key order or
integers beyond 64 bits in payloads; token management is CLI-only.
