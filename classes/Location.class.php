<?php

class Location {

	// Properties
	public $id = '';
	public $brewerID = '';
	public $name = '';
	public $url = '';
	public $countryCode = '';
	public $countryShortName = 'United States of America';
	public $latitude = 0;
	public $longitude = 0;

	// Error Handling
	public $error = false;
	public $errorMsg = '';
	public $validState = array('brewer_id'=>'', 'name'=>'', 'url'=>'', 'country_code'=>'');
	public $validMsg = array('brewer_id'=>'', 'name'=>'', 'url'=>'', 'country_code'=>'');

	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();

	// Google Maps Geocoding API
	// https://cloud.google.com/console/google/maps-apis/overview
	private $gAPIKey = '';

	// Add Functions
	public function add($brewerID, $name, $url, $countryCode){
		// Save to Class
		$this->brewerID = $brewerID;
		$this->name = $name;
		$this->url = $url;
		$this->countryCode = $countryCode;

		// Validate brewerID
		$brewer = new Brewer();
		if($brewer->validate($this->brewerID, false)){
			// Valid BrewerID
			$this->validState['brewer_id'] = 'valid';
		}else{
			// Invalid Brewer
			$this->error = true;
			$this->validState['brewer_id'] = 'invalid';
			$this->validMsg['brewer_id'] = $brewer->errorMsg;
			$this->responseCode = $brewer->responseCode;
		}

		// Validate Name
		$this->validateName();

		// Validate URL
		$this->url = $brewer->validateURL($this->url, 'url');
		if(!$brewer->error){
			// Valid URL
			if(!empty($this->url)){
				$this->validState['url'] = 'valid';
			}
		}else{
			// Invalid URL
			$this->error = true;
			$this->validState['url'] = $brewer->validState['url'];
			$this->validMsg['url'] = $brewer->validMsg['url'];
			$this->responseCode = $brewer->responseCode;
		}

		// Validate Country Code
		$this->validateCC();

		// Generate LocationID
		$uuid = new uuid();
		$this->id = $uuid->generate('location');
		if($uuid->error){
			// locationID Generation Error
			$this->error = true;
			$this->errorMsg = $uuid->errorMsg;
			$this->responseCode = $uuid->responseCode;
		}

		if(!$this->error){
			// Prep for Database
			$db = new Database();
			$dbLocationID = $db->escape($this->id);
			$dbBrewerID = $db->escape($this->brewerID);
			$dbName = $db->escape($this->name);
			$dbURL = $db->escape($this->url);
			$dbCC = $db->escape($this->countryCode);

			// Add to Database
			$db->query("INSERT INTO location (id, brewerID, name, url, countryCode) VALUES ('$dbLocationID', '$dbBrewerID', '$dbName', '$dbURL', '$dbCC')");
			if(!$db->error){
				// Update Brewer lastModified Timestamp
				$brewer->updateModified($this->brewerID);
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}
	}

	public function addLatLong($locationID, $addressString){

		// Request Parameters
		$address = urlencode($addressString);

		// Headers & Options
		$headerArray = array(
			"accept: application/json"
		);

		$optionsArray = array(
			CURLOPT_URL => 'https://maps.googleapis.com/maps/api/geocode/json?address=' . $address . '&key=' . $this->gAPIKey,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_HTTPHEADER => $headerArray
		);

		// Create cURL Request
		$curl = curl_init();
		curl_setopt_array($curl, $optionsArray);
		$response = curl_exec($curl);
		$err = curl_error($curl);
		curl_close($curl);

		if(!empty($err)){
			// cURL Error -- Log It
			$errorLog = new LogError();
			$errorLog->errorNumber = 111;
			$errorLog->errorMsg = 'cURL Error';
			$errorLog->badData = $err;
			$errorLog->filename = 'Location.class.php';
			$errorLog->write();
		}else{
			// Get Latitude and Longitude
			$jsonResponse = json_decode($response);
			if($jsonResponse->status == 'OK'){
				if(count($jsonResponse->results) == 1){
					// Valid Request
					$this->latitude = $jsonResponse->results[0]->geometry->location->lat;
					$this->longitude = $jsonResponse->results[0]->geometry->location->lng;

					// Add to Database
					if($this->validate($locationID, false)){
						// Valid Location, Prep for Database
						$db = new Database();
						$dbLocationID = $db->escape($locationID);
						$dbLatitude = $db->escape($this->latitude);
						$dbLongitude = $db->escape($this->longitude);

						// Update Query
						$db->query("UPDATE location SET latitude='$dbLatitude', longitude='$dbLongitude' WHERE id='$dbLocationID'");
						$db->close();
					}else{
						// Invalid Location ID
						$errorLog = new LogError();
						$errorLog->errorNumber = 114;
						$errorLog->errorMsg = 'Invalid locationID';
						$errorLog->badData = $locationID;
						$errorLog->filename = 'Location.class.php';
						$errorLog->write();
					}
				}else{
					// More than one result, ambiguous
					$errorLog = new LogError();
					$errorLog->errorNumber = 113;
					$errorLog->errorMsg = 'Multiple Google Maps API Results';
					$errorLog->badData = $jsonResponse;
					$errorLog->filename = 'Location.class.php';
					$errorLog->write();
				}
			}else{
				// Google Maps API Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 112;
				$errorLog->errorMsg = 'Google Maps Error';
				$errorLog->badData = 'Status: ' . $jsonResponse->status . ' / Error Message: ' . $jsonResponse->error_message;
				$errorLog->filename = 'Location.class.php';
				$errorLog->write();
			}
		}
	}

	// Validation Functions
	private function validateName(){
		// Must set $this->name
		$this->name = trim($this->name);

		if(!empty($this->name)){
			if(strlen($this->name) <= 255){
				// Valid
				$this->validState['name'] = 'valid';
			}else{
				// Name Too Long
				$this->error = true;
				$this->validState['name'] = 'invalid';
				$this->validMsg['name'] = 'We hate to say it but your location name is too long for our database. Location names are limited to 255 bytes. Any chance you can shorten it?';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 49;
				$errorLog->errorMsg = 'Location name too long (>255 Characters)';
				$errorLog->badData = $this->name;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Name
			$this->error = true;
			$this->validState['name'] = 'invalid';
			$this->validMsg['name'] = 'Please give us the name of the location you\'d like to add.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 50;
			$errorLog->errorMsg = 'Missing location name';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}
	}

	private function validateCC(){
		// ISO 3166-1 Alpha-2 Code
		// https://www.iso.org/iso-3166-country-codes.html

		// Trim
		$this->countryCode = trim($this->countryCode);

		// Validate
		if(!empty($this->countryCode)){
			if($this->countryCode == 'US'){
				// Valid
				$valid = true;
				$this->validState['country_code'] = 'valid';
			}else{
				// Invalid
				$this->error = true;
				$this->validState['country_code'] = 'invalid';
				$this->validMsg['country_code'] = 'Sorry, at this time we are only collecting brewery locations for the United States of America.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 51;
				$errorLog->errorMsg = 'Invalid country code';
				$errorLog->badData = $this->countryCode;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Country Code
			// Invalid
			$this->error = true;
			$this->validState['country_code'] = 'invalid';
			$this->validMsg['country_code'] = 'Which country is this location in? We are missing the country code.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 153;
			$errorLog->errorMsg = 'Missing country code';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}
	}

	public function validate($locationID, $saveToClass){
		// Valid?
		$valid = false;

		// Trim
		$locationID = trim($locationID);

		if(!empty($locationID)){
			// Prep for Database
			$db = new Database();
			$dbLocationID = $db->escape($locationID);

			// Query
			$db->query("SELECT brewerID, name, url, countryCode, latitude, longitude FROM location WHERE id='$dbLocationID'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid Location
					$valid = true;

					// Save to Class?
					if($saveToClass){
						$array = $db->resultArray();
						$this->id = $locationID;
						$this->brewerID = $array['brewerID'];
						$this->name = stripcslashes($array['name']);
						$this->url = $array['url'];
						$this->countryCode = $array['countryCode'];
						$this->latitude = floatval($array['latitude']);
						$this->longitude = floatval($array['longitude']);
					}
				}elseif($db->result->num_rows > 1){
					// Too Many Rows
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					$this->responseCode = 500;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 54;
					$errorLog->errorMsg = 'More than one location with the same ID';
					$errorLog->badData = "locationID: $locationID";
					$errorLog->filename = 'API / Location.class.php';
					$errorLog->write();
				}else{
					// Not Found
					// Location Does Not Exist
					$this->error = true;
					$this->errorMsg = "Sorry, we couldn't find a location with the location_id you provided.";
					$this->responseCode = 404;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 138;
					$errorLog->errorMsg = 'locationID Not Found';
					$errorLog->badData = $locationID;
					$errorLog->filename = 'API / Location.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Missing LocationID
			$this->error = true;
			$this->errorMsg = 'Whoops, we seem to be missing the location_id for the location. Please check your request and try again.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 53;
			$errorLog->errorMsg = 'Missing locationID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}

		// Return
		return $valid;
	}

	// Locations by Brewer
	public function brewerLocations($brewerID){
		// Return Array
		$locationArray = array();

		// Validate BrewerID
		$brewer = new Brewer();
		if($brewer->validate($brewerID, false)){
			// Prep for Database
			$db = new Database();
			$dbBrewerID = $db->escape($brewerID);
			$db->query("SELECT id, name FROM location WHERE brewerID='$dbBrewerID' ORDER BY name");
			if(!$db->error){
				while($array = $db->resultArray()){
					$locationInfo = array('id'=>$array['id'], 'name'=>$array['name']);
					$locationArray[] = $locationInfo;
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Invalid Brewer
			$this->error = true;
			$this->errorMsg = $brewer->errorMsg;
			$this->responseCode = $brewer->responseCode;
		}

		// Return
		return $locationArray;
	}

	// ----- Locations Nearby -----
	// Locations near a specified latitude and longitude
	public function nearbyLatLng($latitude, $longitude, $searchRadius, $metric, $cursor, $count){
		// Return Variable
		$locationArray = array();
		$nextCursor = '';

		// Default Values
		if(empty($cursor)){
			$cursor = base64_encode('0');
		}
		if(empty($count)){
			$count = 100;
		}
		if(empty($searchRadius)){
			$searchRadius = 25;
		}
		if($metric === 'false' || empty($metric)){
			$metric = false;
		}elseif($metric === 'true'){
			$metric = true;
		}else{
			$metric = false;
		}

		// Prep Variables
		$offset = intval(base64_decode($cursor));
		$count = intval($count);

		if(is_int($offset) && $offset >= 0){
			if(is_int($count)){
				// Within Limits?
				$numLocations = $this->countLocations();
				if($offset > $numLocations){
					// Outside Range
					$this->error = true;
					$this->errorMsg = 'Sorry, the cursor value you supplied is outside our data range.';
					$this->responseCode = 400;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 116;
					$errorLog->errorMsg = 'Offset value outside range';
					$errorLog->badData = "Offset: $offset / numLocations: $numLocations";
					$errorLog->filename = 'API / Location.class.php';
					$errorLog->write();
				}

				if($count > 500 || $count < 1){
					// Outside Range
					$this->error = true;
					$this->errorMsg = 'Sorry, the count value you specified is outside our acceptable range. The range we will accept is [1, 500].';
					$this->responseCode = 400;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 117;
					$errorLog->errorMsg = 'Count value outside range';
					$errorLog->badData = $count;
					$errorLog->filename = 'API / Location.class.php';
					$errorLog->write();
				}
			}else{
				// Not an integer offset
				$this->error = true;
				$this->errorMsg = 'Sorry, the count value you supplied is invalid. Please ensure you are sending an integer value.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 118;
				$errorLog->errorMsg = 'Non-integer count value';
				$errorLog->badData = $count;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}
		}else{
			// Not an integer offset
			$this->error = true;
			$this->errorMsg = 'Sorry, the cursor value you supplied is invalid.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 119;
			$errorLog->errorMsg = 'Invalid cursor value';
			$errorLog->badData = $offset;
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}

		if(!$this->error){
			// Convert to Floats
			$latitude = floatval($latitude);
			$longitude = floatval($longitude);
			$searchRadius = intval($searchRadius);

			// Ensure Nonzero
			if($latitude == 0 && $longitude == 0){
				// Middle of the ocean
				$this->error = true;
				$this->errorMsg = "It looks like you're looking for a brewery in the middle of the ocean (latitude = 0, longitude = 0). Sad to say, we aren't able to track shipboard breweries yet. You might want to check the latitude and longitude you provided.";
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 120;
				$errorLog->errorMsg = 'Invalid latitude and longitude';
				$errorLog->badData = "Latitude: $latitude / Longitude: $longitude";
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}

			if($searchRadius == 0){
				// Invalid Search Radius
				$this->error = true;
				$this->errorMsg = "Whoops, the search radius you gave us appears to be zero. You'll want a search radius greater than zero. Please double check your value.'";
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 121;
				$errorLog->errorMsg = 'Invalid search radius (zero)';
				$errorLog->badData = $searchRadius;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}elseif($searchRadius < 0){
				// Invalid Search Radius
				$this->error = true;
				$this->errorMsg = "Whoops, the search radius you gave is negative. Negative distances are weird to compute. Try a positive value for your search radius.'";
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 122;
				$errorLog->errorMsg = 'Invalid search radius (negative)';
				$errorLog->badData = $searchRadius;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}

			// Validate Latitude
			if($latitude < -90 || $latitude > 90){
				// Outside bounds
				$this->error = true;
				$this->errorMsg = "The latitude value you gave is is out of bounds, please check the value you gave us. The accepted range for latitude is [-90, 90].";
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 123;
				$errorLog->errorMsg = 'Invalid latitude';
				$errorLog->badData = $latitude;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}

			// Validate Longitude
			if($longitude < -180 || $longitude > 180){
				// Outside bounds
				$this->error = true;
				$this->errorMsg = "The longitude value you gave is is out of bounds, please check the value you gave us. The accepted range for longitude is [-180, 180].";
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 124;
				$errorLog->errorMsg = 'Invalid longitude';
				$errorLog->badData = $longitude;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}

			// Max of 7 decimals
			$latitude = round($latitude, 7);
			$longitude = round($longitude, 7);

			if(!$this->error){
				// Required Classes
				$db = new Database();
				$brewer  = new Brewer();
				$usaddresses = new USAddresses();

				// Metric or Imperial?
				if($metric){
					// 6371 km -- https://nssdc.gsfc.nasa.gov/planetary/factsheet/earthfact.html
					$radius = 6371;
					$units = 'kilometers';
				}else{
					// 3959 miles
					$radius = 3959;
					$units = 'miles';
				}

				// Query Database
				// Haversine Formula -- https://en.wikipedia.org/wiki/Haversine_formula
				$db->query("SELECT id, brewerID, name, url, countryCode, latitude, longitude, (2 * $radius * ASIN(SQRT(SIN((RADIANS(latitude-$latitude))/2) * SIN((RADIANS(latitude-$latitude))/2) + COS(RADIANS($latitude)) * COS(RADIANS(latitude)) * SIN((RADIANS(longitude-$longitude)/2) * SIN((RADIANS(longitude-$longitude))/2))))) AS distance FROM location HAVING distance < $searchRadius ORDER BY distance LIMIT $offset, $count");
				//$db->query("SELECT id, brewerID, name, url, countryCode, latitude, longitude FROM location ORDER BY id LIMIT $offset, $count");
				if(!$db->error){
					while($array = $db->resultArray()){
						// Get Brewery Info
						$brewer->validate($array['brewerID'], true);

						// Get Address
						$usaddresses->validate($array['id'], true);

						// Distance
						$distance = round(floatval($array['distance']), 1);

						// Build Response Array
						$locationInfo = array('location'=>array('id'=>$array['id'], 'object'=>'location','name'=>$array['name'], 'brewer_id'=>$array['brewerID'], 'url'=>$array['url'], 'country_code'=>$array['countryCode'], 'country_short_name'=>$this->countryShortName, 'latitude'=>floatval($array['latitude']), 'longitude'=>floatval($array['longitude']), 'telephone'=>$usaddresses->telephone, 'address'=>array('address1'=>$usaddresses->address1, 'address2'=>$usaddresses->address2, 'city'=>$usaddresses->city, 'sub_code'=>$usaddresses->sub_code, 'state_short'=>$usaddresses->stateShort, 'state_long'=>$usaddresses->stateLong, 'zip5'=>$usaddresses->zip5, 'zip4'=>$usaddresses->zip4)), 'distance'=>array('distance'=>$distance, 'units'=>$units), 'brewer'=>array('id'=>$brewer->brewerID, 'object'=>'brewer', 'name'=>$brewer->name, 'description'=>$brewer->description, 'short_description'=>$brewer->shortDescription, 'url'=>$brewer->url, 'cb_verified'=>$brewer->cbVerified, 'brewer_verified'=>$brewer->brewerVerified, 'facebook_url'=>$brewer->facebookURL, 'twitter_url'=>$brewer->twitterURL, 'instagram_url'=>$brewer->instagramURL));

						// Add to Array
						$locationArray[] = $locationInfo;
					}

					// Next Cursor
					$db->query("SELECT id, (2 * $radius * ASIN(SQRT(SIN((RADIANS(latitude-$latitude))/2) * SIN((RADIANS(latitude-$latitude))/2) + COS(RADIANS($latitude)) * COS(RADIANS(latitude)) * SIN((RADIANS(longitude-$longitude)/2) * SIN((RADIANS(longitude-$longitude))/2))))) AS distance FROM location HAVING distance < $searchRadius ORDER BY distance LIMIT $offset, 10000");
					$numResults = $db->result->num_rows;
					$nextCursor = $this->nextCursor($cursor, $count, $numResults);
					$db->close();
				}else{
					// Query Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
			}
		}

		// Return
		return array('locationArray'=>$locationArray, 'nextCursor'=>$nextCursor);
	}

	// Next Cursor
	private function nextCursor($cursor, $count, $numResults){
		// Next Cursor
		$offset = base64_decode($cursor);
		$nextCursor = $offset + $count;

		if($nextCursor <= $numResults){
			// Return Next Page
			return base64_encode($nextCursor);
		}else{
			return '';
		}
	}

	// Number of Locations
	public function countLocations(){
		// Return
		$count = 0;

		// Query Database
		$db = new Database();
		$db->query("SELECT COUNT('id') AS numLocations FROM location");
		if(!$db->error){
			$array = $db->resultArray();
			return intval($array['numLocations']);
		}else{
			// Query Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = 400;
		}
		$db->close();

		return $count;
	}

	public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
		/*---
		GET https://api.catalog.beer/location/{location_id}
		GET https://api.catalog.beer/location/nearby

		POST https://api.catalog.beer/location
		POST https://api.catalog.beer/location/{location_id}
		---*/
		// Connect to Class
		$usAddresses = new USAddresses();

		switch($method){
			case 'POST':
				if(!empty($id)){
					if($this->validate($id, true)){
						// POST https://api.catalog.beer/location/{location_id}
						// Add Address for Location

						// Handle Empty Fields
						if(empty($data->address1)){$data->address1 = '';}
						if(empty($data->address2)){$data->address2 = '';}
						if(empty($data->city)){$data->city = '';}
						if(empty($data->sub_code)){$data->sub_code = '';}
						if(empty($data->zip5)){$data->zip5 = '';}
						if(empty($data->zip4)){$data->zip4 = '';}
						if(empty($data->telephone)){$data->telephone = '';}

						$usAddresses->add($this->id, $data->address1, $data->address2, $data->city, $data->sub_code, $data->zip5, $data->zip4, $data->telephone);
						if(!$usAddresses->error){
							// Successfully Added
							$this->responseCode = 201;

							// Response Header
							$responseHeaderString = 'Location: https://';
							if(ENVIRONMENT == 'staging'){
								$responseHeaderString .= 'staging.';
							}
							$this->responseHeader = $responseHeaderString . 'catalog.beer/location/' . $this->id;

							// Validate Location to get latitude and longitude
							$this->validate($this->id, true);

							// JSON Response
							$this->json['id'] = $this->id;
							$this->json['object'] = 'location';
							$this->json['name'] = $this->name;
							$this->json['brewer_id'] = $this->brewerID;
							$this->json['url'] = $this->url;
							$this->json['country_code'] = $this->countryCode;
							$this->json['country_short_name'] = $this->countryShortName;
							$this->json['latitude'] = $this->latitude;
							$this->json['longitude'] = $this->longitude;

							$this->json['telephone'] = $usAddresses->telephone;
							$this->json['address']['address1'] = $usAddresses->address1;
							$this->json['address']['address2'] = $usAddresses->address2;
							$this->json['address']['city'] = $usAddresses->city;
							$this->json['address']['sub_code'] = $usAddresses->sub_code;
							$this->json['address']['state_short'] = $usAddresses->stateShort;
							$this->json['address']['state_long'] = $usAddresses->stateLong;
							$this->json['address']['zip5'] = $usAddresses->zip5;
							$this->json['address']['zip4'] = $usAddresses->zip4;
						}else{
							// Error Adding Address
							$this->responseCode = $usAddresses->responseCode;
							$this->json['error'] = true;
							$this->json['error_msg'] = $usAddresses->errorMsg;
							$this->json['valid_state'] = $usAddresses->validState;
							$this->json['valid_msg'] = $usAddresses->validMsg;
						}
					}else{
						// Invalid Location
						$this->json['error'] = true;
						$this->json['error_msg'] = $this->errorMsg;
					}
				}else{
					// POST https://api.catalog.beer/location
					// Add Location

					// Handle Empty Fields
					if(empty($data->brewer_id)){$data->brewer_id = '';}
					if(empty($data->name)){$data->name = '';}
					if(empty($data->url)){$data->url = '';}
					if(empty($data->country_code)){$data->country_code = '';}

					$this->add($data->brewer_id, $data->name, $data->url, $data->country_code);
					if(!$this->error){
						// Successfully Added
						$this->responseCode = 201;

						// Response Header
						$responseHeaderString = 'Location: https://';
						if(ENVIRONMENT == 'staging'){
							$responseHeaderString .= 'staging.';
						}
						$this->responseHeader = $responseHeaderString . 'catalog.beer/location/' . $this->id;

						// JSON Response
						$this->json['id'] = $this->id;
						$this->json['object'] = 'location';
						$this->json['name'] = $this->name;
						$this->json['brewer_id'] = $this->brewerID;
						$this->json['url'] = $this->url;
						$this->json['country_code'] = $this->countryCode;
						$this->json['country_short_name'] = $this->countryShortName;
						$this->json['latitude'] = $this->latitude;
						$this->json['longitude'] = $this->longitude;
					}else{
						// Error Adding Location
						$this->json['error'] = true;
						$this->json['error_msg'] = $this->errorMsg;
						$this->json['valid_state'] = $this->validState;
						$this->json['valid_msg'] = $this->validMsg;
					}
				}
				break;
			case 'GET':
				if(!empty($id) && empty($function)){
					// GET https://api.catalog.beer/location/{location_id}
					// Validate ID
					if($this->validate($id, true)){
						// Valid Location
						$this->json['id'] = $this->id;
						$this->json['object'] = 'location';
						$this->json['name'] = $this->name;
						$this->json['brewer_id'] = $this->brewerID;
						$this->json['url'] = $this->url;
						$this->json['country_code'] = $this->countryCode;
						$this->json['country_short_name'] = $this->countryShortName;
						$this->json['latitude'] = $this->latitude;
						$this->json['longitude'] = $this->longitude;

						// Check for Address
						if($usAddresses->validate($this->id, true)){
							$this->json['telephone'] = $usAddresses->telephone;
							$this->json['address']['address1'] = $usAddresses->address1;
							$this->json['address']['address2'] = $usAddresses->address2;
							$this->json['address']['city'] = $usAddresses->city;
							$this->json['address']['sub_code'] = $usAddresses->sub_code;
							$this->json['address']['state_short'] = $usAddresses->stateShort;
							$this->json['address']['state_long'] = $usAddresses->stateLong;
							$this->json['address']['zip5'] = $usAddresses->zip5;
							$this->json['address']['zip4'] = $usAddresses->zip4;
						}
					}else{
						// Invalid Location
						$this->json['error'] = true;
						$this->json['error_msg'] = $this->errorMsg;
					}
				}elseif($function == 'nearby'){
					// GET https://api.catalog.beer/location/nearby
					$nearbyLatLngReturn = $this->nearbyLatLng($data->latitude, $data->longitude, $data->searchRadius, $data->metric, $cursor, $count);
					if(!$this->error){
						// Start JSON
						$this->json['object'] = 'list';
						$this->json['url'] = '/location/nearby';

						// Next Cursor
						if(!empty($nearbyLatLngReturn['nextCursor'])){
							$this->json['has_more'] = true;
							$this->json['next_cursor'] = $nearbyLatLngReturn['nextCursor'];
						}else{
							$this->json['has_more'] = false;
						}

						// Append Data
						$this->json['data'] = $nearbyLatLngReturn['locationArray'];
					}else{
						$this->json['error'] = true;
						$this->json['error_msg'] = $this->errorMsg;
					}

				}else{
					// Invalid Function
					$this->responseCode = 404;
					$this->json['error'] = true;
					$this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 152;
					$errorLog->errorMsg = 'Invalid function (/location)';
					$errorLog->badData = $function;
					$errorLog->filename = 'API / Location.class.php';
					$errorLog->write();
				}
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->json['error'] = true;
				$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
				$this->responseCode = 405;
				$this->responseHeader = 'Allow: GET, POST';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 74;
				$errorLog->errorMsg = 'Invalid Method (/location)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
		}
	}
}
