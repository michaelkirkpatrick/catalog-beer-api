# Algolia

All records live in a single `catalog` index, mixing brewers, beers, locations, and styles. `type` distinguishes them.

## settings.php

Pushes the index settings (`searchableAttributes`, `attributesForFaceting`, typo tolerance) to Algolia. **This file is the source of truth** — settings used to live only in the dashboard, where they were untracked and free to drift between staging and production.

```bash
php /var/www/html/api.catalog.beer/public_html/algolia/settings.php [staging|production]
```

A settings PUT is a full replace, not a merge, so edit the array in the script rather than the dashboard. Safe to re-run.

Deliberately **no `customRanking`**: this is one index holding several record types, and every candidate tie-breaker exists on only one of them. Algolia sorts records missing a customRanking attribute to the bottom, so ranking on (say) `location_count` would push every beer below every brewer. Per-type ranking needs a replica index, not a shared rule.

## synonyms.php

Generates the index's synonym set from `style_alias` and `parent_alias` and replaces the whole set on Algolia (`replaceExistingSynonyms`). Like settings.php, **this script is the source of truth** — never hand-edit synonyms in the dashboard.

```bash
php /var/www/html/api.catalog.beer/public_html/algolia/synonyms.php [staging|production]
```

Why it exists: Algolia ANDs query tokens and "ipa" is not a token anywhere in "American-Style India Pale Ale" — typo tolerance can't bridge an abbreviation, so without synonyms the most common beer search on the internet returns nothing. The alias tables are already a curated vocabulary of how people actually spell styles; this re-uses that curation. Re-run whenever the alias tables change (a style-library update — the same event that warrants re-uploading style records). Class aliases ("ale", "lager") are deliberately not pushed; they'd add noise to queries that already match half the index.

## batch-upload.php

Performs a full upload of all brewers, locations, beers, and styles to the Algolia `catalog` index. Uses PUT (upsert) via `Algolia::saveObject()`, so it's safe to re-run without creating duplicates. Run this on the server via SSH:

```bash
php /var/www/html/api.catalog.beer/public_html/algolia/batch-upload.php [staging|production] [limit]
```

- Defaults to `production` if no environment is specified
- Optional `limit` restricts the number of brewers processed (useful for testing)
- Loads credentials from `common/passwords.php` and requires the class autoloader, so it must be run from the deployed server (not locally)

The script processes brewers before it has collected locations, which is safe: `generateBrewerSearchObject()` queries the `location` table directly rather than relying on the script's loop order.

## Record shape

Fields marked ° are denormalized from another table and depend on the cascades below to stay accurate.

**Brewer** — `brewerID`, `name`, `description`, `short_description`, `url`, `type`, `page_url`, `subtitle`°, `location_count`°, `beer_count`°, `_geoloc`°, `states`°, `cities`°, `countries`°

**Beer** — `beerID`, `name`, `style`, `style_id`, `style_family`, `style_family_slug`, `style_class`, `style_class_slug`, `beverage_type`, `description`, `abv`, `ibu`, `srm`°, `cb_verified`, `brewer_verified`, `type`, `page_url`, `subtitle`°, `brewer.brewerID`°, `brewer.name`°, `states`°, `cities`°, `countries`°

**Location** — `locationID`, `name`, `url`, `country_short_name`, `_geoloc`, `address.*`, `states`, `cities`, `countries`, `type`, `page_url`, `subtitle`°, `brewer.brewerID`°, `brewer.name`°

**Style** — `styleID` (slug), `name`, `aliases[]`, `beverage_type`, `style_family` (+ slug), `style_class` (+ slug), `catch_all`, `abv_min/max`, `ibu_min/max`, `srm_min/max`, `beer_count`, `description`, `type`, `subtitle` (family name), `page_url`

Notes:

