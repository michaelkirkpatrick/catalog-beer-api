<?php
/*
Push the `catalog` index settings to Algolia.

These previously lived only in the Algolia dashboard, so faceting behavior was
untracked, unreviewable, and free to drift between staging and production.
This script is the source of truth — change settings here, then run it.

    php settings.php [staging|production]

Safe to re-run; a settings PUT is a full replace, not a merge.
*/

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php settings.php [staging|production]\n";
    exit(1);
}
define('ENVIRONMENT', $env);

// Load Passwords
require_once ROOT . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

$settings = array(

    /*
    Search order matters — earlier attributes rank higher. `name` is the record's
    own identity and always wins; the borrowed parent context (subtitle /
    brewer.name) comes next so "deschutes fresh squeezed" matches the beer; prose
    descriptions rank last so a passing mention never outranks a real name.

    unordered() because word position within these fields carries no meaning —
    "Brewing Company Sierra Nevada" shouldn't score below "Sierra Nevada Brewing
    Company". Descriptions stay ordered(default), where earlier words do matter.
    */
    'searchableAttributes' => array(
        'unordered(name)',
        // Style records' alias vocabulary ("NEIPA", "hazy") — ranked with the
        // name because an alias IS the record's identity, just spelled the way
        // people actually type it.
        'unordered(aliases)',
        'unordered(subtitle)',
        'unordered(brewer.name)',
        'unordered(style)',
        'unordered(style_family)',
        'unordered(cities)',
        'unordered(states)',
        // Street and ZIP — only location records carry address.*, so these
        // surface taprooms, not their brewer or its beers. Ranked below
        // brewer.name so a street match never outranks a name match, and
        // above the prose for the same reason descriptions rank last.
        //
        // address2 is the STREET, not address1 — USPS ordering, which the
        // rest of USAddresses follows (address2 is the required field; the
        // "missing the street address" validation fires on it). address1 is
        // the secondary unit ("Ste 401") and stays unsearchable: nobody
        // finds a brewery by its suite number.
        'unordered(address.address2)',
        'unordered(address.zip5)',
        'short_description',
        'description'
    ),

    /*
    Anything the /search page can refine on.

    searchable() on brewer.name and cities so a long facet list can be typed
    into — there are far more of both than will fit in a refinement panel.

    filterOnly() on the numerics: abv, ibu and beer_count drive range inputs,
    and computing facet counts for continuous values is wasted work.
    */
    'attributesForFaceting' => array(
        'type',
        'beverage_type',
        'searchable(brewer.name)',
        'style',
        'style_family',
        'style_class',
        'states',
        'searchable(cities)',
        'countries',
        'cb_verified',
        'brewer_verified',
        'filterOnly(abv)',
        'filterOnly(ibu)',
        'filterOnly(beer_count)'
    ),

    /*
    Facet value lists are silently truncated at this count — no error, no
    flag in the response. The default of 100 was quietly hiding style values
    (~247 distinct and growing). 300 gives headroom; the server-rendered
    /search page bounds each response's payload by requesting only the facets
    it renders.
    */
    'maxValuesPerFacet' => 300,

    /*
    customRanking on type_rank ONLY — a universal attribute every record
    carries (brewer 40, style 30, location 20, beer 10, set in each
    generateSearchObject). Universality is the load-bearing property: Algolia
    sorts records MISSING a customRanking attribute to the bottom, which is
    why per-type attributes (location_count, abv, beer_count) must never
    appear here. If per-type ranking becomes worth having, the tool is a
    replica index with its own customRanking, not a shared rule here.

    customRanking is the LAST criterion — it fires only when every textual
    criterion (typo/words/proximity/attribute/exact) ties. The case it
    decides: a query matching both a brewer and records that carry the
    brewer's name ("Ballast Point" the brewery vs "Ballast Point Victory at
    Sea" the beer). The brand wins the tie; "sculpin" still ranks the beer
    first because that's decided on textual relevance long before this.
    Records without type_rank (until the next full re-index stamps them)
    simply lose ties, which matches the old behavior of having no rule.
    */
    'customRanking' => array(
        'desc(type_rank)'
    ),

    /*
    Explicit retrieval list, replacing '*'. Every hit used to ship its full
    markdown description and every internal field; hits should carry what a
    result row renders and nothing else. _geoloc is retrievable for a future
    "near me"; descriptions stay retrievable because they're snippeted below.
    (Geo search over brewers relies on _geoloc arrays denormalized from
    locations; Algolia ranks against the closest position in the array.)
    */
    'attributesToRetrieve' => array(
        // identity + routing
        'name', 'subtitle', 'type', 'page_url',
        'beerID', 'brewerID', 'locationID', 'styleID',
        // parent context
        'brewer',
        // classification
        'style', 'style_id', 'style_family', 'style_family_slug',
        'style_class', 'style_class_slug', 'beverage_type', 'catch_all',
        // numbers + specs
        'abv', 'ibu', 'srm',
        'abv_min', 'abv_max', 'ibu_min', 'ibu_max', 'srm_min', 'srm_max',
        'beer_count', 'location_count',
        // trust
        'cb_verified', 'brewer_verified',
        // geography + contact
        'states', 'cities', 'countries', 'address', 'country_short_name',
        'url', '_geoloc',
        // prose (snippeted below)
        'description', 'short_description'
    ),

    /*
    Snippets for the prose fields (~30 words, ellipsised). Beer descriptions
    are nearly empty today (~0.2% populated), so result rows treat the snippet
    as a bonus — but styles are well-described and brewers improve with the
    data-quality work, and this setting is what they'll render through.
    */
    'attributesToSnippet' => array(
        'description:30',
        'short_description:30'
    ),
    'snippetEllipsisText' => '…',

    // Typo tolerance off for very short tokens — "IPA" must not match "APA".
    'minWordSizefor1Typo'  => 4,
    'minWordSizefor2Typos' => 8,

    /*
    A ZIP is five digits, so minWordSizefor1Typo=4 would let 97701 match
    97702 — a different town, presented as a hit. There is no such thing as
    a near-miss postal code; either it's the code or it isn't.
    */
    'disableTypoToleranceOnAttributes' => array(
        'address.zip5'
    )
);

// Push to Algolia
$algolia = new Algolia();
echo "--- Pushing `catalog` index settings to {$env}...\n\n";

if($algolia->setSettings('catalog', $settings)){
    echo "Settings updated successfully.\n";
    echo "  searchableAttributes:   " . count($settings['searchableAttributes']) . "\n";
    echo "  attributesForFaceting:  " . count($settings['attributesForFaceting']) . "\n";
    exit(0);
}else{
    echo "FAILED — see the error_log table for details.\n";
    exit(1);
}
?>
