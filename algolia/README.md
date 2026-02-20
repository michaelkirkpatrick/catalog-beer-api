# Algolia

## batch-upload.php

Performs a full upload of all brewers, locations, and beers to the Algolia `catalog` index. Uses PUT (upsert) via `Algolia::saveObject()`, so it's safe to re-run without creating duplicates. Run this on the server via SSH:

```bash
php /var/www/html/api.catalog.beer/public_html/algolia/batch-upload.php [staging|production] [limit]
```

- Defaults to `production` if no environment is specified
- Optional `limit` restricts the number of brewers processed (useful for testing)
- Loads credentials from `common/passwords.php` and requires the class autoloader, so it must be run from the deployed server (not locally)

## Real-time Sync

As of February 2026, entity create/update/delete operations sync to Algolia in real time via `Algolia::saveObject()` and `Algolia::deleteObject()`. The batch upload script is only needed for the initial index population or a full re-index.
