<?php
// Initialize
include_once $_SERVER["DOCUMENT_ROOT"] . '/classes/initialize.php';
$json = array();

// Process Status Code
$code = $_GET['code'];

switch($code){
	case 403:
		// Show Error Message
		$json['error'] = true;
		$json['error_msg'] = "Forbidden - Sorry, you have requested a resource that you don't have permission to view. We've logged the error so our support team is aware of it. If you believe you shoudn't be seeing a 403 error for your request, please contact us: https://catalog.beer/contact";
		
		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 403;
		$errorLog->errorMsg = '403 Error';
		$errorLog->filename = 'API / errors.php';
		$errorLog->write();
		break;
	case 404:
		// Show Error Message
		$json['error'] = true;
		$json['error_msg'] = "Not Found - Sorry, we weren't able to find the resource you requested. That's a bummer. We've logged the error so our support team is aware of it. If you believe you shoudn't be seeing a 404 error for your request, please contact us: https://catalog.beer/contact";
		
		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 404;
		$errorLog->errorMsg = '404 Error';
		$errorLog->filename = 'API / errors.php';
		$errorLog->write();
		break;
	case 500:
		// Show Error Message
		$json['error'] = true;
		$json['error_msg'] = "Internal Server Error - Sorry, we seem to be having some difficulty serving you what you requested. That's a bummer; hopefully it will pass soon. We've logged the error so our support team is aware of it. If you believe you shoudn't be seeing a 500 error for your request, please contact us: https://catalog.beer/contact";
		
		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 500;
		$errorLog->errorMsg = '500 Error';
		$errorLog->filename = 'API / errors.php';
		$errorLog->write();
		break;
	case 503:
		// Show Error Message
		$json['error'] = true;
		$json['error_msg'] = "Service Unavailable - Sorry, the resource you requested is temporarily unavailable. That's a bummer; hopefully it will pass soon. We've logged the error so our support team is aware of it. If you believe you shoudn't be seeing a 503 error for your request, please contact us: https://catalog.beer/contact";
		
		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 503;
		$errorLog->errorMsg = '503 Error';
		$errorLog->filename = 'API / errors.php';
		$errorLog->write();
		break;
}

/* - - - - - RESPONSE - - - - - */

// HTTP Status Code
http_response_code(404);

// Header Type
header('Content-Type: application/json');

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
	$errorLog->errorNumber = 134;
	$errorLog->errorMsg = 'JSON Encoding Error';
	$errorLog->badData = $json_orig;
	$errorLog->filename = 'API / index.php';
	$errorLog->write();
}