- `style_family` / `style_class` hold resolved **display names** ("India Pale Ale", "Ale") because a slug is never safe to title-case — `ucwords('ipa')` gives "Ipa". The slugs ride along separately for stable URL state.
- Brewer `_geoloc` is an **array** of every location's coordinates. Algolia ranks against the closest position, which is the multi-taproom case.
- Brewer `subtitle` prefers the primary location's "City, ST" and falls back to `short_description`; it's omitted when there's neither.
- **Geography is one facet vocabulary across three types.** Brewers derive `states`/`cities`/`countries` from their locations; beers borrow their brewer's copy; locations mirror their own address under the same keys. The values must match exactly or the facet splits — short state codes ("OR", from `sub_code`) and `countryCode` ("US", not the long name).
- **Only locations carry `address.*`**, so the searchable street (`address.address2`) and ZIP (`address.zip5`) return taproom hits — never the parent brewer or its beers. Note the numbering: **`address2` is the street line and `address1` is the secondary unit** ("Ste 401"), per USPS ordering — `address2` is the required one. `address1` is deliberately left unsearchable; nobody looks up a brewery by suite number. City and state are searchable through the shared `cities`/`states` facet copies instead, which all three types carry. `address.zip5` is in `disableTypoToleranceOnAttributes`: a five-digit token would otherwise fall under `minWordSizefor1Typo`, and 97701 matching 97702 is a different town, not a near miss.
- Beer `abv` and `ibu` are **omitted when unknown**, never written as 0 — numeric range filters match a literal 0, so an unknown ABV indexed as 0 would sit inside every "under 5%" refinement. Records missing the attribute are excluded from numeric filters, which is correct for unknown. (Consequence: an IBU filter only sees beers with a listed IBU; the frontend says so.)
- Beer `srm` is the representative integer collapsed from the style's `srm_min..srm_max` (same rule as the enriched list endpoint) — it drives the swatch, and style records carry the raw range for their gradient.
- **Style objectIDs are deterministic** (`style-<slug>`), not routed through the `algolia` table: slugs are stable, public, and curated, so the random-ID indirection the user-writable types need buys nothing here. Styles have no API write path and therefore no real-time sync — `batch-upload.php` is the only writer; re-run it (and `synonyms.php`) after a style-library update.

## Real-time Sync

As of February 2026, entity create/update/delete operations sync to Algolia in real time via `Algolia::saveObject()` and `Algolia::deleteObject()`. The batch upload script is only needed for the initial index population or a full re-index.

Because Algolia has no joins, some values are denormalized in both directions, and each direction needs a cascade:

- **Brewer rename → children.** Beers and locations carry the brewer name twice (`brewer.name` and `subtitle`). `Brewer::cascadeNameToChildren()` patches them in a single `batchPartialUpdate()` call, guarded on the name actually having changed so ordinary edits stay free. This matters more than it looks: a stale copy silently splits one brewer into two facet buckets.
- **Location or address write → parent brewer → sibling beers.** Brewer records borrow all their geography from locations, so `Brewer::refreshSearchObject($brewerID, true)` runs on location create/update/delete and on address writes. The `true` is the geography cascade: beers borrow the brewer's `states`/`cities`/`countries`, so the same write re-patches every beer of that brewer in one batch (`cascadeGeographyToBeers()`), always sending all three keys — an empty array must propagate so closing a brewer's last Oregon taproom removes its beers from the Oregon facet.
- **Beer create/delete → parent brewer.** `beer_count` lives on the brewer record, so beer create and delete call `Brewer::refreshSearchObject()` *without* the geography cascade — geography didn't change, and patching N sibling beers on every beer write would make adding one beer cost a batch proportional to the brewer's catalog.

`Algolia::batchPartialUpdate()` uses `partialUpdateObjectNoCreate` — these are patches to records that should already exist, and the plain action would resurrect a deleted record as a stub. Note that partial updates merge at the **top level of attributes only**: a nested attribute is replaced wholesale, so a caller patching `brewer` must send `brewerID` *and* `name` or the untouched key is dropped.

## Deploy order

1. Run `settings.php` — faceting doesn't work until `attributesForFaceting` is set, and the frontend `/search` page depends on it.
2. Run `synonyms.php` — same source-of-truth discipline; "ipa" queries return nothing until it lands.
3. Deploy the API — new search-object fields and the geography/beer_count cascades.
4. Deploy the frontend — `search.php`, the `/search` rewrite, the nav form.
5. Run `batch-upload.php` — existing records predate the new fields, and only a full re-index backfills them (including the style records). Facets will look empty or partial until this finishes.

Steps 4 and 5 can swap — the page treats every new field as optional and degrades cleanly against an un-backfilled index. 1–3 must precede 5.

`page_url` for locations now points at `/location/{id}` instead of the parent brewer. **The frontend location pages must exist before step 5**, or search results will 404. The write key used by `synonyms.php` needs the synonyms ACL (`editSettings`-class); if the push 403s, check the key's ACLs in the dashboard.

## Brewer delete

Deleting a brewer removes its own Algolia record **and** its children's: MySQL cascades the delete to beers, locations, and their `algolia`-table rows, so `Brewer::delete()` captures every child's `algolia_id` *before* the SQL delete (`childAlgoliaIds()`) and removes them afterward in one `Algolia::batchDelete()` call. Neither `batchDelete()` nor `deleteObject()` cleans up the local `algolia` table — the FK cascade already handled it, for single deletes as well as the brewer cascade.

(Historical note: before Jul 2026 the children's records were left orphaned in the index — invisible in the old 8-hit modal, but a full `/search` results page renders orphans as hits that 404. If any predate the fix, a full `batch-upload.php` run does not remove them; delete them from the dashboard or ignore — they 404 harmlessly and are few.)
