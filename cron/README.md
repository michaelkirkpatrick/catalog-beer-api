# Cron Jobs

Scripts in this directory run as scheduled tasks on the server, not via web requests. They are deployed to `public_html/cron/` by `deploy.sh` alongside the API code. Each script has a CLI-only guard that exits immediately if accessed via a web request.

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

Add a crontab entry on the server (`crontab -e`):

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
