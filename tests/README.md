# Catalog Beer API Tests

API integration tests for the Catalog Beer REST API, originally written in Postman and run via [Newman](https://github.com/postmanlabs/newman) (Postman's CLI runner).

## Setup

### Prerequisites

- [Node.js](https://nodejs.org/) (installed via `brew install node`)
- Newman: `npm install -g newman`

### Environment Files

Tests require an environment file with your API key and target server:

| File | Purpose | Tracked in git? |
|------|---------|-----------------|
| `env.example.json` | Template with placeholder values | Yes |
| `staging.env.json` | Staging server (`api-staging.catalog.beer`) | No (gitignored) |
| `production.env.json` | Production server (`api.catalog.beer`) | No (gitignored) |

To set up a new environment, copy the example and fill in your API key:

```bash
cp tests/env.example.json tests/myenv.env.json
```

## Running Tests

From the project root:

```bash
# Run against staging
newman run tests/Catalog.beer.postman_collection.json -e tests/staging.env.json

# Run against production
newman run tests/Catalog.beer.postman_collection.json -e tests/production.env.json
```

### Useful Options

```bash
# Stop on first failure
newman run tests/Catalog.beer.postman_collection.json -e tests/staging.env.json --bail

# Run a specific folder (by name)
newman run tests/Catalog.beer.postman_collection.json -e tests/staging.env.json --folder "Role Based Requests"

# Run a specific folder and stop on first failure
newman run tests/Catalog.beer.postman_collection.json -e tests/staging.env.json --folder "Role Based Requests" --bail

# Verbose output (show request/response details)
newman run tests/Catalog.beer.postman_collection.json -e tests/staging.env.json --verbose
```

## Collection Structure

The collection contains **595 requests** organized into sequential test groups. The `--folder` flag can target any folder at any nesting level by name.

### Important: Tests are Sequential

Tests run in order and depend on each other. Earlier tests create entities (brewers, beers, locations) and save their IDs as environment variables for later tests to reference. This means:

- You must run the full collection, not individual requests in isolation
- The `--folder` flag runs all requests within a folder, but tests may depend on earlier folders having run first
- If a test fails, subsequent tests that depend on its output may also fail

### Top-Level Folders

| Folder | Requests | What it tests |
|--------|----------|---------------|
| `/users` | 35 | User creation, updates, email verification, admin operations |
| `/login` | 9 | Authentication, API key validation |
| `Breweries, Beer, Locations` | 527 | Full CRUD for brewers, beers, locations, and addresses |
| `Invalid API Requests - Technical` | 12 | Malformed requests, missing headers, bad content types |
| `/User - End Requests` | 12 | Password reset, account deletion, cleanup |

### /users (35 requests)

| Subfolder | Requests |
|-----------|----------|
| `As Admin` | 19 |
| `Other Requests` | 15 |

### /login (9 requests)

| Subfolder | Requests |
|-----------|----------|
| `Get userID using Admin API Key` | 7 |
| `Get userID using non-admin API Key` | 2 |

### Breweries, Beer, Locations (527 requests)

Most subfolders are named `Brewery #N` and contain a full lifecycle for that brewery: create brewer, add beers, add locations (with addresses), then delete.

| Subfolder | Requests | Notes |
|-----------|----------|-------|
| `Brewery #1` | 3 | |
| `Brewery #2` | 2 | |
| `Brewery #3` | 2 | |
| `Brewery #4` | 4 | Includes URL and duplicate URL test |
| `Brewery #5` | 3 | Brewery Staff claims brewery |
| `Brewery #6` | 2 | |
| `Brewery #7 & #8` | 95 | Admin CRUD: beers, locations, addresses, validation errors |
| `Brewery #9` | 2 | |
| `Brewery #10` | 2 | |
| `Brewery #11` | 2 | |
| `Brewery #12` | 2 | |
| `Brewery #13` | 12 | Admin PUT: beers, locations, addresses |
| `Brewery #14` | 39 | Admin PUT/PATCH: full update coverage |
| `Brewery #15` | 5 | Non-Admin delete attempt |
| `Brewery #16` – `#20` | 2 each | Non-Admin POST with varying fields |
| `Brewery #21 & #22` | 93 | Non-Admin CRUD: beers, locations, addresses |
| `Brewery #23` – `#27` | 2 each | Non-Admin PUT with varying fields |
| `Brewery #28` | 38 | Non-Admin PUT/PATCH: full update coverage |
| `Brewery #29` | 2 | Brewery Staff POST |
| `Brewery #30` | 3 | Brewery Staff delete by non-admin (403) and staff (204) |
| `Brewery #31` – `#32` | 2, 36 | Brewery Staff CRUD: beers, locations, addresses |
| `Brewery #33` – `#34` | 2 each | Brewery Staff PUT |
| `Brewery #35` | 3 | Brewery Staff URL change |
| `Brewery #36` | 37 | Brewery Staff PUT/PATCH: full update coverage |
| `Role Based Requests` | 96 | Permission enforcement for cb_verified/brewer_verified |
| `General GET Requests` | 18 | Pagination, count, and listing endpoints |

#### Role Based Requests (96 requests)

Tests permission enforcement across Admin, Non-Admin, and Brewery Staff roles for verified/unverified entities.

| Subfolder | Requests | What it tests |
|-----------|----------|---------------|
| `Brewery #37` | 37 | cb_verified 403s, staff claims brewery, brewer_verified transitions |
| `Brewery #38` | 14 | cb_verified → staff claims → brewer_verified (PUT), admin deletes |
| `Brewery #39` | 16 | brewer_verified 403s for non-admin, admin upgrades to cb_verified |
| `Brewery #40` | 9 | brewer_verified → admin upgrades, staff deletes cb_verified |
| `Brewery #41` | 7 | Staff claims non-admin brewery (PUT), becomes brewer_verified |
| `Brewery #42` | 7 | Staff claims non-admin brewery (PATCH), becomes brewer_verified |
| `Brewery #43` | 6 | Admin-created brewery, staff deletes non-admin entities |

#### General GET Requests (18 requests)

| Subfolder | Requests |
|-----------|----------|
| `Beer: Admin` | 5 |
| `Beer: Non-Admin` | 5 |
| `Brewer: Admin` | 4 |
| `Brewer: Non-Admin` | 4 |

## What These Tests Cover

These are **API integration tests** (not unit tests). They make real HTTP requests against a running server and validate:

- HTTP status codes (200, 201, 400, 401, 403, 404, 405, 409)
- Response body structure (required fields, correct types)
- Response values match what was submitted
- Permission enforcement (admin vs. non-admin, brewer staff vs. public)
- Validation rules (required fields, format constraints)
- CRUD lifecycle (create, read, update via PUT and PATCH, delete)

### Known Gaps

- No tests for `/location/nearby` (geographic search)
- No tests for Algolia search indexing side-effects
- No tests for external service failures (USPS, Google Maps, Postmark)
- No load/performance testing
- Social media URL fields (`facebook_url`, `twitter_url`, `instagram_url`) were removed from tests as the feature is not currently implemented in the API
