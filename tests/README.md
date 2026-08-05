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

To set up a new environment, copy the example and fill in your API keys:

```bash
cp tests/env.example.json tests/myenv.env.json
```

The environment file requires two keys:

| Variable | Purpose |
|----------|---------|
| `api_key` | Initial API key for requests (overwritten during test run) |
| `api_key_admin` | Admin/master API key used for admin-only operations |

Both should be set to your master API key for the target environment. These keys are **not hardcoded** in the collection — they must be provided via the environment file. During the test run, `api_key` is swapped between admin and non-admin keys as tests create users and exercise different permission levels.

### Test Pages

The API validates brewer and location URLs by making a live HTTP request. Tests use a static test page hosted on several domains. Each domain must serve a page at `/catalog-beer-test-page.html` that returns HTTP 200:

| URL |
|-----|
| `https://catalog.beer/catalog-beer-test-page.html` |
| `https://mekstudios.com/catalog-beer-test-page.html` |
| `https://swim.team/catalog-beer-test-page.html` |
| `https://plungemasters.com/catalog-beer-test-page.html` |

If any of these pages are missing or return a non-200 response, URL validation tests will fail.

## Running Tests

From the project root:

```bash
# Run against staging
tests/run-tests.sh tests/staging.env.json --bail --verbose

# Run against production
tests/run-tests.sh tests/production.env.json --bail --verbose
```

`run-tests.sh` is a thin wrapper around Newman that runs `cleanup-test-data.js`
afterwards no matter how the run ended, and exits with Newman's status. Calling
`newman run` directly still works — just read the Cleanup section below first,
because a run that stops early leaves data behind.

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

### Cleanup

A **completed** run cleans up after itself. Each `Brewery #N` folder deletes the
brewer it created, and the `brewer` foreign keys cascade, so the beers and
locations underneath go with it. `User - End Requests` deletes the three test
users — and only the users; it does not touch brewers, beers, or locations.

An **interrupted** run does not. The requests are sequential and dependent, and
the deletes come at the end of each folder, so anything that stops the run part
way — `--bail` on a failed assertion, a dropped connection, Ctrl-C — strands
every entity created up to that point. Against production that junk is live: it
shows up in `/brewer`, in search results, and in Algolia.

Re-running a single folder is not a fix. The ids live in environment variables
(`{{brewer_id_14}}` and friends) that are set during the run and held in memory;
Newman does not write them back to the env file. A standalone `--folder` run
sends the placeholder unresolved and deletes nothing.

Use the cleanup script instead. Every entity the collection creates is named
with the `[Postman API Test] ` prefix, so it matches on that exact string and
will not touch anything else:

```bash
# Show what would be removed
node tests/cleanup-test-data.js tests/staging.env.json

# Actually remove it
node tests/cleanup-test-data.js tests/staging.env.json --apply
```

It pages through `/brewer` rather than using `/brewer/search`, so a stranded row
is still found if the FULLTEXT index is out of sync. It also checks the brewers
the collection *borrows* rather than creates — a few tests reassign a test beer
or location to Ballast Point, and those survive the cascade — reading those ids
out of the collection at runtime so it stays correct if the tests change.

That covers brewers, beers and locations. It does **not** cover the three test
users, and cannot: `/users` has no list endpoint, so there is nothing to scan,
and the ids exist only in the running collection's memory. `User - End Requests`
is the last folder in the collection, so an interrupted run strands all three
almost every time. Clearing them means taking the ids from the Newman output —
or from the `users` table — and calling `DELETE /users/{id}` by hand.

One edge to know about on locations: the match is on the name, and
`location.name` is optional. A test location created *without* a name is still
caught when its own brewer is deleted (it goes with the cascade), but one
reassigned to a borrowed brewer would be missed. No current test does that.

Safe to run any time; on a clean database it reports `Nothing to clean up`.

## Collection Structure

The collection contains **720 requests** organized into sequential test groups. The `--folder` flag can target any folder at any nesting level by name.

