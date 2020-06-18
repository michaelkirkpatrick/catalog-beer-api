<?php

class Location {

	// Properties
	public $locationID = '';
	public $brewerID = '';
	public $name = '';
	public $url = '';			// Optional
	public $countryCode = '';	
	public $countryShortName = 'United States of America';
	public $latitude = 0;		// Optional
	public $longitude = 0;		// Optional
	public $cbVerified = false;
	public $brewerVerified = false;
	public $lastModified = 0;

	// Error Handling
	public $error = false;
	public $errorMsg = null;
	public $validState = array('brewer_id'=>null, 'name'=>null, 'url'=>null, 'country_code'=>null);
	public $validMsg = array('brewer_id'=>null, 'name'=>null, 'url'=>null, 'country_code'=>null);

	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();

	// Google Maps Geocoding API
	// https://cloud.google.com/console/google/maps-apis/overview
	private $gAPIKey = '';

	// Add Functions
	public function add($brewerID, $name, $url, $countryCode, $userID, $method, $locationID, $patchFields){
			
		// Required Classes
		$brewer = new Brewer();
		$db = new Database();
		$privileges = new Privileges();
		$users = new Users();
		$uuid = new uuid();
		
		// ----- locationID -----
		$newLocation = false;
		switch($method){
			case 'POST':
				// Generate a new location_id
				$newLocation = true;
				$this->locationID = $uuid->generate('location');
				if($uuid->error){
					// UUID Generation Error
					$this->error = true;
					$this->errorMsg = $uuid->errorMsg;
					$this->responseCode = $uuid->responseCode;
				}
				break;
			case 'PUT':
				if($this->validate($locationID, false)){
					// Valid Location - Update Existing Entry
					$this->locationID = $locationID;
				}else{
					// Location doesn't exist, they'd like to add it
					// Reset Errors from $this->validate()
					$this->error = false;
					$this->errorMsg = null;
					$this->responseCode = 200;
					
					// Validate UUID
					if($uuid->validate($locationID)){
						// Save submitted UUID as locationID
						$newLocation = true;
						$this->locationID = $locationID;
					}else{
						// Invalid UUID Submission
						$this->error = true;
						$this->errorMsg = $uuid->errorMsg;
						$this->responseCode = $uuid->responseCode;
					}
				}
				break;
			case 'PATCH':
				if($this->validate($locationID, true)){
					// Valid Location - Update Existing Entry (Reference #1)
					$this->locationID = $locationID;
					if(!in_array('brewerID', $patchFields)){
						// Not updating brewer. Retain current brewerID
						$brewerID = $this->brewerID;
					}else{
						// Check to ensure it's a new brewer_id
						if($this->brewerID == $brewerID){
							// Same brewer_id, not changing. Remove from $patchFields
							unset($patchFields['brewerID']);
						}
					}
				}
				break;
			default:
				// Invalid Method
				$this->error = true;
				$this->errorMsg = 'Invalid Method.';
				$this->responseCode = 405;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 184;
				$errorLog->errorMsg = 'Invalid Method';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
		}
		
		// ----- Validate brewerID -----
		
		if($brewer->validate($brewerID, true)){
			// Valid BrewerID
			$this->brewerID = $brewerID;
			$this->validState['brewer_id'] = 'valid';
			
			// Which brewer is this beer currently associated with?
			if($method == 'PUT' || $method == 'PATCH'){
				// Get the brewerID currenlty associated with this beer
				$dbLocationID = $db->escape($this->locationID);
				$db->query("SELECT brewerID FROM location WHERE id='$dbLocationID'");
				if($db->result->num_rows > 0){
					// Brewer currently associated with this beer
					$permissionsBrewerID = $db->singleResult('brewerID');
				}else{
					// No brewer currently associated with this beer (e.g. PUT)
					$permissionsBrewerID = $this->brewerID;
				}
			}else{
				// Non PUT/PATCH Request, use $this->brewerID
				$permissionsBrewerID = $this->brewerID;
			}
		}else{
			// Invalid Brewer
			$this->error = true;
			$this->validState['brewer_id'] = 'invalid';
			$this->validMsg['brewer_id'] = $brewer->errorMsg;
			
			// Correct 404 (Not Found) to 400 (Bad Request) for Brewer Not Found
			if($brewer->responseCode === 404){
				$this->responseCode = 400;
			}else{
				$this->responseCode = $brewer->responseCode;
			}
		}
		
		// ----- Permissions & Validation Badge -----
		
		if(!$this->error){
			if($users->validate($userID, true)){
				// Get User's Email Domain Name
				$userEmailDomain = $users->emailDomainName($users->email);

				// Get User Privileges
				$userBrewerPrivileges = $privileges->brewerList($userID);

				// ----- Permissions Check -----
				if($method == 'PUT' || $method == 'PATCH'){
					if(!$newLocation){
						// Attempting to PUT or PATCH existing Location
						// Get cb_verified and brewer_verified flags
						$dbLocationID = $db->escape($this->locationID);
						$db->query("SELECT cbVerified, brewerVerified FROM location WHERE id='$dbLocationID'");
						$resultArray = $db->resultArray();
						$cbVerified = $resultArray['cbVerified'];
						$brewerVerified = $resultArray['brewerVerified'];

						if($cbVerified){
							if($userEmailDomain == $brewer->domainName || in_array($permissionsBrewerID, $userBrewerPrivileges)){
								// Allow PUT/PATCH. User is brewery staff.
							}else{
								if(!$users->admin){
									// Deny
									$this->error = true;
									$this->errorMsg = 'Sorry, because this location is cb_verified, we limit editing capabilities to Catalog.beer Admins. If you would like to see an update made to this location, please [contact us](https://catalog.beer/contact)';
									$this->responseCode = 403;

									// Log Error
									$errorLog = new LogError();
									$errorLog->errorNumber = 185;
									$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /location, cb_verified';
									$errorLog->badData = "User: $userID / Location: $this->locationID";
									$errorLog->filename = 'API / Location.class.php';
									$errorLog->write();
								}
							}
						}else{
							if($brewerVerified){
								if($userEmailDomain == $brewer->domainName || in_array($permissionsBrewerID, $userBrewerPrivileges)){
									// Allow PUT/PATCH. User is brewery staff.
								}else{
									if(!$users->admin){
										// Deny
										$this->error = true;
										$this->errorMsg = 'Sorry, because this location is brewer_verified, we limit editing capabilities to brewery staff. If you would like to see an update made to this location, please [contact us](https://catalog.beer/contact)';
										$this->responseCode = 403;

										// Log Error
										$errorLog = new LogError();
										$errorLog->errorNumber = 187;
										$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /location, brewer_verified';
										$errorLog->badData = "User: $userID / Location: $this->locationID";
										$errorLog->filename = 'API / Location.class.php';
										$errorLog->write();
									}
								}
							}
						}
					}
				}

				// ----- Verification Badges -----
				$this->cbVerified = false;
				$dbCBV = b'0';
				$this->brewerVerified = false;
				$dbBV = b'0';

				// Get User Info
				if($users->admin){
					// Catalog.beer Verified
					$this->cbVerified = true;
					$dbCBV = b'1';
				}else{
					// Not Catalog.beer Verified
					if(!empty($brewer->domainName)){
						if($userEmailDomain == $brewer->domainName || in_array($this->brewerID, $userBrewerPrivileges)){
							// User has email associated with the brewery, give breweryValidated flag.
							$this->brewerVerified = true;
							$dbBV = b'1';

							if(!in_array($this->brewerID, $userBrewerPrivileges)){
								// Give user privileges for this brewer
								$privileges->add($userID, $this->brewerID, true);
							}
						}
					}
				}
			}else{
				// User Validation Error
				$this->error = true;
				$this->errorMsg = $users->errorMsg;
				$this->responseCode = $users->responseCode;
			}
		}
		
		// ----- Validate Fields -----
		// Don't waste processing resources if there's been an error in the steps above.
		if(!$this->error){
			// Default SQL
			$sql = '';
			
			if($method == 'POST' || $method == 'PUT'){
				// Validate Name
				$this->name = $name;
				$this->validateName();

				// Validate URL
				$this->url = $brewer->validateURL($url, 'url', 'location');
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
				$this->countryCode = $countryCode;
				$this->validateCC();
				
				if(!$this->error){
					// Last Modified
					$this->lastModified = time();
					
					// Prep for Database
					$dbLocationID = $db->escape($this->locationID);
					$dbBrewerID = $db->escape($this->brewerID);
					$dbName = $db->escape($this->name);
					$dbURL = $db->escape($this->url);
					$dbCC = $db->escape($this->countryCode);
					$dbLastModified = $db->escape($this->lastModified);
					
					// SQL Query
					if($newLocation){
						// Add Location (POST/PUT)
						$urlSQL1 = '';
						$urlSQL2 = '';
						if(!empty($this->url)){
							$urlSQL1 = ", url";
							$urlSQL2 = ", '$dbURL'";
						}
						$sql = "INSERT INTO location (id, brewerID, name, countryCode, cbVerified, brewerVerified, lastModified" . $urlSQL1 . ") VALUES ('$dbLocationID', '$dbBrewerID', '$dbName', '$dbCC', $dbCBV, $dbBV, $dbLastModified" . $urlSQL2 . ")";
					}else{
						// Update Location (PUT)
						$urlSQL = '';
						if(!empty($this->url)){
							$urlSQL = ", url='$dbURL'";
						}
						$sql = "UPDATE location SET brewerID='$dbBrewerID', name='$dbName', countryCode='$dbCC', cbVerified=$dbCBV, brewerVerified=$dbBV, lastModified=$dbLastModified" . $urlSQL . " WHERE id='$dbLocationID'";
					}					
				}
			}
			elseif($method == 'PATCH'){
				/*-- 
				Validate the field if it's different than what is currently stored.
				Check against the $this->{var} which we have from performing a $this->validate($locationID, true) in the locationID flow above for PATCH (Reference #1).
				--*/
				
				// SQL Update
				$sqlArray = array();
				
				// brewerID
				if(in_array('brewerID', $patchFields)){
					// Validated brewerID above, and checked to ensure new, no need to re-validate
					$dbBrewerID = $db->escape($this->brewerID);
					$sqlArray[] = "brewerID='$dbBrewerID'";
				}
				
				// Name
				if(in_array('name', $patchFields)){
					if($this->name != $name){
						// Validate Name
						$this->name = $name;
						$this->validateName();
						if(!$this->error){
							$this->validState['name'] = 'valid';
							$dbName = $db->escape($this->name);
							$sqlArray[] = "name='$dbName'";
						}
					}
				}
				
				// URL
				if(in_array('url', $patchFields)){
					if($this->url != $url){
						// Validate URL
						$this->url = $brewer->validateURL($url, 'url', 'location');
						if(!$brewer->error){
							// Valid URL
							if(!empty($this->url)){
								$this->validState['url'] = 'valid';
								$dbURL = $db->escape($this->url);
								$sqlArray[] = "url='$dbURL'";
							}
						}else{
							// Invalid URL
							$this->error = true;
							$this->validState['url'] = $brewer->validState['url'];
							$this->validMsg['url'] = $brewer->validMsg['url'];
							$this->responseCode = $brewer->responseCode;
						}
					}
				}
				
				// Country Code
				if(in_array('countryCode', $patchFields)){
					if($this->countryCode != $countryCode){
						// Validate Country Code
						$this->countryCode = $countryCode;
						$this->validateCC();
						if(!$this->error){
							$dbCC = $db->escape($this->countryCode);
							$sqlArray[] = "countryCode='$dbCC'";
						}
					}
				}
				
				if(!$this->error && !empty($sqlArray)){
					// Last Modified
					$this->lastModified = time();

					// Prep for Database
					$dbLocationID = $db->escape($this->locationID);
					$dbLastModified = $db->escape($this->lastModified);
					
					// Construct SQL Statement
					$sql = "UPDATE location SET lastModified=$dbLastModified, cbVerified=$dbCBV, brewerVerified=$dbBV";
					
					$totalUpdates = count($sqlArray);
					if($totalUpdates > 0){$sql .= ", ";}
					$lastUpdate = $totalUpdates - 1;
					for($i=0;$i<$totalUpdates; $i++){
						if($i == $lastUpdate){
							$sql .= $sqlArray[$i];
						}else{
							$sql .= $sqlArray[$i] . ", ";
						}
					}
					$sql .= " WHERE id='$dbLocationID'";
				}
			}
			
			if(!$this->error && !empty($sql)){
				// Update Database
				$db->query($sql);
				if(!$db->error){
					if($newLocation){
						// Successfully Added
						$this->responseCode = 201;

						// Response Header
						$responseHeaderString = 'Location: https://';
						if(ENVIRONMENT == 'staging'){
							$responseHeaderString .= 'staging.';
						}
						$this->responseHeader = $responseHeaderString . 'catalog.beer/location/' . $this->locationID;
					}else{
						// Success
						$this->responseCode = 200;
					}
				}else{
					// Query Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
				$db->close();
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
			$db->query("SELECT brewerID, name, url, countryCode, latitude, longitude, cbVerified, brewerVerified, lastModified FROM location WHERE id='$dbLocationID'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid Location
					$valid = true;

					// Save to Class?
					if($saveToClass){
						$array = $db->resultArray();
						$this->locationID = $locationID;
						$this->brewerID = $array['brewerID'];
						$this->name = stripcslashes($array['name']);
						$this->url = $array['url'];
						$this->countryCode = $array['countryCode'];
						$this->latitude = floatval($array['latitude']);
						$this->longitude = floatval($array['longitude']);
						if($array['cbVerified']){
							$this->cbVerified = true;
						}if($array['brewerVerified']){
							$this->brewerVerified = true;
						}
						$this->lastModified = intval($array['lastModified']);
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

	public function delete($locationID, $userID){
		if($this->validate($locationID, true)){
			// Get User Information
			$users = new Users();
			$users->validate($userID, true);
			
			// Get Brewer Privileges
			$privileges = new Privileges();
			$brewerPrivilegesList = $privileges->brewerList($userID);
			
			if($users->admin || in_array($this->brewerID, $brewerPrivilegesList)){
				// Delete Location
				$db = new Database();
				$dbLocationID = $db->escape($locationID);
				$db->query("DELETE FROM location WHERE id='$dbLocationID'");
				if($db->error){
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
				$db->close();
			}else{
				// Not Allowed to Delete
				$this->error = true;
				$this->errorMsg = 'Sorry, you do not have permission to delete this location.';
				$this->responseCode = 403;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 200;
				$errorLog->errorMsg = 'Forbidden: DELETE, /location';
				$errorLog->badData = "User: $userID / brewerID: $this->brewerID / locationID: $locationID";
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}
		}
	}
	
	public function updateLastModified($locationID){
		// Validate Location
		if($this->validate($locationID, true)){
			// Update Last Modified Timestamp
			$db = new Database();
			$this->lastModified = time();
			$dbLastModified = $db->escape($this->lastModified);
			$dbLocationID = $db->escape($locationID);
			$db->query("UPDATE location SET lastModified=$dbLastModified WHERE id='$dbLocationID'");
			if($db->error){
				// Database Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
		}
	}
	
	public function googleMapsAPI($locationID, $addressString, $googleAPI){
		// Request Parameters
		$address = urlencode($addressString);

		// Headers & Options
		$headerArray = array(
			"accept: application/json"
		);
		
		// URL
		switch($googleAPI){
			case 'geocode':
				$url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . $address . '&key=' . $this->gAPIKey;
				$arrayName = 'results';
				break;
			case 'findplacefromtext':
				$url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?input=' . $address . '&key=' . $this->gAPIKey . '&inputtype=textquery&language=en&fields=formatted_address,geometry';
					$arrayName = 'candidates';
				break;
		}

		$optionsArray = array(
			CURLOPT_URL => $url,
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
			$this->error = true;
			$this->errorMsg = 'Looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			$this->responseCode = 500;
			
			$errorLog = new LogError();
			$errorLog->errorNumber = 194;
			$errorLog->errorMsg = 'Google Maps API cURL Error';
			$errorLog->badData = $err;
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}else{
			// Get Latitude and Longitude
			$jsonResponse = json_decode($response);
			if($jsonResponse->status == 'OK'){	
				if(count($jsonResponse->$arrayName) == 1){
					// Valid Request, store Latitude and Longitude
					$this->latitude = $jsonResponse->$arrayName[0]->geometry->location->lat;
					$this->longitude = $jsonResponse->$arrayName[0]->geometry->location->lng;

					// Add to Database
					if($this->validate($locationID, false)){
						// Valid Location, Prep for Database
						$db = new Database();
						$dbLocationID = $db->escape($locationID);
						$dbLatitude = $db->escape($this->latitude);
						$dbLongitude = $db->escape($this->longitude);

						// Update Query
						$db->query("UPDATE location SET latitude='$dbLatitude', longitude='$dbLongitude' WHERE id='$dbLocationID'");
						if($db->error){
							// Database Error
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
							$this->responseCode = $db->responseCode;
						}
						$db->close();
					}
					
					// Find Place From Text?
					if($googleAPI == 'findplacefromtext'){
						return $jsonResponse->$arrayName[0]->formatted_address;
						
						// Log as Found in error_log for later troubleshooting
						$errorLog = new LogError();
						$errorLog->errorNumber = 202;
						$errorLog->errorMsg = 'Address Found by Google';
						$errorLog->badData = 'Address String: ' . $addressString . ' // Response: ' . $jsonResponse->$arrayName[0]->formatted_address;
						$errorLog->filename = 'API / USAddresses.class.php';
						$errorLog->write();
					}
				}else{
					// More than one result, ambiguous
					$this->error = true;
					$this->errorMsg = "We found multiple locations based on the address you provided. Can you be more specific in your street address?";
					$this->responseCode = 400;
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 197;
					$errorLog->errorMsg = 'Multiple Google Maps API Results';
					$errorLog->badData = $jsonResponse;
					$errorLog->filename = 'API / Location.class.php';
					$errorLog->write();
				}
			}elseif($jsonResponse->status == 'ZERO_RESULTS'){
				// The search was successful but returned no results
				$this->error = true;
				$this->errorMsg = "We were not able to find a location based on the address you provided.";
				$this->responseCode = 400;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 196;
				$errorLog->errorMsg = 'Unable to find location (Google Places API)';
				$errorLog->badData = "LocationID: $locationID / Address String: $addressString / Status: $jsonResponse->status / Error Message: $jsonResponse->error_message";
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}else{
				// Google Maps API Error
				$this->error = true;
				$this->errorMsg = 'Sorry, we were not able to find an address for you. We have logged the error and our support team will look into it.';
				$this->responseCode = 500;
				
				$errorLog = new LogError();
				$errorLog->errorNumber = 195;
				$errorLog->errorMsg = 'Google Maps Error';
				$errorLog->badData = 'Status: ' . $jsonResponse->status . ' / Error Message: ' . $jsonResponse->error_message;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}
		}
	}
	
	public function generateLocationObject(){
		// Generates the Location Object
		// Generally returned as part of the API output
		
		// Optional Values that may be stored as null, return as empty ("")
		if(empty($this->url)){$this->url = null;}
		if(empty($this->latitude)){$this->latitude = null;}
		if(empty($this->longitude)){$this->longitude = null;}
		
		// Get Brewery Data
		$brewer = new Brewer();
		$brewer->validate($this->brewerID, true);
		$brewer->generateBrewerObject(true);
		
		// Address Data
		$usAddresses = new USAddresses();
		
		// Generate JSON
		$this->json['id'] = $this->locationID;
		$this->json['object'] = 'location';
		$this->json['name'] = $this->name;
		$this->json['url'] = $this->url;
		$this->json['country_code'] = $this->countryCode;
		$this->json['country_short_name'] = $this->countryShortName;
		$this->json['latitude'] = $this->latitude;
		$this->json['longitude'] = $this->longitude;
		$this->json['cb_verified'] = $this->cbVerified;
		$this->json['brewer_verified'] = $this->brewerVerified;
		$this->json['last_modified'] = $this->lastModified;
		if($usAddresses->validate($this->locationID, true)){
			if(empty($usAddresses->address1)){$usAddresses->address1 = null;}
			if(empty($usAddresses->zip4)){$usAddresses->zip4 = null;}
			if(empty($usAddresses->telephone)){$usAddresses->telephone = null;}
			
			$this->json['address']['address1'] = $usAddresses->address1;
			$this->json['address']['address2'] = $usAddresses->address2;
			$this->json['address']['city'] = $usAddresses->city;
			$this->json['address']['sub_code'] = $usAddresses->sub_code;
			$this->json['address']['state_short'] = $usAddresses->stateShort;
			$this->json['address']['state_long'] = $usAddresses->stateLong;
			$this->json['address']['zip5'] = $usAddresses->zip5;
			$this->json['address']['zip4'] = $usAddresses->zip4;
			$this->json['address']['telephone'] = $usAddresses->telephone;
		}
		$this->json['brewer'] = $brewer->json;
	}

	public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
		/*---
		{METHOD} https://api.catalog.beer/location/{function}
		{METHOD} https://api.catalog.beer/location/{id}/{function}
		
		GET https://api.catalog.beer/location/{location_id}
		GET https://api.catalog.beer/location/nearby

		POST https://api.catalog.beer/location
		
		PUT https://api.catalog.beer/location/{location_id}
		
		PATCH https://api.catalog.beer/location/{location_id}
		
		DELETE https://api.catalog.beer/location/{location_id}
		---*/
		// Connect to Class
		

		switch($method){
			case 'GET':
				if(!empty($id) && empty($function)){
					// GET https://api.catalog.beer/location/{location_id}
					// Validate ID
					if($this->validate($id, true)){
						// Valid Location
						$this->generateLocationObject();
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
			case 'POST':
				// POST https://api.catalog.beer/location
				// Add Location

				// Handle Empty Fields
				if(empty($data->brewer_id)){$data->brewer_id = '';}
				if(empty($data->name)){$data->name = '';}
				if(empty($data->url)){$data->url = '';}
				if(empty($data->country_code)){$data->country_code = '';}
				
				// Validate API Key for userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				$this->add($data->brewer_id, $data->name, $data->url, $data->country_code, $apiKeys->userID, 'POST', '', array());
				if(!$this->error){
					// JSON Response
					$this->generateLocationObject();
				}else{
					// Error Adding Location
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'PUT':
				// PUT https://api.catalog.beer/location/{location_id}

				// Handle Empty Fields
				if(empty($data->brewer_id)){$data->brewer_id = '';}
				if(empty($data->name)){$data->name = '';}
				if(empty($data->url)){$data->url = '';}
				if(empty($data->country_code)){$data->country_code = '';}
				
				// Validate API Key for userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				$this->add($data->brewer_id, $data->name, $data->url, $data->country_code, $apiKeys->userID, 'PUT', $id, array());
				if(!$this->error){
					// JSON Response
					$this->generateLocationObject();
				}else{
					// Error Adding Location
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'PATCH':
				// PATCH https://api.catalog.beer/location/{location_id}
				// Which fields are we updating?
				$patchFields = array();

				// Handle Empty Fields
				if(isset($data->brewer_id)){$patchFields[] = 'brewerID';}
				else{$data->brewer_id = '';}
				
				if(isset($data->name)){$patchFields[] = 'name';}
				else{$data->name = '';}
				
				if(isset($data->url)){$patchFields[] = 'url';}
				else{$data->url = '';}
				
				if(isset($data->country_code)){$patchFields[] = 'countryCode';}
				else{$data->country_code = '';}
				
				// Validate API Key for userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				$this->add($data->brewer_id, $data->name, $data->url, $data->country_code, $apiKeys->userID, 'PATCH', $id, $patchFields);
				if(!$this->error){
					// JSON Response
					$this->generateLocationObject();
				}else{
					// Error Adding Location
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'DELETE':
				// DELETE https://api.catalog.beer/location/{location_id}
				// Get userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Delete Location
				$this->delete($id, $apiKeys->userID);
				if(!$this->error){
					// Successful Delete
					$this->responseCode = 204;
				}else{
					// Error
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
				}
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->json['error'] = true;
				$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
				$this->responseCode = 405;
				$this->responseHeader = 'Allow: GET, POST, DELETE';

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
