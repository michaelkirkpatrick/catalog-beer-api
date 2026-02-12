<?php
/*
$apiLogging = new apiLogging();
$apiLogging->add($apiKey, $method, $uri, $body, $response, $responseCode);
*/

class apiLogging {

	// Variables
	public $id = '';
	public $apiKey = '';
	public $timestamp = 0;
	public $ipAddress = '';
	public $method = '';
	public $uri = '';
	public $body = '';
	public $response = '';
	public $responseCode = 0;

	// Error Handling
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
			if($method == 'GET'){
				// Don't save the response (Memory Issues with large requests)
				$this->response = '';
			}else{
				// Save the Response
				$this->response = $response;
			}
			$this->responseCode = $responseCode;

			if(!$this->error){
				// Insert
				$db = new Database();
				$db->query("INSERT INTO api_logging (id, apiKey, timestamp, ipAddress, method, uri, body, response, responseCode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [$this->id, $this->apiKey, $this->timestamp, $this->ipAddress, $this->method, $this->uri, $this->body, $this->response, $this->responseCode]);
				if($db->error){
					$this->error = true;
				}
				$db->close();
			}
		}else{
			// Missing required attribute
			$this->error = true;
			$errorLog = new LogError();
			$errorLog->errorNumber = 48;
			$errorLog->errorMsg = 'Missing required parameter';
			$errorLog->badData = "apiKey: $this->apiKey / method: $this->method / uri: $this->uri / body: $this->body";
			$errorLog->filename = 'API / apiLogging.class.php';
			$errorLog->write();
		}
	}
}