```
Catalog.beer
├── Users
│   ├── As Admin
│   └── Other Requests
├── Login
│   ├── Get userID using Admin API Key
│   └── Get userID using non-admin API Key
├── Breweries, Beer, Locations
│   ├── Brewery #1
│   ├── Brewery #2
│   ├── Brewery #3
│   ├── Brewery #4
│   ├── Brewery #5
│   ├── Brewery #6
│   ├── Brewery #7 & #8
│   ├── Brewery #9
│   ├── Brewery #10
│   ├── Brewery #11
│   ├── Brewery #12
│   ├── Brewery #13
│   ├── Brewery #14
│   ├── Brewery #15
│   ├── Brewery #16
│   ├── Brewery #17
│   ├── Brewery #18
│   ├── Brewery #19
│   ├── Brewery #20
│   ├── Brewery #21 & #22
│   │   ├── Brewery Requests - Part 1
│   │   ├── Beer #7
│   │   ├── Beer #8
│   │   ├── Beer #9
│   │   ├── Beer #10
│   │   ├── Beer #11
│   │   ├── Beer #12
│   │   ├── Location #13
│   │   ├── Location #14
│   │   ├── Location #15
│   │   └── Location #16
│   ├── Brewery #23
│   ├── Brewery #24
│   ├── Brewery #25
│   ├── Brewery #26
│   ├── Brewery #27
│   ├── Brewery #28
│   │   ├── Brewery #28
│   │   ├── Location #17
│   │   ├── Location #18
│   │   ├── Location #19
│   │   ├── Location #20
│   │   ├── Location #21
│   │   ├── Location #22
│   │   ├── Location #23
│   │   └── Location #24
│   ├── Brewery #29
│   ├── Brewery #30
│   ├── Brewery #31
│   ├── Brewery #32
│   │   ├── Brewery Requests - Part 1
│   │   ├── Beer #13
│   │   ├── Beer #14
│   │   ├── Beer #15
│   │   ├── Beer #16
│   │   ├── Beer #17
│   │   ├── Beer #18
│   │   ├── Location #26
│   │   ├── Location #27
│   │   ├── Location #16
│   │   └── Location #25
│   ├── Brewery #33
│   ├── Brewery #34
│   ├── Brewery #35
│   ├── Brewery #36
│   │   ├── Brewery #36
│   │   ├── Location #29
│   │   ├── Location #30
│   │   ├── Location #31
│   │   ├── Location #32
│   │   ├── Location #33
│   │   ├── Location #34
│   │   ├── Location #35
│   │   └── Location #36
│   ├── Role Based Requests
│   │   ├── Brewery #37
│   │   │   ├── Brewer: Part 1
│   │   │   ├── Beer #19: Part 1
│   │   │   ├── Location: Part 1
│   │   │   ├── Brewer: Part 2
│   │   │   ├── Beer #19: Part 2
│   │   │   └── Brewer: Part 3
│   │   ├── Brewery #38
│   │   ├── Brewery #39
│   │   ├── Brewery #40
│   │   ├── Brewery #41
│   │   ├── Brewery #42
│   │   └── Brewery #43
│   └── General GET Requests
│       ├── Beer: Admin
│       ├── Beer: Non-Admin
│       ├── Brewer: Admin
│       └── Brewer: Non-Admin
├── Invalid API Requests - Technical
└── User - End Requests
```

### Important: Tests are Sequential

Tests run in order and depend on each other. Earlier tests create entities (brewers, beers, locations) and save their IDs as environment variables for later tests to reference. This means:

- You must run the full collection, not individual requests in isolation
- The `--folder` flag runs all requests within a folder, but tests may depend on earlier folders having run first
- If a test fails, subsequent tests that depend on its output may also fail

### Test User Email Conventions

Tests create three users with UUID-based emails to ensure uniqueness:

| User | Role | Email Pattern | Domain |
|------|------|---------------|--------|
| User #1 | Brewery Staff | `michael+{uuid}@catalog.beer` | `catalog.beer` |
| User #2 | Non-Admin | `michael+{uuid}@mekstudios.com` | `mekstudios.com` |
| User #3 | Test User | `michael+{uuid}@mekstudios.com` | `mekstudios.com` |

Each email uses Postman's `{{$randomUUID}}` for the plus-address, making collisions between users or between runs impossible. Different domains distinguish Brewery Staff from Non-Admin roles.

### Top-Level Folders

| Folder | Requests | What it tests |
|--------|----------|---------------|
| `Users` | 36 | User creation, updates, email verification, admin operations |
| `Login` | 9 | Authentication, API key validation |
| `Breweries, Beer, Locations` | 618 | Full CRUD for brewers, beers, locations, and addresses |
| `Usage` | 11 | Request counting and per-key usage reporting |
| `Styles` | 22 | Style vocabulary: list, detail, families, classes, search ranking |
| `Invalid API Requests - Technical` | 12 | Malformed requests, missing headers, bad content types |
| `User - End Requests` | 12 | Password reset, account deletion, cleanup |

### Users (35 requests)

| Subfolder | Requests |
|-----------|----------|
| `As Admin` | 19 |
| `Other Requests` | 15 |

### Login (9 requests)

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

### Staging-Only Tests

Some tests rely on staging-specific API behavior and are automatically skipped when running against production:

| Test | Why | Manual Production Testing |
|------|-----|--------------------------|
| U-41 (Reset Password - User #3) | Requires `password_reset_key`, which is only returned in the staging API response. In production, the key is sent via email only. | Trigger a password reset (U-37), retrieve the key from the database or email, then POST to `/users/password-reset/{key}` with a new password. Expect 204. |

U-37 (Password Reset Request) behaves differently per environment:
- **Staging:** Returns 200 with `{"password_reset_key": "..."}` so U-41 can capture it
- **Production:** Returns 204 No Content (key sent via email only)

### Known Gaps

- No tests for `/location/nearby` (geographic search)
- No tests for Algolia search indexing side-effects
- No tests for external service failures (USPS, Google Maps, Postmark)
- No load/performance testing
- Social media URL fields (`facebook_url`, `twitter_url`, `instagram_url`) were removed from tests as the feature is not currently implemented in the API
