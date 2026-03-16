# Catalog.beer API

This is the API web service that powers [Catalog.beer](https://catalog.beer).

Comments, issues and pull requests welcome.

-Michael

Michael Kirkpatrick  
Founder, Catalog.beer

## Cron Jobs

The following cron jobs need to be set up on the server. Most run under the `michael` user (`crontab -e`), except where noted.

```
# michael's crontab (crontab -e)

# Update API usage counts daily at 2 AM
0 2 * * * php /var/www/html/api.catalog.beer/public_html/cron/update-usage.php production

# Prune old API logs weekly on Sunday at 3 AM
0 3 * * 0 php /var/www/html/api.catalog.beer/public_html/cron/prune-api-logging.php production

# Send weekly app error digest email (Mondays at 7am Pacific)
0 7 * * 1 php /var/www/html/api.catalog.beer/public_html/cron/error-digest.php production
```

```
# root's crontab (sudo crontab -e)
# Runs as root because /var/log/php/error.log is owned by www-data and not readable by michael

# Send weekly PHP error digest email (Mondays at 6am Pacific)
0 6 * * 1 php /var/www/html/api.catalog.beer/public_html/cron/php-error-digest.php production
```

Adjust the path if the deploy directory differs on the new server.

## See Also

* [Catalog.beer - GitHub](https://github.com/michaelkirkpatrick/catalog-beer)
* [Catalog.beer MySQL Schema - GitHub](https://github.com/michaelkirkpatrick/catalog-beer-mysql)
