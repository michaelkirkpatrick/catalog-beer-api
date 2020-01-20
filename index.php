<?php
// Initialize
include_once $_SERVER["DOCUMENT_ROOT"] . '/classes/initialize.php';

// Method & Data
// get the HTTP method, path and body of the request
$method = $_SERVER['REQUEST_METHOD'];
$input = file_get_contents('php://input');
$data = json_decode($input);
if(empty($data)){$data = new stdClass();}

// Defaults
$apiKey = '';
$count = 500;
$cursor = base64_encode('0');	// Page
$endpoint = '';
$error = false;
$function = '';
$id = '';
$json = array();
$responseCode = 200;
$responseHeader = '';

if(isset($_GET['count'])){
	$count = $_GET['count'];
}
if(isset($_GET['cursor'])){
	$cursor = $_GET['cursor'];
}
if(isset($_GET['endpoint'])){
	$endpoint = $_GET['endpoint'];
}
if(isset($_GET['function'])){
	$function = $_GET['function'];
}
if(isset($_GET['id'])){
	$id = substr($_GET['id'], 1, 36);
}


if($_SERVER['HTTPS'] == 'on'){
	// Check Authorization Header
	if(isset($_SERVER['PHP_AUTH_USER'])){
		// Get Submitted Username and Password
		$apiKey = $_SERVER['PHP_AUTH_USER'];

		if(!empty($apiKey)){
			$apiKeys = new apiKeys();
			if(!$apiKeys->validate($apiKey, true)){
				// Invalid User
				$error = true;
				$responseCode = 401;
				$json['error'] = true;
				$json['error_msg'] = 'Sorry to say this, but your API key appears to be invalid. Please contact Catalog.beer support if you believe you have received this message in error; we will help you figure it out.';
			}
		}else{
			// Missing Username
			$error = true;
			$responseCode = 401;
			$json['error'] = true;
			$json['error_msg'] = 'We are missing your API Key. This key should be submitted in the username field of your API request using HTTP Basic Auth. No password is required.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 7;
			$errorLog->errorMsg = 'Missing username';
			$errorLog->badData = '';
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
		}
	}else{
		// Invalid Authentication
		$error = true;
		$responseCode = 401;
		$json['error'] = true;
		$json['error_msg'] = 'Missing API key. Please check that your request includes your API key as the Username using HTTP basic auth and then try again.';

		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 6;
		$errorLog->errorMsg = 'No credentials submitted';
		$errorLog->badData = '';
		$errorLog->filename = 'API / index.php';
		$errorLog->write();
	}
}else{
	// No HTTPS
	$error = true;
	$responseCode = 400;
	$json['error'] = true;
	$json['error_msg'] = 'In order to connect to the Catalog.beer API, you will need to connect using a secure connection (HTTPS). Please try your request again.';
}

/* - - - - - BREWER - - - - - */
if($endpoint == 'brewer' && !$error){
	$brewer = new Brewer();
	$brewer->api($method, $function, $id, $apiKey, $count, $cursor, $data);
	$json = $brewer->json;
	$responseCode = $brewer->responseCode;
	$responseHeader = $brewer->responseHeader;
}

/* - - - - - BEER - - - - - */
if($endpoint == 'beer' && !$error){
	$beer = new Beer();
	$beer->api($method, $function, $id, $apiKey, $count, $cursor, $data);
	$json = $beer->json;
	$responseCode = $beer->responseCode;
	$responseHeader = $beer->responseHeader;
}

