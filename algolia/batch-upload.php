<?php
// Full re-index of the Algolia `catalog` index: every brewer, its locations
// and beers, then the style vocabulary. Each brewer goes up as one batch
// (brewer + locations + beers, Algolia::batchSave() chunks at 1,000), so a
// run is ~6,700 requests rather than one PUT per record. updateObject is an
// upsert, so the script is safe to re-run.
//
// Usage: php batch-upload.php [staging|production] [limit]

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__) . '/public_html');

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php batch-upload.php [staging|production] [limit]\n";
    echo "  limit: optional max number of brewers to process (for testing)\n";
    exit(1);
}
define('ENVIRONMENT', $env);

// Optional limit for testing
$limit = isset($argv[2]) ? intval($argv[2]) : 0;

// Load Passwords
require_once dirname(__DIR__) . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

// Required Classes
$brewer = new Brewer();
$location = new Location();
$beer = new Beer();
$algolia = new Algolia();

// ----- Brewers, each with its locations and beers -----

// Get a list of all the Brewers and brewerID's
$fetchCount = ($limit > 0) ? $limit : 10000;
$brewerList = $brewer->getBrewers(base64_encode('0'), $fetchCount);

// Counters
$numBrewers = count($brewerList);
$numLocations = 0;
$numBeers = 0;
$counter = 0;

if($limit > 0){
    echo "Limit: $limit brewers\n\n";
}
echo "--- Processing $numBrewers brewers (with their locations and beers)...\n\n";

for($i=0; $i<$numBrewers; $i++){
    $brewerID = $brewerList[$i]['id'];
    $objects = array();

    // Brewer
    $brewer->validate($brewerID, true);
    $objects[] = $brewer->generateBrewerSearchObject();

    // Locations — the validated brewer is passed in so each location doesn't
    // re-validate the parent
    $locations = $location->brewerLocations($brewerID);
    if(!empty($locations)){
        foreach($locations as $row){
            $location->validate($row['id'], true);
            $objects[] = $location->generateLocationSearchObject($brewer);
            $numLocations++;
        }
    }

    // Beers — same brewer pass-through
    $beerInfo = $beer->brewerBeers($brewerID);
    if(!empty($beerInfo['data'])){
        foreach($beerInfo['data'] as $row){
            $beer->validate($row['id'], true);
            $objects[] = $beer->generateBeerSearchObject($brewer);
            $numBeers++;
        }
    }

    // One batch per brewer (chunked at 1,000 inside)
    $algolia->batchSave('catalog', $objects);
    $counter++;
    $percent = round(($counter/$numBrewers) * 100);
    echo "[$percent%] Brewer: $brewer->name (" . (count($objects) - 1) . " children)\n";
}

echo "\n\n--- Done: $numBrewers brewers, $numLocations locations, $numBeers beers. Starting Styles...\n\n";

// ----- Styles -----
//
// Deterministic objectIDs ('style-' + slug), and no per-style loop on the data
// side: generateStyleSearchObjects() builds the whole vocabulary in two
// queries. Not limited by the brewer $limit — the style set is ~250 records
// and always uploads in full.

$style = new Style();
$styleObjects = $style->generateStyleSearchObjects();
$numStyles = count($styleObjects);

echo "--- Processing $numStyles styles...\n\n";
$algolia->batchSave('catalog', array_values($styleObjects));

echo "--- Done with Styles. Script complete.\n";
?>
