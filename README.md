# Catalog.beer API

This is the API web service that powers [Catalog.beer](https://catalog.beer).

Comments, issues and pull requests welcome.

-Michael

Michael Kirkpatrick  
Founder, Catalog.beer

## Cron Jobs

The following cron jobs need to be set up on the server:

```
# Update API usage counts
0 0 * * * php /var/www/html/api.catalog.beer/public_html/cron/update-usage.php production

# Send daily error digest email (7am Pacific)
0 7 * * * php /var/www/html/api.catalog.beer/public_html/cron/error-digest.php production
```

Adjust the path if the deploy directory differs on the new server.

## See Also

* [Catalog.beer - GitHub](https://github.com/michaelkirkpatrick/catalog-beer)
* [Catalog.beer MySQL Schema - GitHub](https://github.com/michaelkirkpatrick/catalog-beer-mysql)
