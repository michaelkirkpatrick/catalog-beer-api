# Cron Jobs

Scripts in this directory run as scheduled tasks on the server, not via web requests. They are deployed to `public_html/cron/` by `deploy.sh` alongside the API code. Each script has a CLI-only guard that exits immediately if accessed via a web request.

**All of these live in root's crontab (`sudo crontab -e`), which is shared with swim.team.** Two consequences worth knowing before adding anything:

- Running as root is what lets them read `common/passwords.php`, which `deploy.sh` leaves `chmod 600` owned by `www-data`. A job moved to the `michael` crontab would fail to load database credentials.
- The schedule below is only half the picture. swim.team regenerates its sitemap **every hour on the hour**, and the catalog.beer frontend runs `generate-sitemap.php` at `0 4` on Mondays. Read the live crontab before choosing a time; the top of any hour is never free.

## update-usage.php

Counts API requests per key per month from `api_logging` and upserts the totals into `api_usage`. This data powers the `GET /usage/currentMonth/{api_key}` endpoint.

### How it works

1. Determines the target month from `MAX(lastUpdated)` in `api_usage`, or defaults to the current month if no data exists
2. Runs a single `INSERT ... ON DUPLICATE KEY UPDATE` query that counts all `api_logging` rows per API key for that month and upserts into `api_usage`
3. Only processes one month per run — the full month is recounted from scratch each time, so no entries are missed

### Schema dependency

Requires a unique index on `api_usage`:

```sql
ALTER TABLE api_usage ADD UNIQUE INDEX idx_apiKey_year_month (apiKey, year, month);
```

### Deployment

The script is deployed automatically by `deploy.sh` to `public_html/cron/` on the server. No manual copying needed.

The crontab entry on the server must be set up once (see Scheduling below).

### Scheduling

Add a crontab entry on the server (`sudo crontab -e` — see the note at the top of this file):

```
# Update API usage counts daily at 2 AM
0 2 * * * php /var/www/html/api.catalog.beer/public_html/cron/update-usage.php production
```

Daily is sufficient since this is a monthly rollup. At month boundaries, the previous month gets its final accurate count one day into the new month, and the new month's counter starts appearing the following day.

### Manual run

```bash
php cron/update-usage.php production
php cron/update-usage.php staging
```

Defaults to `production` if no argument is given. The script exits immediately if accessed via a web request.

## bill-usage.php

Monthly Stripe billing for API overage (`classes/Billing.class.php` holds the logic; `classes/Stripe.class.php` makes the API calls). For every billing-enabled key that went past its free tier last month, records a `billing_charges` row — $1 per 1,000 requests over the key's `requestLimit`, blocks rounded up, clamped to the key's `monthlySpendCapCents` — then creates a Stripe invoice (auto-charged to the saved card) for each key whose unbilled total has reached the $5 floor. Under the floor, charges roll forward as `status='pending'`; the floor is waived when December is billed so no balance crosses years. Payment outcomes (paid / failed / uncollectible) arrive later via `POST /stripe-webhook`.

Safe to re-run: charge rows use `INSERT IGNORE` against a unique `(apiKey, year, month)` key, each row records its Stripe invoice item so a crashed run never creates a duplicate line, and Stripe calls carry `Idempotency-Key` headers.

### Schema dependency