/* - - - - - USERS - - - - - */
if($endpoint == 'users' && !$error){
	// Verify User
	$users = new Users();
	$users->validate($apiKeys->userID, true);
	if($users->admin){
		switch($method){
			case 'GET':
				if(!empty($id) && empty($function)){
					if($users->validate($id, true)){
						$json['id'] = $users->userID;
						$json['object'] = 'users';
						$json['name'] = $users->name;
						$json['email'] = $users->email;
						$json['emailVerified'] = $users->emailVerified;
						$json['emailAuth'] = $users->emailAuth;
						$json['emailAuthSent'] = $users->emailAuthSent;
						$json['admin'] = $users->admin;
					}else{
						// Invalid User
						$responseCode = 401;
						$json['error'] = true;
						$json['errorMsg'] = 'Sorry, this is not a valid Catalog.beer account.';

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 36;
						$errorLog->errorMsg = 'Invalid Account';
						$errorLog->badData = "UserID: $id";
						$errorLog->filename = 'API / index.php';
						$errorLog->write();
					}
				}else{
					switch($function){
						case 'api-key':
							if(!empty($id)){
								// Get API Key
								$userAPIKey = $apiKeys->getKey($id);
								if(!empty($userAPIKey)){
									$json['object'] = 'api_key';
									$json['user_id'] = $id;
									$json['api_key'] = $userAPIKey;
								}else{
									// Invalid User
									$json['error'] = true;
									$json['error_msg'] = $apiKeys->error_msg;
								}
							}else{
								// Missing Function
								$responseCode = 400;
								$json['error'] = true;
								$json['errorMsg'] = 'We seem to be missing the user_id you would like to retreive the api_key for. Please check your submission and try again.';

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 79;
								$errorLog->errorMsg = 'Missing user_id';
								$errorLog->badData = "UserID: $apiKeys->userID / function: $function / userID: $id";
								$errorLog->filename = 'API / index.php';
								$errorLog->write();
							}
							break;
						default:
							// Missing Function
							$responseCode = 400;
							$json['error'] = true;
							$json['errorMsg'] = 'Sorry, this is an invalid endpoint.';

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 78;
							$errorLog->errorMsg = 'Invalid Endpoint (/users)';
							$errorLog->badData = "UserID: $apiKeys->userID / function: $function / userID: $id";
							$errorLog->filename = 'API / index.php';
							$errorLog->write();		
					}
				}
				break;
			case 'POST':
				if(empty($function)){
					// Create Account
					$users->createAccount($data->name, $data->email, $data->password, $data->terms_agreement, $apiKeys->userID);
					if(!$users->error){
						$json['id'] = $users->userID;
						$json['object'] = 'users';
						$json['name'] = $users->name;
						$json['email'] = $users->email;
						$json['emailVerified'] = $users->emailVerified;
						$json['emailAuth'] = $users->emailAuth;
						$json['emailAuthSent'] = $users->emailAuthSent;
						$json['admin'] = $users->admin;
					}else{
						$responseCode = 400;
						$json['error'] = true;
						$json['error_msg'] = $users->errorMsg;
						$json['valid_state'] = $users->validState;
						$json['valid_msg'] = $users->validMsg;
					}
				}else{
					switch($function){
						case 'api-key':
							$apiKeys->add($id);
							if(!$apiKeys->error){
								$json['object'] = 'api_key';
								$json['user_id'] = $id;
								$json['api_key'] = $apiKeys->apiKey;
							}else{
								$responseCode = 400;
								$json['error'] = true;
								$json['errorMsg'] = $apiKeys->errorMsg;
							}
							break;
						case 'verify-email':
							$users->verifyEmail($id);
							if(!$users->error){
								$json['id'] = $users->userID;
								$json['object'] = 'users';
								$json['name'] = $users->name;
								$json['email'] = $users->email;
								$json['emailVerified'] = $users->emailVerified;
								$json['emailAuth'] = $users->emailAuth;
								$json['emailAuthSent'] = $users->emailAuthSent;
								$json['admin'] = $users->admin;
							}else{
								$responseCode = 400;
								$json['error'] = true;
								$json['error_msg'] = $users->errorMsg;
							}
							break;
						default:
							// Missing Function
							$responseCode = 400;
							$json['error'] = true;
							$json['error_msg'] = 'Sorry, this is an invalid endpoint.';

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 80;
							$errorLog->errorMsg = 'Invalid Endpoint (/users)';
							$errorLog->badData = "UserID: $apiKeys->userID / function: $function / id: $id";
							$errorLog->filename = 'API / index.php';
							$errorLog->write();	
					}
				}
				break;
			default:
				// Invalid Method
				$responseCode = 404;
				$json['error'] = true;
				$json['error_msg'] = 'Sorry, ' . $method . ' is an invalid method for this endpoint.';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 72;
				$errorLog->errorMsg = 'Invalid Method (/users)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / index.php';
				$errorLog->write();
		}
	}else{
		// Not an Admin
		$responseCode = 401;
		$json['error'] = true;
		$json['errorMsg'] = 'Sorry, your account does not have permission to perform this action.';

		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 37;
		$errorLog->errorMsg = 'Non-Admin trying to get account info';
		$errorLog->badData = "UserID: $apiKeys->userID / id: $id / function: $function";
		$errorLog->filename = 'API / index.php';
		$errorLog->write();
	}
}

