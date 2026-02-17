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

# Run a specific folder
newman run tests/Catalog.beer.postman_collection.json -e tests/staging.env.json --folder "/users"

# Verbose output (show request/response details)
newman run tests/Catalog.beer.postman_collection.json -e tests/staging.env.json --verbose
```

## Collection Structure

The collection contains **595 requests** organized into sequential test groups:

| Folder | Requests | What it tests |
|--------|----------|---------------|
| `/users` | 35 | User creation, updates, email verification, admin operations |
| `/login` | 9 | Authentication, API key validation |
| `Breweries, Beer, Locations` | 527 | Full CRUD for brewers, beers, locations, and addresses |
| `Invalid API Requests - Technical` | 12 | Malformed requests, missing headers, bad content types |
| `/User - End Requests` | 12 | Password reset, account deletion, cleanup |

### Important: Tests are Sequential

Tests run in order and depend on each other. Earlier tests create entities (brewers, beers, locations) and save their IDs as environment variables for later tests to reference. This means:

- You must run the full collection, not individual requests in isolation
- The `--folder` flag works for top-level folders but tests within may still depend on earlier folders
- If a test fails, subsequent tests that depend on its output may also fail

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
