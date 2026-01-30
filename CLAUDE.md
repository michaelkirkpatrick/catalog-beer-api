# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is the REST API backend for [Catalog.beer](https://catalog.beer), a beer database service. It's a PHP-based API that provides endpoints for managing brewers, beers, locations, and user accounts.

## Architecture

### Request Flow
1. All requests route through `index.php` via Apache mod_rewrite rules in `.htaccess`
2. `classes/initialize.php` bootstraps the application (session, environment detection, autoloading)
3. Requests are authenticated via HTTP Basic Auth (API key as username)
4. The endpoint parameter routes to the appropriate class's `api()` method

### Environment Detection
The environment is determined by subdomain:
- `api-staging.*` → staging
- `api.*` → production

### Class Structure
Each major entity has a class in `/classes/` with consistent patterns:
- **Properties**: Entity fields plus error handling (`$error`, `$errorMsg`, `$validState`, `$validMsg`)
- **API Response**: `$responseCode`, `$responseHeader`, `$json`
- **Core Methods**:
  - `api()` - Main API routing method handling HTTP methods
  - `add()` - Create/update records (handles POST, PUT, PATCH)
  - `validate()` - Check if entity exists, optionally populate class properties

### Key Classes
- `Beer.class.php` - Beer management (linked to brewers)
- `Brewer.class.php` - Brewery management with verification states
- `Location.class.php` - Physical brewery locations with geocoding
- `Users.class.php` - User accounts and authentication
- `USAddresses.class.php` - US address handling with USPS validation
- `Database.class.php` - MySQL wrapper with query/escape methods
- `uuid.class.php` - RFC 4122 v4 UUID generation and validation
- `Algolia.class.php` - Search indexing integration
- `Privileges.class.php` - User-to-brewer permission mapping

### Verification System
Two verification levels control edit permissions:
- `cbVerified` - Catalog.beer verified (admin-only edits)
- `brewerVerified` - Brewery staff verified (staff or admin edits)

Verification is checked by matching user email domain to brewer's domain name or via explicit privileges.

## API Endpoints

Defined in `.htaccess`, all endpoints accept UUID-based IDs (36-character format):
- `/brewer`, `/brewer/{id}`, `/brewer/{id}/beer`, `/brewer/{id}/locations`
- `/beer`, `/beer/{id}`
- `/location/{id}`, `/location/nearby`
- `/address/{id}`
- `/users/{id}`, `/users/{id}/api-key`, `/users/verify-email/{id}`
- `/login`
- `/usage`

## Database

- MySQL database named `catalogbeer`
- All IDs are UUIDs (v4 compliant)
- Schema available at: https://github.com/michaelkirkpatrick/catalog-beer-mysql

## External Services

- **USPS API** - Address validation
- **Google Maps Geocoding API** - Location coordinates
- **Algolia** - Search indexing (API keys via environment variables)