Requires `billing_charges` plus the `users.stripeCustomerID` and `api_keys.billingEnabled`/`monthlySpendCapCents` columns — apply `migrations/2026-07-29-stripe-billing.sql` from the [catalog-beer-mysql](https://github.com/michaelkirkpatrick/catalog-beer-mysql) repo before the first run. Also requires the `STRIPE_SECRET_KEY` constant in `common/passwords.php`.

### Scheduling

Run once a month, on the 1st, after `update-usage.php` has run at 2 AM (the real-time counter is already accurate; the rollup is a backstop):

```
# Bill last month's API overage via Stripe (1st of the month, 2:40 AM Pacific)
40 2 1 * * php /var/www/html/api.catalog.beer/public_html/cron/bill-usage.php production
```

The `:40` keeps it clear of swim.team's on-the-hour sitemap job and gives `update-usage.php` time to finish.

### Manual run

```bash
php cron/bill-usage.php production
php cron/bill-usage.php staging
```

Defaults to `production`. Prints how many keys had overage recorded, how many were invoiced, and how many rolled forward under the $5 floor. Logs errors 283 (Stripe invoice failures) and 284 (database errors).

## prune-api-logging.php

Deletes `api_logging` rows older than 3 months to keep the table from growing indefinitely. The cutoff is midnight on the 1st of the month, 3 months ago (e.g., running in February deletes everything before November 1).

### Scheduling

Run weekly — there's no harm in running it more often since it's idempotent:

```
# Prune old API logs weekly on Sunday at 3 AM
0 3 * * 0 php /var/www/html/api.catalog.beer/public_html/cron/prune-api-logging.php production
```

### Manual run

```bash
php cron/prune-api-logging.php production
php cron/prune-api-logging.php staging
```

Defaults to `production` if no argument is given.

## error-digest.php

Queries the `error_log` database table for the past 7 days, groups errors by `errorNumber` and `errorMessage`, sends the grouped data to Claude (Haiku) for analysis, and emails a formatted digest via Postmark.

### Scheduling

Added with `sudo crontab -e`, like everything else here:

```
# Send weekly app error digest email (Mondays at 7am Pacific)
0 7 * * 1 php /var/www/html/api.catalog.beer/public_html/cron/error-digest.php production
```

### Manual run

```bash
php cron/error-digest.php production
php cron/error-digest.php staging
```

Defaults to `production` if no argument is given.

## check-urls.php

Link-health monitor for brewer URLs (`classes/UrlCheck.class.php` does the detection). Report-only, with one deliberate exception: it writes the `brewer` URL-status columns (`urlStatus`, `urlCheckedAt`, `urlLastOkAt`, `urlFailCount`, `urlFinal`) and prints a report — it never clears a brewer's `url`, and the only modification it ever makes is the https promotion below.

**https promotion:** when a stored `http://` URL checks `ok` and the site's own redirect landed on `https://` at the same host (`www` aside), the cron updates `url` to that https URL and refreshes the brewer's Algolia record. This is recording the operator's decision, not making one — and same-host means `domainName`, and with it staff permissions, cannot shift. Any broader difference (new host, subdomain) is never applied; an `ok` URL that lands somewhere other than itself keeps that landing URL in `urlFinal` as evidence for the report.

The API write path is the mirror of this: when a brewer's `url` changes via PUT/PATCH, `Brewer::add()` resets all five monitoring columns to baseline (`unverified`, `urlCheckedAt=NULL`) — a changed URL's history describes the old URL, and the NULL `urlCheckedAt` puts it at the front of this cron's queue for a full check the next night.

### How it works

Each run selects the brewers with the oldest `urlCheckedAt` (never-checked first) and classifies each URL through tiers:

1. **DNS** — `NXDOMAIN` → `gone` (the only unambiguous dead signal)
2. **HTTP** (GET, browser User-Agent, 30s timeout, redirects followed, body capped at 300 KB) — `401/403/405/406/418/429/451` → `blocked` (never a failure); `5xx` → `server_error`; `404/410` → `url_wrong` (apex re-tested first); no response/TLS failure → `no_answer`
3. **Off-domain redirect** — registrable-domain comparison with same-entity filtering (www/hyphen/TLD variants and brand-token survival are not flagged) → `moved`, with the destination stored in `urlFinal`
4. **Parked-page heuristics** — parking-service fingerprints or a near-empty body on an HTTP 200 → `parked`

Escalation: only `no_answer`/`gone` increment `urlFailCount` (`ok` resets it). The report surfaces `moved`/`parked` immediately, sustained failures at `urlFailCount >= 3`, and lists "alive but brewery name absent from page text" as ambiguous. Flagged domains get an RDAP registration-date lookup in the report — a recent date means the domain lapsed and was re-registered by someone else. Nothing is ever deleted automatically; a single observation is never trusted (checks run one at a time with a 0.5s gap — do not add concurrency, high concurrency is what produced a 78% false-negative rate in the original scan).

With `--llm`, ambiguous and flagged pages (capped at 25 per run) are sent to the Claude Messages API for an advisory verdict (`brewery_site` / `parked` / `unrelated_business` / `spam` / `unclear`), printed in the report but never written to the database. Uses `ANTHROPIC_API_KEY` from `common/passwords.php`; error numbers 295 (cURL error) and 296 (non-200).

### Schema dependency

Requires the brewer URL-status columns — apply `migrations/2026-07-28-brewer-url-status.sql` from the [catalog-beer-mysql](https://github.com/michaelkirkpatrick/catalog-beer-mysql) repo (schema v2.3.0) before the first run.

### Scheduling

The default limit of 160/run covers the full catalog (~4,800 URLs) roughly every 30 days when run daily:

```
# Check brewer URL health daily at 1:20 AM (off-peak, ahead of everything else)
20 1 * * * php /var/www/html/api.catalog.beer/public_html/cron/check-urls.php production
```

It goes first in the night's order, ahead of `update-usage.php` at 2 AM, because it is the only job here whose runtime is unbounded in practice.

The `:20` is not cosmetic. The server's crontab is shared with swim.team, which regenerates its sitemap **every hour on the hour** (`0 * * * *`) — so `0 <hour>` is never actually a free slot on this box, whatever the local schedule looks like. Check the real crontab before picking a time, not just this file.

**Budget two hours before `snapshot-metrics.php`, not thirty minutes.** A run is far slower than the per-URL average suggests, because the failures are the slow ones: a dead host burns the full 30s timeout, a 404 adds another 15s re-testing the apex, and every check pays the 0.5s pacing gap. A staging run of 5 URLs hit one 27s timeout — at that rate a 160-URL batch takes roughly 20 minutes, before the 15s RDAP lookup each flagged domain adds in the report phase.

Worse, the slow ones cluster. Brewers are selected oldest-checked first with never-checked ahead of those, so early runs are weighted toward URLs nobody has ever verified — exactly where the dead links live. Overrunning into `snapshot-metrics.php` doesn't corrupt anything, but that night's `brewer_url_status` counts end up a mix of yesterday's values and today's, which is the one thing running them in order was meant to avoid.

### Manual run

```bash
php cron/check-urls.php staging 20         # check 20 URLs on staging
php cron/check-urls.php production 50 --llm  # 50 URLs + Claude verdicts on ambiguous ones
```

Defaults to `production` and a limit of 160 if no arguments are given.

## snapshot-metrics.php

Writes one row per catalog-health metric per day into `metrics_daily` (`classes/Metrics.class.php` holds the metric definitions). ~77 metrics a night.

Most of what it records cannot be reconstructed later. `cbVerified`/`brewerVerified` are bit flags with no audit trail, "how many beers have a description" is only ever knowable as of right now, and the `api_logging` rows behind the demand metrics are pruned at 3 months. A day that isn't snapshotted is gone.

### Metric families

| Family | Examples |
|---|---|
| Size & growth | `total_beer`, `created_brewer_30d`, `deleted_location_1d` |
| Freshness | `touched_beer_90d`, `stale_brewer_2yr`, `age_beer_p50_days`, `brewer_stale_catalog_2yr` |
| CB effort | `cb_verified_brewer`, `cb_verified_beer`, `cb_verified_location` |
| Brewer engagement | `brewer_verified_*`, `brewers_engaged`, `privileges_users`, `users_email_verified` |
| API demand | `api_get_30d`, `api_write_30d`, `api_keys_active_30d` |
| Completeness | `brewer_with_location`, `beer_with_ibu`, `location_with_latlng` |
| Classification | `beer_style_id_resolved`, `beer_style_confidence` (by dimension), `beer_beverage_type` (by dimension) |
| URL health | `brewer_url_status` (by dimension) |

Two deliberate choices worth keeping:

- **Raw counts only, never composite scores.** A "catalog health = 0–100" number belongs at display time. Bake the weights into stored history and the day you change your mind about them, the whole series becomes uninterpretable.
- **`beer_with_ibu` counts `ibu > 0`, not `ibu IS NOT NULL`.** The 2020 bulk import filled ~33,000 rows with a `0` sentinel, which is not an IBU. Counting non-null reports 99.7% coverage where the real figure is ~45%. `beer_ibu_zero` and `beer_abv_zero` track the sentinels separately.

Deletions are inferred by differencing against yesterday: `deleted = (yesterday's total + created since) − today's total`. Beer, brewer and location deletes are hard deletes with no tombstone, so this is the only record there is. It undercounts anything created and deleted inside the same day, and is skipped entirely when yesterday's snapshot is missing rather than attributing several days of deletions to one.

### Schema dependency

Requires `metrics_daily` and the `createdAt` columns — apply `migrations/2026-07-28-metrics-daily.sql` and `migrations/2026-07-28-created-at.sql` from the [catalog-beer-mysql](https://github.com/michaelkirkpatrick/catalog-beer-mysql) repo before the first run. The script checks for both and exits with a message naming the missing migration.

### Scheduling

```
# Snapshot catalog health metrics daily at 4:20 AM (three hours after check-urls)
20 4 * * * php /var/www/html/api.catalog.beer/public_html/cron/snapshot-metrics.php production
```

Run it after `check-urls.php` so each night's `brewer_url_status` counts reflect that morning's checks — leaving a three-hour gap, for the reasons under that script's scheduling notes. The `:20` keeps it clear of the frontend's `generate-sitemap.php` at `0 4` on Mondays, which walks the whole catalog through the API while this walks the same tables with aggregates. Re-running on the same day upserts in place, so it is safe to retry by hand after a failure. Missing a day leaves a gap in the series and suppresses that day's `deleted_*` figures; it does not corrupt anything.

### Manual run

```bash
php cron/snapshot-metrics.php production
php cron/snapshot-metrics.php staging
```

Defaults to `production`. Logs error 297 on failure.

## backfill-metrics.php

One-time (idempotent) replay of historical daily snapshots from the `createdAt` columns, so the growth trend lines start in 2017 instead of the day `snapshot-metrics.php` was first installed. Run once after applying the migrations. Roughly 3,200 days and 48,000 rows for the current catalog; takes about a second.

Only the size/growth family can be reconstructed, and only approximately:

- **Totals count records that still exist today.** Anything created and later deleted is invisible, so historical totals run slightly low and every historical day would appear to have zero deletions. `deleted_*` is therefore not written at all — a real zero and an unknowable zero should not look alike in the series.
- **Verification and completeness are current-state only.** Nothing records when `cbVerified` was set or when a description was added, so those metrics necessarily begin at the first live snapshot.
- **Freshness is not replayable either.** `lastModified` holds only the most recent edit, so a row edited twice looks untouched in the earlier window and `touched_*` would come out silently low.

Backfilled days are measured at 23:59:59 local where the live cron measures at whatever time it runs; the seam between the two is a few hours wide on the first live day.

By default it uses `INSERT IGNORE`, so it can never overwrite a real snapshot. Pass `--overwrite` to replace existing rows.

### Manual run

```bash
php cron/backfill-metrics.php production
php cron/backfill-metrics.php staging --overwrite
```

Defaults to `production`. Not scheduled — run it by hand once.

## php-error-digest.php

Reads the server-wide PHP error log (`/var/log/php/error.log` and rotated files), groups errors by normalized message, sends the grouped data to Claude (Haiku) for analysis, and emails a formatted digest via Postmark. Covers all PHP sites on the server.

### Scheduling

Needs root specifically, rather than merely inheriting it: `/var/log/php/error.log` is owned by `www-data` and unreadable by `michael`.

```
# Send weekly PHP error digest email (Mondays at 6am Pacific)
0 6 * * 1 php /var/www/html/api.catalog.beer/public_html/cron/php-error-digest.php production
```

### Manual run

```bash
sudo php cron/php-error-digest.php production
sudo php cron/php-error-digest.php staging
```

Defaults to `production` if no argument is given. Requires `sudo` (or running as root) to read the PHP error log.
