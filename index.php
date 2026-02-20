<?php
// Initialize
include_once $_SERVER["DOCUMENT_ROOT"] . '/classes/initialize.php';

// Defaults
$apiKey = '';
$error = false;
$json = array();
$responseCode = 200;
$responseHeader = '';

// ----- Method & Data -----

// get the HTTP method and body of the request
$method = $_SERVER['REQUEST_METHOD'];
$input = file_get_contents('php://input');
if(!empty($input)){
	$data = json_decode($input);
	if(empty($data)){
		// Set new, empty class for empty data sets
		$data = new stdClass();
	}
	if(json_last_error() > 0){
		// Error Decoding JSON
		$error = true;
		$responseCode = 400;
		$json['error'] = true;
		switch (json_last_error()) {
			case JSON_ERROR_DEPTH:
		    $json['error_msg'] = 'JSON decoding error: Maximum stack depth exceeded';
			break;
			case JSON_ERROR_STATE_MISMATCH:
		    $json['error_msg'] = 'JSON decoding error: Underflow or the modes mismatch';
			break;
			case JSON_ERROR_CTRL_CHAR:
		    $json['error_msg'] = 'JSON decoding error: Unexpected control character found';
			break;
			case JSON_ERROR_SYNTAX:
		    $json['error_msg'] = 'JSON decoding error: Syntax error, malformed JSON';
			break;
			case JSON_ERROR_UTF8:
		    $json['error_msg'] = 'JSON decoding error: Malformed UTF-8 characters, possibly incorrectly encoded';
			break;
			case JSON_ERROR_RECURSION:
		    $json['error_msg'] = 'JSON decoding error: One or more recursive references in the value to be encoded';
			break;
			case JSON_ERROR_INF_OR_NAN:
		    $json['error_msg'] = 'JSON decoding error: One or more NAN or INF values in the value to be encoded';
			break;
			case JSON_ERROR_UNSUPPORTED_TYPE:
		    $json['error_msg'] = 'JSON decoding error: A value of a type that cannot be encoded was given';
			break;
			case JSON_ERROR_INVALID_PROPERTY_NAME:
		    $json['error_msg'] = 'JSON decoding error: A property name that cannot be encoded was given';
			break;
			case JSON_ERROR_UTF16:
		    $json['error_msg'] = 'JSON decoding error: Malformed UTF-16 characters, possibly incorrectly encoded';
			break;
			default:
		    $json['error_msg'] = 'JSON decoding error: Unknown error';
			break;
		}

		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 154;
		$errorLog->errorMsg = 'JSON Decoding Error';
		$errorLog->badData = $json['error_msg'] . ' // ' . $input;
		$errorLog->filename = 'API / index.php';
		$errorLog->write();
	}
}else{
	// Setup Default Class
	$data = new stdClass();
}

// General URL Parameters
$count = 500;
$cursor = base64_encode('0');	// Page
$endpoint = '';
$function = '';
$id = '';
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

// Location Search URL Parameters
$data->latitude = 0;
$data->longitude = 0;
$data->searchRadius = null;
$data->metric = '';
if(isset($_GET['latitude'])){
	$data->latitude = $_GET['latitude'];
}
if(isset($_GET['longitude'])){
	$data->longitude = $_GET['longitude'];
}
if(isset($_GET['search_radius'])){
	$data->searchRadius = $_GET['search_radius'];
}
if(isset($_GET['metric'])){
	$data->metric = $_GET['metric'];
}

// --- Health Check (no auth required) ---
if($endpoint == 'health'){
	if($method != 'GET'){
		http_response_code(405);
		header('Content-Type: application/json');
		header('Allow: GET');
		echo json_encode(array('error' => true, 'error_msg' => 'Method Not Allowed.'));
		exit;
	}
	$dbHealthy = false;
	try {
		$db = new Database();
		$result = $db->query("SELECT 1");
		if($result){
			$dbHealthy = true;
		}
		$db->close();
	} catch (Exception $e) {
		// DB unavailable
	}
	if($dbHealthy){
		http_response_code(200);
		header('Content-Type: application/json');
		echo json_encode(array('status' => 'ok'));
	}else{
		http_response_code(503);
		header('Content-Type: application/json');
		echo json_encode(array('status' => 'error'));
	}
	exit;
}

// --- Check Headers ----

// Get all the headers that were sent
$headers = array();
foreach (getallheaders() as $name => $value) {
	$headers[$name] = $value;
}

