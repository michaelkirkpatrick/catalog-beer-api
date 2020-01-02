<?php
/*
$apiLogging = new apiLogging();
$apiLogging->add($apiKey, $method, $uri, $body, $response, $responseCode);
*/

class apiLogging {
	
	public $id = '';
	public $apiKey = '';
	public $timestamp = 0;
	public $ipAddress = '';
	public $method = '';
	public $uri = '';
	public $body = '';
	public $response = '';
	public $responseCode = 0;
	
	private $error = false;
	
	public function add($apiKey, $method, $uri, $body, $response, $responseCode){
		// Check for missing variables
		if(!empty($apiKey) && !empty($method) && !empty($uri)){
			// Generate UUID
			$uuid = new uuid();
			$this->id = $uuid->generate('api_logging');
			if($uuid->error){
				$this->error = true;
			}

			// Save to Class
			$this->apiKey = $apiKey;
			$this->timestamp = time();
			$this->ipAddress = $_SERVER['REMOTE_ADDR'];
			$this->method = $method;
			$this->uri = $uri;
			$this->body = serialize($body);
			$this->response = $response;
			$this->responseCode = $responseCode;

			if(!$this->error){
				// Prep for Database
				$db = new Database();
				$dbAPILogID = $db->escape($this->id);
				$dbAPIKey = $db->escape($this->apiKey);
				$dbTimestamp = $db->escape($this->timestamp);
				$dbIPAddress = $db->escape($this->ipAddress);
				$dbMethod = $db->escape($this->method);
				$dbURI = $db->escape($this->uri);
				$dbBody = $db->escape($this->body);
				$dbResponse = $db->escape($this->response);
				$dbResponseCode = $db->escape($this->responseCode);

				// Insert
				$db->query("INSERT INTO api_logging (id, apiKey, timestamp, ipAddress, method, uri, body, response, responseCode) VALUES ('$dbAPILogID', '$dbAPIKey', '$dbTimestamp', '$dbIPAddress', '$dbMethod', '$dbURI', '$dbBody', '$dbResponse', '$dbResponseCode')");
				if($db->error){
					$this->error = true;
				}
			}
		}else{
			// Missing required attrribute
			$this->error = true;

			$errorLog = new LogError();
			$errorLog->errorNumber = 48;
			$errorLog->errorMsg = 'Missing required parameter';
			$errorLog->badData = "apiKey: $apiKey / method: $method / uri: $uri / body: $body";
			$errorLog->filename = 'API / apiLogging.class.php';
			$errorLog->write();
		}
	}
}