# Secrets Rotation Checklist

All production secrets live in `common/passwords.php` (gitignored, deployed via rsync). The file is `chmod 600`, owned by `www-data`, and HTTP access is denied via `common/.htaccess`.

## Rotation cadence

| Secret | Suggested cadence | Trigger-based rotation |
|---|---|---|
| `DB_PASSWORD` | Annually | Any DB user compromise, dev offboarding |
| `POSTMARK_SERVER_TOKEN` | Annually | Suspicious sends, sandbox abuse |
| `USPS_CLIENT_SECRET` | Annually | USPS notifies of compromise |
| `GOOGLE_MAPS_API_KEY` | Annually | Quota anomalies, referer leak |
| `ALGOLIA_WRITE_API_KEY` | Annually | Index tampering, search anomalies |
| `ALGOLIA_SEARCH_API_KEY` | As needed | Frontend leak (it's already public-by-design) |
| `ANTHROPIC_API_KEY` | Annually | Unexpected spend, key checked into VCS |
| `MASTER_API_KEYS` | Every 6 months | Admin offboarding |

Always rotate immediately if:
- The secret was committed to git (even briefly)
- It was pasted into Slack, email, a ticket, a chat with an LLM, or any third-party tool
- A staff member with access leaves
- You see unexpected usage in the provider's dashboard

## Rotation procedure (general shape)

For every secret:

1. Generate the new secret in the provider's dashboard.
2. Edit `common/passwords.php` on your local machine.
3. `./deploy.sh staging` → smoke-test the affected feature on staging.
4. `./deploy.sh production` → verify in production.
5. Revoke the old secret in the provider's dashboard.
6. Update this file's "Last rotated" column below.

## Per-secret instructions

### `DB_PASSWORD` (MySQL `catalogadmin`)
- **Used by:** `classes/Database.class.php` — every API request
- **Provider:** MySQL on each server (staging: 172.236.249.199, production: 172.233.129.106)
- **Rotate:**
  1. SSH into each server: `mysql -u root -p`
  2. `ALTER USER 'catalogadmin'@'localhost' IDENTIFIED BY 'NEW_PASSWORD';`
  3. `FLUSH PRIVILEGES;`
  4. Update `passwords.php` for that environment, deploy, smoke-test (e.g. `curl https://api.catalog.beer/health`).
- **Note:** Staging and production passwords are independent — rotate them separately so you don't lock yourself out of one while testing the other.

### `POSTMARK_SERVER_TOKEN`
- **Used by:** `classes/SendEmail.class.php`, `classes/PostmarkSendEmail.class.php` — verification emails, password resets, weekly digests
- **Provider:** https://account.postmarkapp.com → Servers → (staging or production) → API Tokens
- **Rotate:**
  1. Postmark UI → "Rotate token" on the desired server
  2. Update `passwords.php`, deploy
  3. Trigger a verification email (e.g. create a test user via Newman) to confirm
- **Note:** Staging uses Postmark's sandbox server, production uses a live server. They have separate tokens.

### `USPS_CLIENT_ID` + `USPS_CLIENT_SECRET`
- **Used by:** `classes/USPSAuth.class.php`, `classes/USAddresses.class.php` — address validation on Location create/update
- **Provider:** https://developer.usps.com → My Apps → (your app) → Credentials
- **Rotate:**
  1. Create a new app or generate new credentials
  2. Update `passwords.php` (both client ID and secret), deploy
  3. POST a test Location with an address to verify
- **Note:** USPS uses the same credentials for staging and production; environments differ only in `USPS_API_BASE_URL` (`apis-tem.usps.com` vs `apis.usps.com`).

### `GOOGLE_MAPS_API_KEY`
- **Used by:** `classes/Location.class.php` — geocoding addresses to lat/lng
- **Provider:** https://console.cloud.google.com → APIs & Services → Credentials
- **Rotate:**
  1. Create a new API key, restrict it to the Geocoding + Places APIs
  2. Restrict by server IP (staging + production IPs) if not already
  3. Update `passwords.php`, deploy, POST a Location to verify
  4. Delete the old key

### `ALGOLIA_WRITE_API_KEY`
- **Used by:** `classes/Algolia.class.php`, `algolia/batch-upload.php` — index updates on Brewer/Beer/Location create/update/delete
- **Provider:** https://www.algolia.com/account/api-keys → All API Keys
- **Rotate:**
  1. Create a new API key with `addObject`, `deleteObject`, `editSettings` ACLs scoped to the `catalog` index
  2. Update `passwords.php`, deploy
  3. Edit a Brewer or Beer to verify search index updates
  4. Delete the old key

### `ALGOLIA_SEARCH_API_KEY`
- **Used by:** `classes/Algolia.class.php` plus the public catalog.beer frontend (the SEARCH key is intentionally public)
- **Note:** This key is meant to be safe to expose — it has read-only access. Rotate only if Algolia flags abuse, or when you change the search ACL surface.

### `ALGOLIA_APPLICATION_ID`
- **Note:** Not a secret — it's the public identifier for your Algolia account. No rotation needed unless you migrate accounts.

### `ANTHROPIC_API_KEY`
- **Used by:** `cron/error-digest.php`, `cron/php-error-digest.php` — weekly Monday 7am Pacific error digests
- **Provider:** https://console.anthropic.com → Settings → API Keys
- **Rotate:**
  1. Create a new key
  2. Update `passwords.php`, deploy
  3. Run the digest manually on the server: `php cron/error-digest.php production`
  4. Confirm the digest email arrives with analysis section populated
  5. Delete the old key
- **Note:** Same key serves staging and production today; consider splitting if you want isolated billing/usage.

### `MASTER_API_KEYS`
- **Used by:** `index.php` — these UUIDs identify admin/internal API keys that are excluded from `api_logging`
- **Rotate:**
  1. Generate new UUIDs (any UUID v4 generator)
  2. Add them to `MASTER_API_KEYS` in `passwords.php` alongside the old ones
  3. Insert matching rows into the `users`/`api_keys` table on each server
  4. Update any consumers (your own scripts, internal tooling) to use the new keys
  5. Once consumers are migrated, remove the old UUIDs from `MASTER_API_KEYS` and delete the corresponding `api_keys` rows
  6. Deploy
- **Note:** These don't have a provider dashboard — they're issued by you, for you. Rotation is purely operational.

## Rotation log

Keep a record of when each secret was last rotated.

| Secret | Last rotated | Rotated by | Notes |
|---|---|---|---|
| `DB_PASSWORD` (staging) | — | — | |
| `DB_PASSWORD` (production) | — | — | |
| `POSTMARK_SERVER_TOKEN` (staging) | — | — | |
| `POSTMARK_SERVER_TOKEN` (production) | — | — | |
| `USPS_CLIENT_SECRET` | — | — | |
| `GOOGLE_MAPS_API_KEY` | — | — | |
| `ALGOLIA_WRITE_API_KEY` | — | — | |
| `ANTHROPIC_API_KEY` | — | — | |
| `MASTER_API_KEYS` | — | — | |
