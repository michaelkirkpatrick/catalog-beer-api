# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

REST API backend for [Catalog.beer](https://catalog.beer), a beer database service. PHP-based API with no framework, no dependency manager (no Composer), and no test suite. Runs on Apache with mod_rewrite.

Related repos:
- Frontend: [catalog-beer](https://github.com/michaelkirkpatrick/catalog-beer)
- Database schema: [catalog-beer-mysql](https://github.com/michaelkirkpatrick/catalog-beer-mysql)

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
2. `classes/initialize.php` bootstraps: session start, constants, timezone (`America/Los_Angeles`), SPL autoloader
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
- Database-prefixed fields (e.g., `$dbBrewerID`) used during SQL operations
- Error state: `$error` (bool), `$errorMsg` (string), `$validState` (bool), `$validMsg` (string array)
- Response: `$responseCode` (int), `$responseHeader` (string), `$json` (array)

**Methods:**
- `api($method, $function, $id, $apiKey, ...)` — Main router; switches on HTTP method and `$function`
- `add($method, $id, $apiKey, $data)` — Handles POST, PUT, and PATCH in a single method with `switch($method)` to vary required fields
- `validate($id, $saveToClass)` — Checks if entity exists by UUID; if `$saveToClass` is true, populates class properties
- `delete($id, $userID)` — Soft or hard delete with permission checks
- `generateObject()` — Builds the JSON response object for the entity
- `generateSearchObject()` — Builds the Algolia search index object

### Verification & Permissions
Two-tier verification controls who can edit entities:
- **cbVerified** — Catalog.beer admin verified; only admins can edit
- **brewerVerified** — Brewery staff verified; staff or admins can edit

Staff status determined by: user email domain matching brewer's `domainName`, or explicit entry in `privileges` table. Admin status is a flag on the user account.

### Pagination
Uses base64-encoded cursor pagination. Default count is 500 per page. Cursor is base64 of the offset number.

### Error Logging
All errors are logged to the `error_log` database table via `LogError` class. Each error site has a unique `errorNumber` (integers, currently ranging 1–220+). When adding new error logging, use the next available error number.

### Database Access
`Database.class.php` wraps mysqli. Key methods:
- `query($sql)` — Execute query, returns result
- `escape($string)` — Escape for SQL (uses `real_escape_string`)
- `resultArray($result)` — Fetch all rows as array
- `singleResult($result)` — Fetch single row

SQL is built as concatenated strings (not prepared statements). Database credentials are loaded from `common/passwords.php` (gitignored).

## API Endpoints

Defined in `.htaccess`. All IDs are 36-character UUIDs:
- `/brewer`, `/brewer/{id}`, `/brewer/{id}/beer`, `/brewer/{id}/locations`, `/brewer/count`
- `/beer`, `/beer/{id}`, `/beer/count`
- `/location/{id}`, `/location/nearby`
- `/address/{id}`
- `/users/{id}`, `/users/{id}/api-key`, `/users/verify-email/{id}`, `/users/{id}/reset-password`, `/users/password-reset/{id}`
- `/login`
- `/usage`, `/usage/currentMonth/{id}`

## External Services

- **USPS API** — Address validation (`USAddresses.class.php`); API key via `USPS_API_KEY` constant
- **Google Maps Geocoding API** — Lat/lng coordinates (`Location.class.php`); API key via `GOOGLE_MAPS_API_KEY` constant
- **Algolia** — Search indexing; API keys via `ALGOLIA_APPLICATION_ID`, `ALGOLIA_SEARCH_API_KEY`, `ALGOLIA_WRITE_API_KEY` constants (sourced from environment variables)
- **Postmark** — Transactional email (`SendEmail.class.php`, `PostmarkSendEmail.class.php`); server token via `POSTMARK_SERVER_TOKEN` constant

All secrets are centralized in `common/passwords.php` (gitignored, never committed). This file is loaded by `classes/initialize.php` after the `ENVIRONMENT` constant is set.

## Code Conventions

- Tabs for indentation
- Class files: `ClassName.class.php`
- Entity JSON uses `snake_case` keys (e.g., `brewer_id`, `error_msg`)
- PHP class properties use `camelCase` (e.g., `$brewerID`, `$errorMsg`)
- Database column names accessed via associative arrays using their SQL column names
- UUID generation and validation via `uuid.class.php` (RFC 4122 v4)
