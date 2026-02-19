# Algolia

## batch-upload.php

Performs a full upload of all brewers, locations, and beers to the Algolia search indexes. Run this on the server via SSH:

```bash
php /var/www/html/public_html/algolia/batch-upload.php
```

The script defaults to the staging environment. It loads credentials from `common/passwords.php` and requires the class autoloader, so it must be run from the deployed server (not locally).

## Real-time Sync

As of February 2026, entity create/update/delete operations sync to Algolia in real time via `Algolia::saveObject()` and `Algolia::deleteObject()`. The batch upload script is only needed for the initial index population or a full re-index.