/* - - - - - LOGIN - - - - - */
if($endpoint == 'login' && !$error){
	$users = new Users();
	$users->api($method, $apiKey, $data);
	$json = $users->json;
	$responseCode = $users->responseCode;
}

/* - - - - - LOCATION - - - - - */
if($endpoint == 'location' && !$error){
	// Connect to Class
	$location = new Location();
	$usAddresses = new USAddresses();
	
	switch($method){
		case 'POST':
			if(!empty($id)){
				if($location->validate($id, true)){
					// Add Address for Location
					$usAddresses->add($location->id, $data->address1, $data->address2, $data->city, $data->sub_code, $data->zip5, $data->zip4, $data->telephone);
					if(!$usAddresses->error){
						// Successfully Added
						$json['id'] = $location->id;
						$json['object'] = 'location';
						$json['name'] = $location->name;
						$json['brewer_id'] = $location->brewerID;
						$json['url'] = $location->url;
						$json['country_code'] = $location->countryCode;
						$json['country_short_name'] = $location->countryShortName;
						$json['latitude'] = $location->latitude;
						$json['longitude'] = $location->longitude;
						
						$json['telephone'] = $usAddresses->telephone;
						$json['address']['address1'] = $usAddresses->address1;
						$json['address']['address2'] = $usAddresses->address2;
						$json['address']['city'] = $usAddresses->city;
						$json['address']['sub_code'] = $usAddresses->sub_code;
						$json['address']['state_short'] = $usAddresses->stateShort;
						$json['address']['state_long'] = $usAddresses->stateLong;
						$json['address']['zip5'] = $usAddresses->zip5;
						$json['address']['zip4'] = $usAddresses->zip4;
					}else{
						// Error Adding Address
						$responseCode = 400;
						$json['error'] = true;
						$json['error_msg'] = $usAddresses->errorMsg;
						$json['valid_state'] = $usAddresses->validState;
						$json['valid_msg'] = $usAddresses->validMsg;
					}
				}else{
					// Invalid Location
					$responseCode = 404;
					$json['error'] = true;
					$json['error_msg'] = 'Sorry, we don\'t have any locations with that location_id. Please check your request and try again.';
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 85;
					$errorLog->errorMsg = 'Invalid location_id';
					$errorLog->badData = $id;
					$errorLog->filename = 'API / index.php';
					$errorLog->write();
				}
			}else{
				// Add Location
				$location->add($data->brewer_id, $data->name, $data->url, $data->country_code);
				if(!$location->error){
					// Successfully Added
					$json['id'] = $location->id;
					$json['object'] = 'location';
					$json['name'] = $location->name;
					$json['brewer_id'] = $location->brewerID;
					$json['url'] = $location->url;
					$json['country_code'] = $location->countryCode;
					$json['country_short_name'] = $location->countryShortName;
					$json['latitude'] = $location->latitude;
					$json['longitude'] = $location->longitude;
				}else{
					// Error Adding Location
					$responseCode = 400;
					$json['error'] = true;
					$json['error_msg'] = $location->errorMsg;
					$json['valid_state'] = $location->validState;
					$json['valid_msg'] = $location->validMsg;
				}
			}
			break;
		case 'GET':
			if(!empty($id) && empty($function)){
				// Validate ID
				if($location->validate($id, true)){
					// Valid Location
					$json['id'] = $location->id;
					$json['object'] = 'location';
					$json['name'] = $location->name;
					$json['brewer_id'] = $location->brewerID;
					$json['url'] = $location->url;
					$json['country_code'] = $location->countryCode;
					$json['country_short_name'] = $location->countryShortName;
					$json['latitude'] = $location->latitude;
					$json['longitude'] = $location->longitude;
					
					// Check for Address
					if($usAddresses->validate($location->id, true)){
						$json['telephone'] = $usAddresses->telephone;
						$json['address']['address1'] = $usAddresses->address1;
						$json['address']['address2'] = $usAddresses->address2;
						$json['address']['city'] = $usAddresses->city;
						$json['address']['sub_code'] = $usAddresses->sub_code;
						$json['address']['state_short'] = $usAddresses->stateShort;
						$json['address']['state_long'] = $usAddresses->stateLong;
						$json['address']['zip5'] = $usAddresses->zip5;
						$json['address']['zip4'] = $usAddresses->zip4;
					}
				}else{
					// Invalid Location
					$responseCode = 404;
					$json['error'] = true;
					$json['error_msg'] = 'Sorry, we don\'t have any locations with that location_id. Please check your request and try again.';
				}
			}elseif($function == 'nearby'){
				// Defaults
				$latitude = 0;
				$longitude = 0;
				$searchRadius = 0;
				$metric = '';
				$cursor = '';
				$count = 0;
				
				// Get URL Parameters
				if(isset($_GET['latitude'])){
					$latitude = $_GET['latitude'];
				}
				if(isset($_GET['longitude'])){
					$longitude = $_GET['longitude'];
				}
				if(isset($_GET['search_radius'])){
					$searchRadius = $_GET['search_radius'];
				}
				if(isset($_GET['metric'])){
					$metric = $_GET['metric'];
				}
				if(isset($_GET['cursor'])){
					$cursor = $_GET['cursor'];
				}
				if(isset($_GET['count'])){
					$count = $_GET['count'];
				}
				
				$nearbyLatLngReturn = $location->nearbyLatLng($latitude, $longitude, $searchRadius, $metric, $cursor, $count);
				if(!$location->error){
					// Start JSON
					$json['object'] = 'list';
					$json['url'] = '/location/nearby';

					// Next Cursor
					if(!empty($nearbyLatLngReturn['nextCursor'])){
						$json['has_more'] = true;
						$json['next_cursor'] = $nearbyLatLngReturn['nextCursor'];
					}else{
						$json['has_more'] = false;
					}

					// Append Data
					$json['data'] = $nearbyLatLngReturn['locationArray'];	
				}else{
					$responseCode = 400;
					$json['error'] = true;
					$json['error_msg'] = $location->errorMsg;
				}
				
			}else{
				// Invalid Endpoint
				$responseCode = 400;
				$json['error'] = true;
				$json['error_msg'] = 'Sorry, this is an invalid endpoint. You can list all the locations for a specific brewery (GET https://api.catalog.beer/brewer/{brewer_id}/locations).';
			}
			break;
		default:
			// Invalid Method
			$responseCode = 404;
			$json['error'] = true;
			$json['error_msg'] = 'Sorry, ' . $method . ' is an invalid method for this endpoint.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 74;
			$errorLog->errorMsg = 'Invalid Method (/location)';
			$errorLog->badData = $method;
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
	}
}

