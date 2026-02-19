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
All errors are logged to the `error_log` database table via `LogError` class. Each error site has a unique `errorNumber` (integers, currently ranging 1–238). When adding new error logging, use the next available error number. `LogError::write()` has a static recursion guard (`self::$writing`) to prevent infinite loops when the database is down.

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
- FULLTEXT search: `Beer::search()` and `Brewer::search()` use `MATCH ... AGAINST(? IN NATURAL LANGUAGE MODE)` with MySQL FULLTEXT indexes; `Beer::search()` JOINs with brewer to return full objects in one query

## API Endpoints

Defined in `.htaccess`. All IDs are 36-character UUIDs:
- `/brewer`, `/brewer/{id}`, `/brewer/{id}/beer`, `/brewer/{id}/locations`, `/brewer/count`, `/brewer/search`
- `/beer`, `/beer/{id}`, `/beer/count`, `/beer/search`
- `/location/{id}`, `/location/nearby`
- `/address/{id}`
- `/users/{id}`, `/users/{id}/api-key`, `/users/verify-email/{id}`, `/users/{id}/reset-password`, `/users/password-reset/{id}`
- `/login`
- `/usage`, `/usage/currentMonth/{id}`
- `/health` — Unauthenticated GET-only health check; returns `{"status":"ok"}` (200) or `{"status":"error"}` (503). Verifies Apache + PHP + MySQL. No logging. Used by exit1.dev for uptime monitoring.

## External Services

- **USPS Addresses API v3** — Address validation (`USAddresses.class.php`); OAuth 2.0 via `USPSAuth.class.php` using `USPS_CLIENT_ID`, `USPS_CLIENT_SECRET`, `USPS_API_BASE_URL` constants
- **Google Maps Geocoding API** — Lat/lng coordinates (`Location.class.php`); API key via `GOOGLE_MAPS_API_KEY` constant
- **Algolia** — Search indexing; API keys via `ALGOLIA_APPLICATION_ID`, `ALGOLIA_SEARCH_API_KEY`, `ALGOLIA_WRITE_API_KEY` constants (sourced from environment variables)
- **Postmark** — Transactional email (`SendEmail.class.php`, `PostmarkSendEmail.class.php`); server token via `POSTMARK_SERVER_TOKEN` constant

All secrets are centralized in `common/passwords.php` (gitignored, never committed). This file is loaded by `classes/initialize.php` after the `ENVIRONMENT` constant is set.

## Cron Jobs

The `cron/` directory contains scripts intended to run as scheduled tasks on the server, not via web requests.

- `cron/update-usage.php` — Counts `api_logging` rows per API key per month and upserts into `api_usage`. Run via: `php cron/update-usage.php [staging|production]` (defaults to production). CLI-only; exits immediately if accessed via web.

The `cron/` directory is deployed by `deploy.sh` to `public_html/cron/` on the server. Each script has a CLI-only guard that exits immediately if accessed via a web request.

**Schema dependency:** `update-usage.php` requires a `UNIQUE INDEX` on `api_usage (apiKey, year, month)` for `INSERT ... ON DUPLICATE KEY UPDATE`. The index must be applied before the cron runs:
```sql
ALTER TABLE api_usage ADD UNIQUE INDEX idx_apiKey_year_month (apiKey, year, month);
```

## Code Conventions

- Tabs for indentation
- Class files: `ClassName.class.php`
- Entity JSON uses `snake_case` keys (e.g., `brewer_id`, `error_msg`)
- PHP class properties use `camelCase` (e.g., `$brewerID`, `$errorMsg`)
- Database column names accessed via associative arrays using their SQL column names
- UUID generation via `uuid.class.php` (RFC 4122 v4, `random_bytes`-based, no DB uniqueness check needed)
