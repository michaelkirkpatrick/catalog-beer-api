# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

REST API backend for [Catalog.beer](https://catalog.beer), a beer database service. PHP-based API with no framework and no dependency manager (no Composer). Runs on Apache with mod_rewrite. Integration tests run via Newman (Postman CLI).

Related repos:
- Frontend: [catalog-beer](https://github.com/michaelkirkpatrick/catalog-beer)
- Database schema (canonical): [catalog-beer-mysql](https://github.com/michaelkirkpatrick/catalog-beer-mysql)

API documentation page (for external consumers) lives in the frontend repo: `../catalog-beer/api-docs.php`

## Development Environment

This is a plain PHP project served by Apache. There are no build steps, linters, or test runners. Development requires:
- Apache with mod_rewrite enabled
- PHP with mysqli extension
- MySQL database named `catalogbeer`

Environment is detected by subdomain in `classes/initialize.php`:
- `api-staging.*` → staging
- `api.*` → production

## Architecture

### Request Flow
1. `.htaccess` rewrites all URLs to `index.php` with query parameters (`endpoint`, `id`, `function`)
2. `classes/initialize.php` bootstraps: constants, timezone (`America/Los_Angeles`), SPL autoloader
3. `index.php` parses JSON body, validates headers (Content-Type, Accept), authenticates via HTTP Basic Auth (API key as username)
4. `switch($endpoint)` routes to the appropriate class, calling its `api()` method
5. Class sets `$responseCode`, `$responseHeader`, `$json`; `index.php` outputs the JSON response
6. Request is logged via `apiLogging` (except for master API keys)

### Class Autoloading
SPL autoloader in `initialize.php` loads `classes/{ClassName}.class.php`. All class files follow this naming convention.

### Entity Class Pattern
Entity classes (`Beer`, `Brewer`, `Location`, `Users`) share a consistent structure:

**Properties:**
- Entity fields (e.g., `$brewerID`, `$name`)
- Error state: `$error` (bool), `$errorMsg` (string), `$validState` (bool), `$validMsg` (string array)
- Response: `$responseCode` (int), `$responseHeader` (string), `$json` (array)
- Cache: `$brewerObj` (cached Brewer for reuse), `$totalCount` (cached count for pagination)

**Methods:**
- `api($method, $function, $id, $apiKey, ...)` — Main router; switches on HTTP method and `$function`
- `add($method, $id, $apiKey, $data)` — Handles POST, PUT, and PATCH in a single method with `switch($method)` to vary required fields
- `validate($id, $saveToClass)` — Checks if entity exists by UUID; if `$saveToClass` is true, populates class properties
- `delete($id, $userID)` — Soft or hard delete with permission checks
- `generateObject()` — Builds the JSON response object for the entity; accepts optional cached `$brewerObj` to avoid re-querying
- `generateSearchObject()` — Builds the Algolia search index object; accepts optional cached `$brewerObj`

**Query optimization patterns used in `add()`:**
- PUT calls `validate($id, true)` to populate class properties, then saves original values (`$originalCBV`, `$originalBV`, etc.) before they're overwritten — avoids redundant `SELECT` queries for brewerID, cbVerified, brewerVerified
- The validated `$brewer` object is cached in `$this->brewerObj` and reused by `generateObject()`/`generateSearchObject()`
- `Privileges::brewerList($userID)` assumes the caller has already validated the userID
- `USAddresses::validate()` JOINs with the `subdivisions` table to get state names in one query

### Verification & Permissions
Two-tier verification controls who can edit entities:
- **cbVerified** — Catalog.beer admin verified; only admins can edit
- **brewerVerified** — Brewery staff verified; staff or admins can edit

Staff status determined by: user email domain matching brewer's `domainName`, or explicit entry in `privileges` table. Admin status is a flag on the user account.

### Pagination
Uses base64-encoded cursor pagination. Default count is 500 per page. Cursor is base64 of the offset number. Count queries are cached in `$this->totalCount` to avoid duplicate `COUNT` calls between validation and `nextCursor()`. `Location::nearbyLatLng()`, `Beer::search()`, and `Brewer::search()` use a `LIMIT count+1` approach instead of a separate count query — if the extra row is returned, there are more results.

### Error Logging
All errors are logged to the `error_log` database table via `LogError` class. Each error site has a unique `errorNumber` (integers, currently ranging 1–287). When adding new error logging, use the next available error number. `LogError::write()` has a static recursion guard (`self::$writing`) to prevent infinite loops when the database is down. CLI-safe: uses null coalescing for `$_SERVER['REQUEST_URI']` and `$_SERVER['REMOTE_ADDR']`.

### Database Access
`Database.class.php` wraps mysqli with prepared statements. Key methods:
- `query(string $sql, array $params = []): ?mysqli_result` — Prepare and execute a query with `?` placeholders; auto-detects param types (`i` for int, `d` for float, `s` for string); returns `mysqli_result` or `null` on error
- `getInsertId(): int` — Returns the last insert ID
- `getConnection(): mysqli` — Returns the underlying mysqli connection
- `close()` — Closes the database connection

All queries use parameterized `?` placeholders. Database credentials are loaded from `common/passwords.php` (gitignored) via constants `DB_HOST`, `DB_USER`, `DB_NAME`, `DB_PASSWORD`.

**Query patterns:**
- Single row: `$result = $db->query("SELECT ... WHERE id=?", [$id]); $row = $result->fetch_assoc();`
- Loop: `$result = $db->query("SELECT ..."); while($row = $result->fetch_assoc()) { ... }`
- INSERT/UPDATE/DELETE: `$db->query("INSERT INTO t (...) VALUES (?, ?)", [$a, $b]);`
- Dynamic PATCH: Build `$setClauses[]` and `$setParams[]` parallel arrays, then `implode(', ', $setClauses)`
- PUT full replacement: Optional fields use `if(!empty()) { $setClauses[] = 'col=?'; } else { $setClauses[] = 'col=NULL'; }` — omitted fields are cleared per REST standards
- Optional INSERT fields: Build `$columns[]` and `$params[]` arrays, add optional fields conditionally
- JOINs: Used where related data is needed together (e.g., `Location::nearbyLatLng()` JOINs location+brewer+US_addresses+subdivisions; `USAddresses::validate()` JOINs with subdivisions)
- FULLTEXT search: `Beer::search()` and `Brewer::search()` rank in tiers — exact name, all query terms in the name as word prefixes (BOOLEAN MODE against a name-only FULLTEXT index), then the blended `NATURAL LANGUAGE MODE` match — so name matches always outrank description/style mentions. Query strings are sanitised by `SearchQuery::terms()` (`SearchQuery.class.php`), which strips FULLTEXT operator characters (a bare `*` is a parser error even in natural-language mode) and removes InnoDB stopwords from the boolean query (a required stopword like `+the*` silently matches nothing). `Beer::search()` JOINs with brewer to return full objects in one query

## Database Schema — keep `catalog-beer-mysql` in sync

The canonical DB schema is a **separate, public repo**: [catalog-beer-mysql](https://github.com/michaelkirkpatrick/catalog-beer-mysql) (`catalog-beer-schema.sql`) — declarative DDL for the whole `catalogbeer` database. The live migration DDL (the style taxonomy system + `beer` column additions) is authored in `style-library/scripts/migration/` (`01_migration.sql`, regenerated `02_seed.sql`, and the `staging-sync-*.sql` upgrades for already-migrated DBs).

**When you add or alter any table or column — in a migration, or directly in this API — update `catalog-beer-schema.sql` in the SAME change** so the canonical schema never lags, and bump its git tag (`v1.x`). It's easy to forget: it lives in a third repo, isn't imported by the app, and nothing breaks if it's wrong — but it is the schema every human and every future Claude session reads as truth.

Touchpoints here that imply a schema change: a new entry in an entity's `$columns[]`/`$setClauses[]` arrays in `add()`, a new `CREATE TABLE`, a new index, or a `SELECT` that reads a column that doesn't exist yet (e.g. the `style_confidence` column added for the Guided Style Field).

## API Endpoints

Defined in `.htaccess`. All IDs are 36-character UUIDs:
- `/activity` — Admin-only activity report (`Activity.class.php`); queries `api_logging` for write summary, top contributors, recent activity, GET traffic
- `/brewer`, `/brewer/{id}`, `/brewer/{id}/beer`, `/brewer/{id}/locations`, `/brewer/count`, `/brewer/search`
- `/beer`, `/beer/{id}`, `/beer/count`, `/beer/search`
- `/billing` (GET status / PATCH spend cap / DELETE disable), `/billing/checkout-session`, `/billing/portal-session` — Stripe usage billing (`Billing.class.php`); exempt from the rate-limit gate and from api_logging/api_usage counting so a capped key can still manage billing
- `/stripe-webhook` — POST-only, no Basic Auth (verified by Stripe webhook signature instead); routed before header/auth checks like `/health`
- `/location/{id}`, `/location/nearby`, `/location/map` (admin-only, all locations with coordinates for map display)
- `/address/{id}`
- `/users/{id}`, `/users/{id}/api-key`, `/users/verify-email/{id}`, `/users/{id}/reset-password`, `/users/password-reset/{id}`
- `/login`
- `/usage`, `/usage/my-usage`
- `/health` — Unauthenticated GET-only health check; returns `{"status":"ok"}` (200) or `{"status":"error"}` (503). Verifies Apache + PHP + MySQL. No logging. Used by exit1.dev for uptime monitoring.

## External Services

- **USPS Addresses API v3** — Address validation (`USAddresses.class.php`); OAuth 2.0 via `USPSAuth.class.php` using `USPS_CLIENT_ID`, `USPS_CLIENT_SECRET`, `USPS_API_BASE_URL` constants
- **Google Address Validation API** — Address verification + lat/lng (`USAddresses.class.php`); API key via `GOOGLE_ADDRESS_VALIDATION_KEY` constant. The same constant currently also powers the legacy Maps Geocoding/Places calls in `Location.class.php` — still live for `/location/nearby` zip/city geocoding, but no longer called from the address flow. Street/unit parsing is `USAddresses::parseValidatedAddress()` (pure static). **The governing rule: USPS owns the street's STRUCTURE, Google owns the NAME's spelling.** `streetSkeleton()` reduces a street to its directionals + type; same skeleton means the sources differ only in spelling and Google wins ("COMRCL CTR BLVD" → "Commercial Center Boulevard", "SUB ZERO" → "Sub-Zero", "MCMURRAY" → "McMurray"), a different skeleton means CASS wins — Google's road data drops/adds directionals in ways that change where mail goes. CASS also wins for road *designations* (route contains a bare number: "West Arizona 92", "Farm to Market Road 423") and for streets Google returns *truncated* (route "India" for India St, marked CONFIRMED with no signal). Whether a unit exists is Google's `subpremise`, but CASS renders it ("Suite 12" in, "BLDG 12" out) and is trusted outright when Google reports no subpremise at all. Display form via AP-style `apAbbreviate()` ("4th St NW", "I-35") — its recognition of directionals/types is deliberately form-agnostic, since it sees both Google's spelled-out text and CASS's abbreviated text. **Offline regression test: `php tests/address-parse.php`** replays real captured responses (`tests/fixtures/google-address-validation.json`) — run it for any change to address parsing, and add a hand-verified fixture for any new shape encountered
- **Algolia** — Search indexing; API keys via `ALGOLIA_APPLICATION_ID`, `ALGOLIA_SEARCH_API_KEY`, `ALGOLIA_WRITE_API_KEY` constants (plain strings, not `getenv()`)
- **Stripe** — Usage billing (`Stripe.class.php` raw-cURL client, `Billing.class.php` logic; no SDK). Keys with a card on file (`api_keys.billingEnabled`) may exceed the free tier at $1 per 1,000 requests (blocks rounded up, clamped to `api_keys.monthlySpendCapCents`, default $50). Metering stays local in `api_usage`; Stripe only stores cards (Checkout setup mode), creates monthly invoices (`cron/bill-usage.php`, $5 invoice floor with roll-forward in `billing_charges`), and reports payment outcomes (`POST /stripe-webhook`). Constants: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` (environment-conditional: staging uses test-mode keys)
- **Postmark** — Transactional email (`SendEmail.class.php`, `PostmarkSendEmail.class.php`); server token via `POSTMARK_SERVER_TOKEN` constant (environment-conditional: staging uses sandbox server)

All secrets are centralized in `common/passwords.php` (gitignored, never committed). This file is loaded by `classes/initialize.php` after the `ENVIRONMENT` constant is set.

## Cron Jobs

The `cron/` directory contains scripts intended to run as scheduled tasks on the server, not via web requests.

**Schedules and operational notes live in the private Linode repo (`Linode/Cron-Jobs.md`), not here.** That file is the one to update when a schedule changes, and the one to read before picking a time — the production crontab is shared with every other site on the server, so a slot that looks free in this repo often isn't.

- `cron/update-usage.php` — Counts `api_logging` rows per API key per month and upserts into `api_usage`. Run via: `php cron/update-usage.php [staging|production]` (defaults to production). CLI-only; exits immediately if accessed via web.
- `cron/bill-usage.php` — Monthly Stripe billing (1st of the month). Records last month's overage per billing-enabled key into `billing_charges`, then invoices keys whose unbilled total has reached the $5 floor (floor waived when billing December). Idempotent: `INSERT IGNORE` charge rows, per-row invoice-item tracking, Stripe `Idempotency-Key` headers. Run via: `php cron/bill-usage.php [staging|production]`.
- `cron/snapshot-metrics.php` — Writes ~77 catalog-health metrics per day into `metrics_daily` (definitions in `classes/Metrics.class.php`). Verification flags, completeness and API demand have no history in the schema, so a day not snapshotted is lost. Stores raw counts only — composite "health scores" are computed at display time so the formula can change without invalidating stored history. Deletions are inferred by differencing against yesterday's snapshot, since entity deletes are hard deletes with no tombstone. See `Linode/Cron-Jobs.md` for the metric families.
- `cron/check-urls.php` — Brewer link-health monitor (`classes/UrlCheck.class.php` classifies; see `Linode/Cron-Jobs.md`). Report-only apart from promoting `http://` to the site's own `https://`. Two rules the API mirrors: **replacing** a brewer's `url` resets the five monitoring columns to baseline, while **clearing** it keeps them and stores the removed address in `brewer.urlLastKnown` — so a record can distinguish "no website" from "domain lapsed, don't go looking". Every `brewer.url` change appends a row to `brewer_url_history` via `Brewer::logURLChange()`; admins can attach a reason with the write-only `url_note` field on POST/PUT/PATCH `/brewer`.
- `cron/backfill-metrics.php` — One-time replay of historical daily size/growth snapshots from the `createdAt` columns. Only that family is reconstructable; verification, completeness and freshness are current-state only and necessarily begin at the first live snapshot. Uses `INSERT IGNORE` so it can never overwrite a real snapshot.

The `cron/` directory is deployed by `deploy.sh` to `public_html/cron/` on the server. Each script has a CLI-only guard that exits immediately if accessed via a web request.

`deploy.sh` excludes `*.md` from deploys, so documentation never reaches a server. The two exceptions are `cron/error-context.md` and `cron/php-error-context.md`, which the digest crons read at runtime as Claude system prompts — they have explicit `--include` entries that must stay **above** the `*.md` exclude in the `EXCLUDES` array, since rsync takes the first matching rule.

## Algolia Batch Upload

`algolia/batch-upload.php` — Uploads all brewers, locations, and beers to the Algolia `catalog` index. Uses `Algolia::saveObject()` (PUT/upsert), safe to re-run. Run via: `php batch-upload.php [staging|production] [limit]` (defaults to production). CLI-only; must be run on the server.

- Requires the `algolia` table in MySQL (columns: `algolia_id`, `beer_id`, `brewer_id`, `location_id`)
- `ensureAlgoliaRecord()` creates local `algolia` table entries for new records before uploading
- Optional `limit` argument restricts number of brewers processed (for testing)

**Schema dependency:** `update-usage.php` requires a `UNIQUE INDEX` on `api_usage (apiKey, year, month)` for `INSERT ... ON DUPLICATE KEY UPDATE`. The index must be applied before the cron runs:
```sql
ALTER TABLE api_usage ADD UNIQUE INDEX idx_apiKey_year_month (apiKey, year, month);
```

## Code Conventions

- 4 spaces for indentation
- Class files: `ClassName.class.php`
- Entity JSON uses `snake_case` keys (e.g., `brewer_id`, `error_msg`)
- PHP class properties use `camelCase` (e.g., `$brewerID`, `$errorMsg`)
- Database column names accessed via associative arrays using their SQL column names
- UUID generation via `uuid.class.php` (RFC 4122 v4, `random_bytes`-based, no DB uniqueness check needed)
