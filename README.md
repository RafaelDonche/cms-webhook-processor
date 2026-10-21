# CMS Webhook Processor

A Laravel service that receives content events from an external CMS through a webhook, keeps a
synchronized representation of that content in PostgreSQL, and exposes it through a secure REST API
with role-based visibility and local administrative overrides.

## Tech stack

- PHP 8.3+ and Laravel 13
- PostgreSQL 15+
- Laravel Sanctum for consumer API tokens
- PHPUnit, Laravel Pint and Larastan

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

2. Install dependencies, create the `.env` file, generate the application key and run the migrations:

   ```bash
   composer setup
   ```

   `composer setup` copies `.env.example` to `.env` when it does not exist yet. Fill in the database
   credentials (`DB_USERNAME`, `DB_PASSWORD`) and the webhook credentials (`CMS_WEBHOOK_USERNAME`,
   `CMS_WEBHOOK_PASSWORD`) in `.env` and run `php artisan migrate` again if the first run failed
   because of missing credentials.

## Running

```bash
composer dev
```

The API is served at `http://localhost:8000`.

## Testing and quality

```bash
composer test      # PHPUnit suite
composer lint      # Code style check (Pint)
composer analyse   # Static analysis (Larastan)
composer check     # All of the above
```

The test suite always runs against the `cms_webhook_test` database (configured in `phpunit.xml`).
As a safeguard, the base test case refuses to run when the configured database name does not end
with `_test`, so the development database can never be wiped by `RefreshDatabase`.

## Design decisions

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
