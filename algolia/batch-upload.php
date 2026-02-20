<?php
// CLI only
if(php_sapi_name() !== 'cli'){
	exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

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
require_once ROOT . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
	require_once ROOT . '/classes/' . $class_name . '.class.php';
});

/**
 * Ensure a local algolia table record exists for the entity.
 * Returns the algolia_id (existing or newly created).
 */
function ensureAlgoliaRecord($type, $recordId){
	$algolia = new Algolia();
	$algoliaId = $algolia->getAlgoliaIdByRecord($type, $recordId);
	if($algoliaId === null){
		$algoliaId = $algolia->add($type, $recordId);
	}
	return $algoliaId;
}

// Required Classes
$brewer = new Brewer();
$location = new Location();
$beer = new Beer();
$algolia = new Algolia();

// Save Array
$listOfLocationIDs = array();
$listOfBeerIDs = array();

// ----- Brewers -----

// Get a list of all the Brewers and brewerID's
$fetchCount = ($limit > 0) ? $limit : 10000;
$brewerList = $brewer->getBrewers(base64_encode('0'), $fetchCount);

// Counter
$numBrewers = count($brewerList);
$counter = 0;

if($limit > 0){
	echo "Limit: $limit brewers\n\n";
}
echo "--- Processing $numBrewers brewers...\n\n";

// Loop Through all the Brewers
for($i=0; $i<$numBrewers; $i++){
	// Brewer ID
	$brewerID = $brewerList[$i]['id'];

	// Ensure Algolia record exists
	ensureAlgoliaRecord('brewer', $brewerID);

	// Get Brewer Basic Info
	$brewer->validate($brewerID, true);
	$brewerInfo = $brewer->generateBrewerSearchObject();

	// Get Brewer Locations
	$locations = $location->brewerLocations($brewerID);
	if(!empty($locations)){
		$locationsArray = array();
		for($j=0; $j<count($locations); $j++){
			$listOfLocationIDs[] = $locations[$j]['id'];
			$locationsArray[$j]['locationID'] = $locations[$j]['id'];
			$locationsArray[$j]['name'] = $locations[$j]['name'];
		}
		$brewerInfo['locations'] = $locationsArray;
	}

	// Get Beers for Brewer
	$beerInfo = $beer->brewerBeers($brewerID);
	if(!empty($beerInfo)){
		$beerArray = array();
		for($j=0; $j<count($beerInfo['data']); $j++){
			$listOfBeerIDs[] = $beerInfo['data'][$j]['id'];
			$beerArray[$j]['beerID'] = $beerInfo['data'][$j]['id'];
			$beerArray[$j]['name'] = $beerInfo['data'][$j]['name'];
		}
		$brewerInfo['beer'] = $beerArray;
	}

	// Send to Algolia via PUT (upsert)
	$algolia->saveObject('catalog', $brewerInfo);
	$counter++;
	$percent = round(($counter/$numBrewers) * 100);
	echo "[$percent%] Brewer: $brewer->name\n";
}
echo "\n\n--- Done with Brewers. Starting Locations...\n\n";


// ----- Locations -----

$numLocations = count($listOfLocationIDs);
$counter = 0;

echo "--- Processing $numLocations locations...\n\n";

for($i=0; $i<$numLocations; $i++){
	$locationID = $listOfLocationIDs[$i];

	// Ensure Algolia record exists
	ensureAlgoliaRecord('location', $locationID);

	// Get Location Info
	$location->validate($locationID, true);
	$array = $location->generateLocationSearchObject();

	// Send to Algolia via PUT (upsert)
	$algolia->saveObject('catalog', $array);
	$counter++;
	$percent = ($numLocations > 0) ? round(($counter/$numLocations) * 100) : 0;
	echo "[$percent%] Location: $location->name\n";
}

echo "\n\n--- Done with Locations. Starting Beers...\n\n";

// ----- Beers -----

$numBeers = count($listOfBeerIDs);
$counter = 0;

echo "--- Processing $numBeers beers...\n\n";

for($i=0; $i<$numBeers; $i++){
	$beerID = $listOfBeerIDs[$i];

	// Ensure Algolia record exists
	ensureAlgoliaRecord('beer', $beerID);

	// Get Beer Info
	$beer->validate($beerID, true);
	$array = $beer->generateBeerSearchObject();

	// Send to Algolia via PUT (upsert)
	$algolia->saveObject('catalog', $array);
	$counter++;
	$percent = ($numBeers > 0) ? round(($counter/$numBeers) * 100) : 0;
	echo "[$percent%] Beer: $beer->name\n";
}

echo "\n\n--- Done with Beers. Script complete.\n";
?>