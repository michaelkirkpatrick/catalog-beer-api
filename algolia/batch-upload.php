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

// Required Classes
$brewer = new Brewer();
$location = new Location();
$beer = new Beer();

// Save Array
$listOfLocationIDs = array();
$listOfBeerIDs = array();

function curlRequest($index, $data = null){
	// Create URL
	$url = 'https://' . ALGOLIA_APPLICATION_ID . '.algolia.net/1/indexes/' . $index;

	// Return URL
	$returnURL = '';

	// Initialize Curl
	$curl = curl_init();

	// URL to Test
	curl_setopt($curl, CURLOPT_URL, $url);

	// Initialize headers array
	$headers = array(
		'x-algolia-application-id: ' . ALGOLIA_APPLICATION_ID,
		'x-algolia-api-key: ' . ALGOLIA_WRITE_API_KEY,
		'User-Agent: api.catalog.beer/1.0'
	);

	// Determine if JSON data is provided
	if ($data !== null) {
		// Convert data to JSON if it's an array
		if (is_array($data)) {
			$jsonData = json_encode($data);
		} else {
			// Assume it's already a JSON string
			$jsonData = $data;
		}

		// Set the request method to POST
		curl_setopt($curl, CURLOPT_POST, true);

		// Attach the JSON payload
		curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);

		// Add JSON headers to auth headers
		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Content-Length: ' . strlen($jsonData);
	} else {
		// If no data is provided, make it a GET request
		curl_setopt($curl, CURLOPT_HTTPGET, true);
	}

	// Set all headers
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

	// SSL Verification for HTTPS
	if(preg_match('/^https:\/\//', $url)){
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
	}

	curl_setopt($curl, CURLOPT_HEADER, true);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curl, CURLOPT_TIMEOUT, 30);

	// Send Request, Get Output
	$output = curl_exec($curl);

	// Response HTTP Code
	$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

	if(curl_errno($curl)){
		// cURL Error
		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 16;
		$errorLog->errorMsg = 'cURL Error';
		$errorLog->badData = "URL: $url / cURL Error: " . curl_error($curl);
		$errorLog->filename = 'algolia/batch-upload.php';
		$errorLog->write();
	}

	// Process Output
	$responseBody = '';
	if(gettype($output) == 'string'){
		// Separate headers and body
		$headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		$header = substr($output, 0, $headerSize);
		$responseBody = substr($output, $headerSize);

		// Extract Location header if present
		if(preg_match('/[lL]ocation: (.+)/', $header, $matches)){
			$newLineChars = array("\n", "\r");
			$returnURL = trim(str_replace($newLineChars, '', $matches[1]));
		}

		// Update HTTP code if found in headers
		if(preg_match('/HTTP\/1\.[01] (\d{3})/', $header, $matches)){
			$httpCode = intval($matches[1]);
		}
	}

	// Close curl
	curl_close($curl);

	// Optionally, decode the JSON response
	$decodedResponse = json_decode($responseBody, true);

	// Response Array
	$response = array(
		'httpCode' => $httpCode,
		'url' => $returnURL,
		'response' => $decodedResponse !== null ? $decodedResponse : $responseBody
	);

	return $response;
}

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

	// Clear $brewerInfo
	$brewerInfo = array();

	// Get Brewer Basic Info
	$brewer->validate($brewerID, true);
	$brewerInfo = $brewer->generateBrewerSearchObject();

	// Get Brewer Locations
	$locations = $location->brewerLocations($brewerID);
	if(!empty($locations)){
		// Locations Array
		$locationsArray = array();

		// Loop Through Locations
		for($j=0; $j<count($locations); $j++){
			// For Later Lookup
			$listOfLocationIDs[] = $locations[$j]['id'];

			// For brewers.json
			$locationsArray[$j]['locationID'] = $locations[$j]['id'];
			$locationsArray[$j]['name'] = $locations[$j]['name'];
		}

		// Append to $brewerInfo
		$brewerInfo['locations'] = $locationsArray;
	}

	// Get Beers for Brewer
	$beerInfo = array();
	$beerInfo = $beer->brewerBeers($brewerID);
	if(!empty($beerInfo)){
		// Beer Array
		$beerArray = array();

		// Loop Through Beers
		for($j=0; $j<count($beerInfo['data']); $j++){
			// For Later Lookup
			$listOfBeerIDs[] = $beerInfo['data'][$j]['id'];

			// For brewers.json
			$beerArray[$j]['beerID'] = $beerInfo['data'][$j]['id'];
			$beerArray[$j]['name'] = $beerInfo['data'][$j]['name'];
		}

		// Append to $brewerInfo
		$brewerInfo['beer'] = $beerArray;
	}

	// Send to Algolia
	$json = json_encode($brewerInfo);
	$curlResponse = curlRequest('catalog', $json);
	$counter++;
	$percent = round(($counter/$numBrewers) * 100);
	$output = "[$percent%] ";
	if($curlResponse['httpCode'] == 201){$output .= "Created for brewerID: $brewer->brewerID\n";}
	else{$output .= 'HTTP Code: ' . $curlResponse['httpCode'] . ' / ' . (is_array($curlResponse['response']) ? $curlResponse['response']['message'] : $curlResponse['response']) . "\n";}
	echo $output;
}
echo "\n\n--- Done with Brewers. Starting Locations...\n\n";


// ----- Locations -----

// Counter
$numLocations = count($listOfLocationIDs);
$counter = 0;

echo "--- Processing $numLocations locations...\n\n";

// Loop Through all the Locations
for($i=0; $i<$numLocations; $i++){
	// locationID
	$locationID = $listOfLocationIDs[$i];

	// Get Location Info
	$location->validate($locationID, true);
	$array = $location->generateLocationSearchObject();

	// Send to Algolia
	$json = json_encode($array);
	$curlResponse = curlRequest('catalog', $json);
	$counter++;
	$percent = ($numLocations > 0) ? round(($counter/$numLocations) * 100) : 0;
	$output = "[$percent%] ";
	if($curlResponse['httpCode'] == 201){$output .= "Created for locationID: $location->locationID\n";}
	else{$output .= 'HTTP Code: ' . $curlResponse['httpCode'] . ' / ' . (is_array($curlResponse['response']) ? $curlResponse['response']['message'] : $curlResponse['response']) . "\n";}
	echo $output;
}

echo "\n\n--- Done with Locations. Starting Beers...\n\n";

// ----- Beers -----

// Counter
$numBeers = count($listOfBeerIDs);
$counter = 0;

echo "--- Processing $numBeers beers...\n\n";

// Loop Through all the Beers
for($i=0; $i<$numBeers; $i++){
	// beerID
	$beerID = $listOfBeerIDs[$i];

	// Get Beer Info
	$beer->validate($beerID, true);
	$array = $beer->generateBeerSearchObject();

	// Send to Algolia
	$json = json_encode($array);
	$curlResponse = curlRequest('catalog', $json);
	$counter++;
	$percent = ($numBeers > 0) ? round(($counter/$numBeers) * 100) : 0;
	$output = "[$percent%] ";
	if($curlResponse['httpCode'] == 201){$output .= "Created for beerID: $beer->beerID\n";}
	else{$output .= 'HTTP Code: ' . $curlResponse['httpCode'] . ' / ' . (is_array($curlResponse['response']) ? $curlResponse['response']['message'] : $curlResponse['response']) . "\n";}
	echo $output;
}

echo "\n\n--- Done with Beers. Script complete.\n";
?>