/* - - - - - USAGE - - - - - */
if($endpoint == 'usage' && !$error){
	// Required Class
	$usage = new Usage();
	$usage->api($method, $function, $id, $apiKey);
	$json = $usage->json;
	$responseCode = $usage->responseCode;
	$responseHeader = $usage->responseHeader;
}

/* - - - - - RESPONSE - - - - - */

// HTTP Status Code
http_response_code($responseCode);

// Header Type
header('Content-Type: application/json');
if(!empty($responseHeader)){
	header($responseHeader);
}

// Output JSON
if($json_encoded = json_encode($json)){
	echo $json_encoded;
}else{
	$json_orig = $json;
	$json = array();
	$json['error'] = true;
	$json['error_msg'] = 'Sorry, we have encountered an encoding error and are unable to present your data at this time. We\'ve logged the issue and our support team will look into it.';
	echo json_encode($json);
	
	// Log Error
	$errorLog = new LogError();
	$errorLog->errorNumber = 45;
	$errorLog->errorMsg = 'JSON Encoding Error';
	$errorLog->badData = $json_orig;
	$errorLog->filename = 'API / index.php';
	$errorLog->write();
}

// Log
$apiLogging = new apiLogging();
$apiLogging->add($apiKey, $method, $_SERVER['REQUEST_URI'], $data, $json_encoded, $responseCode);
?>