# Secrets Rotation Checklist

All secrets live in `common/passwords.php` (gitignored, **never deployed**). On the server it sits at the vhost root, outside the web root, as `0640 www-data:developers`; the repo-root `common/` mirrors that location so the same `dirname(ROOT)` expression resolves locally and on the server. Rotating a secret means editing the file **on each server** -- a deploy never carries it.

## Server layout

The API loads secrets with `require_once dirname(ROOT) . '/common/passwords.php'` from `public_html/classes/initialize.php`, and the `cron/` and `algolia/` scripts do the equivalent from their own directories (`dirname(__DIR__)`). `deploy.sh` refuses to transfer anything until the file exists at the vhost root, and warns afterwards if a stale copy is still inside `public_html/common/`. On a 26.04 server `add-domain.sh` puts it there (and the real file replaces its generated stub -- Linode repo, `2604 Installation Instructions.md` § 9). On a 24.04 server still on the legacy layout (file inside `public_html/common/`, `0640`; was `0600` before 2026-09-22), move it up once, as root, before the first deploy of this layout:

```bash
V=/var/www/html/<vhost>
sudo mkdir -p $V/common $V/cron $V/algolia
sudo mv $V/public_html/common/passwords.php $V/common/passwords.php
sudo chown www-data:developers $V/common $V/common/passwords.php $V/cron $V/algolia
sudo chmod 775 $V/common $V/cron $V/algolia
sudo chmod 640 $V/common/passwords.php
```

Then re-point the crontab (`Cron-Jobs.md` / `crontab.txt` in the Linode repo): every `public_html/cron/` path becomes `cron/`, and the Algolia scripts move from `public_html/algolia/` to `algolia/`. The first deploy of the new layout removes the now-empty `public_html/common/` and `public_html/cron/` from the server (`--delete`), so do the crontab edit in the same sitting as the deploy or the next scheduled run fails with "No such file".

To edit the file on a server, copy it up and install it as root so owner and mode are set in one step (a plain `scp` onto the `0640 www-data` file fails):

```bash
scp common/passwords.php michael@<server>:~/passwords.php
sudo install -o www-data -g developers -m 640 ~/passwords.php /var/www/html/<vhost>/common/passwords.php && rm ~/passwords.php
```

## Rotation cadence

| Secret | Suggested cadence | Trigger-based rotation |
|---|---|---|
| `DB_PASSWORD` | Annually | Any DB user compromise, dev offboarding |
| `POSTMARK_SERVER_TOKEN` | Annually | Suspicious sends, sandbox abuse |
| `USPS_CLIENT_SECRET` | Annually | USPS notifies of compromise |
| `GOOGLE_ADDRESS_VALIDATION_KEY` | Annually | Quota anomalies, referer leak |
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
2. Update `common/passwords.php` on the **staging** server (§ Server layout above; the file is never deployed) → smoke-test the affected feature on staging.
3. Update it on the **production** server → verify in production.
4. Keep the local copy in step so a fresh server gets the current values.
5. Revoke the old secret in the provider's dashboard.
6. Update this file's "Last rotated" column below.

## Per-secret instructions

### `DB_PASSWORD` (MySQL `catalogadmin`)
- **Used by:** `classes/Database.class.php` — every API request
- **Provider:** MySQL on each server (which box is in `Sites.md` in the Linode repo; staging and production passwords differ)
- **Rotate:**
  1. SSH into each server: `mysql -u root -p`
  2. `ALTER USER 'catalogadmin'@'localhost' IDENTIFIED BY 'NEW_PASSWORD';`
  3. `FLUSH PRIVILEGES;`
  4. Update `passwords.php` on that server, smoke-test (e.g. `curl https://api.catalog.beer/health`).
- **Note:** Staging and production passwords are independent — rotate them separately so you don't lock yourself out of one while testing the other.

### `POSTMARK_SERVER_TOKEN`
- **Used by:** `classes/SendEmail.class.php`, `classes/PostmarkSendEmail.class.php` — verification emails, password resets, weekly digests
- **Provider:** https://account.postmarkapp.com → Servers → (staging or production) → API Tokens
- **Rotate:**
  1. Postmark UI → "Rotate token" on the desired server
  2. Update `passwords.php` on each server
  3. Trigger a verification email (e.g. create a test user via Newman) to confirm
- **Note:** Staging uses Postmark's sandbox server, production uses a live server. They have separate tokens.

### `USPS_CLIENT_ID` + `USPS_CLIENT_SECRET`
- **Used by:** `classes/USPSAuth.class.php`, `classes/USAddresses.class.php` — address validation on Location create/update
- **Provider:** https://developer.usps.com → My Apps → (your app) → Credentials
- **Rotate:**
  1. Create a new app or generate new credentials
  2. Update `passwords.php` on each server (both client ID and secret)
  3. POST a test Location with an address to verify
- **Note:** USPS uses the same credentials for staging and production; environments differ only in `USPS_API_BASE_URL` (`apis-tem.usps.com` vs `apis.usps.com`).

### `GOOGLE_ADDRESS_VALIDATION_KEY`
- **Used by:** `classes/USAddresses.class.php` — address validation (+ lat/lng) on Location create/update. Also currently powers the legacy Maps Geocoding/Places calls in `classes/Location.class.php` (being deprecated).
- **Provider:** https://console.cloud.google.com → APIs & Services → Credentials
- **Rotate:**
  1. Create a new API key, restrict it to the Address Validation API (and Geocoding + Places APIs while the legacy `Location.class.php` geocoding remains)
  2. Restrict by server IP (staging + production IPs) if not already
  3. Update `passwords.php` on each server, POST a Location to verify
  4. Delete the old key
- **Note:** The frontend repo uses a *separate* JavaScript Maps API key — do not reuse this server-side key there.

### `ALGOLIA_WRITE_API_KEY`
- **Used by:** `classes/Algolia.class.php`, `algolia/batch-upload.php` — index updates on Brewer/Beer/Location create/update/delete
- **Provider:** https://www.algolia.com/account/api-keys → All API Keys
- **Rotate:**
  1. Create a new API key with `addObject`, `deleteObject`, `editSettings` ACLs scoped to the `catalog` index
  2. Update `passwords.php` on each server
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
  2. Update `passwords.php` on each server
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
  6. Nothing to deploy -- `index.php` reads the constant from the file at runtime
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
| `GOOGLE_ADDRESS_VALIDATION_KEY` | — | — | |
| `ALGOLIA_WRITE_API_KEY` | — | — | |
| `ANTHROPIC_API_KEY` | — | — | |
| `MASTER_API_KEYS` | — | — | |