// Content-Type
$contentTypeMethods = array('POST', 'PUT', 'PATCH');
if(in_array($method, $contentTypeMethods)){
	// Check the Content-Type Header - Method may include 'Content-Type' header.
	if(array_key_exists('Content-Type', $headers)){
		// User is telling us they are sending a specific Content-Type
		if($headers['Content-Type'] != 'application/json'){
			// They are trying to send us data in a form that we can't accept
			$error = true;
			$responseCode = 415;
			$json['error'] = true;
			$json['error_msg'] = 'Our API only accepts JSON data at this time. Based on your \'Content-Type\' header, it doesn\'t appear that you are sending us \'application/json\' data.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 157;
			$errorLog->errorMsg = 'Invalid Content-Type Header';
			$errorLog->badData = $headers['Content-Type'];
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
		}
	}
}

// Accept
$contentTypeMethods = array('GET', 'POST', 'PUT', 'PATCH');
if(in_array($method, $contentTypeMethods)){
	// Check the Accept Header - Method may include 'Accept' header.
	if(array_key_exists('Accept', $headers)){
		// User is telling us they would like us to return a specific Content-Type
		$acceptableAcceptHeader = array('application/json', '*/*');
		if(!in_array($headers['Accept'], $acceptableAcceptHeader)){
			// They are asking us to send us data in a format different from what we send (JSON)
			$error = true;
			$responseCode = 406;
			$json['error'] = true;
			$json['error_msg'] = 'At this time our API only sends data in a JSON format. Based on your \'Accept\' header, it appears that you would like us to send you data in a format other than \'application/json\'.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 158;
			$errorLog->errorMsg = 'Invalid Accept header';
			$errorLog->badData = $headers['Accept'];
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
		}
	}
}

// ----- HTTPS & Authorization  -----
if(isset($_SERVER['HTTPS'])){
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
}else{
	// No HTTPS Variable Set
	$error = true;
	$responseCode = 500;
	$json['error'] = true;
	$json['error_msg'] = 'In order to connect to the Catalog.beer API, you will need to connect using a secure connection (HTTPS). Please try your request again.';
}

/* - - - - - Process Based on Endpoint - - - - - */
if(!$error){
	switch($endpoint){
		case 'address':
			$usAddresses = new USAddresses();
			$usAddresses->api($method, $id, $apiKey, $data);
			$json = $usAddresses->json;
			$responseCode = $usAddresses->responseCode;
			$responseHeader = '';
			break;
		case 'beer':
			$beer = new Beer();
			$beer->api($method, $function, $id, $apiKey, $count, $cursor, $data);
			$json = $beer->json;
			$responseCode = $beer->responseCode;
			$responseHeader = $beer->responseHeader;
			break;
		case 'brewer':
			$brewer = new Brewer();
			$brewer->api($method, $function, $id, $apiKey, $count, $cursor, $data);
			$json = $brewer->json;
			$responseCode = $brewer->responseCode;
			$responseHeader = $brewer->responseHeader;
			break;
		case 'location':
			if(empty($_GET['count'])){$count = 0;}
			$location = new Location();
			$location->api($method, $function, $id, $apiKey, $count, $cursor, $data);
			$json = $location->json;
			$responseCode = $location->responseCode;
			$responseHeader = $location->responseHeader;
			break;
		case 'login':
			$users = new Users();
			$users->loginAPI($method, $apiKey, $data);
			$json = $users->json;
			$responseCode = $users->responseCode;
			$responseHeader = $users->responseHeader;
			break;
		case 'usage':
			$usage = new Usage();
			$usage->api($method, $function, $id, $apiKey);
			$json = $usage->json;
			$responseCode = $usage->responseCode;
			$responseHeader = $usage->responseHeader;
			break;
		case 'users':
			$users = new Users();
			$users->usersAPI($method, $function, $id, $apiKey, $data);
			$json = $users->json;
			$responseCode = $users->responseCode;
			$responseHeader = $users->responseHeader;
			break;
		default:
			// Invalid Endpoint
			$responseCode = 404;
			$json['error'] = true;
			$json['error_msg'] = 'Invalid path. The URI you requested does not exist.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 151;
			$errorLog->errorMsg = 'Invalid endpoint';
			$errorLog->badData = $endpoint;
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
	}
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

$masterKeys = unserialize(MASTER_API_KEYS);
if(!in_array($apiKey, $masterKeys)){
	// Log Request
	$apiLogging = new apiLogging();
	$apiLogging->add($apiKey, $method, $_SERVER['REQUEST_URI'], $data, $json_encoded ?: '', $responseCode);
}
?>
