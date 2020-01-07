<?php

class Usage {
	
	public $id = '';
	public $apiKey = '';
	public $year = 0;
	public $month = 0;
	public $count = 0;
	public $lastUpdated = 0;
	
	// Validation
	public $error = false;
	public $errorMsg = '';
	
	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();
	
	public function currentUsage($apiKey, $apiKeyInUse){
		// Get usage for API Key ($apiKey)
		// The request comes from $apiKeyInUse
		// Usage for Current Month
		
		$apiKey = trim($apiKey);
		if(!empty($apiKey)){
			// Required Classes
			$apiKeys = new apiKeys();
			if($apiKeys->validate($apiKey, true)){
				
				$users = new Users();
				$users->validate($apiKeys->userID, true);

				if($apiKey != $apiKeyInUse){
					// This request didn't come from the user themselves, ensure it came from an Admin user (e.g. the website)
					if(!$users->admin){
						// Didn't come from the Admin user, disallow this request
						$this->responseCode = 401;
						$this->error = true;
						$this->errorMsg = 'Unauthorized: API Key mismatch. In order to retreive API usage for your account, you must make the API request using your API Key.';
					}
				}

				if(!$this->error){
					// Current month and year
					$year = date('Y', time());
					$month = date('n', time());

					// Required Class
					$db = new Database();

					// Prep for Database
					$dbAPIKey = $db->escape($apiKey);
					$dbYear = $db->escape($year);
					$dbMonth = $db->escape($month);

					// Query
					$db->query("SELECT id, count, lastUpdated FROM api_usage WHERE apiKey='$dbAPIKey' AND year='$dbYear' AND month='$dbMonth'");
					$array = $db->resultArray();

					// Save to Class
					$this->id = $array['id'];
					$this->apiKey = $apiKey;
					$this->year = $year;
					$this->month = $month;
					$this->count = $array['count'];
					$this->lastUpdated = $array['lastUpdated'];

					// Close Database Connection
					$db->close();
				}
			}else{
				// Invalid API Key
				$this->error = true;
				$this->errorMsg = 'Invalid API Key. Unable to retreive API usage information.';
				$this->responseCode = 404;

				$errorLog = new LogError();
				$errorLog->errorNumber = 143;
				$errorLog->errorMsg = 'Invalid API Key';
				$errorLog->badData = $apiKey;
				$errorLog->filename = 'API / Usage.class.php';
				$errorLog->write();
			}
		}else{
			// Missing API Key
			$this->error = true;
			$this->errorMsg = 'Missing API Key. Ensure your request includes the API key in the URL: /usage/currentMonth/{api_key}';
			$this->responseCode = 400;
			
			$errorLog = new LogError();
			$errorLog->errorNumber = 131;
			$errorLog->errorMsg = 'Missing API Key';
			$errorLog->badData = $apiKey;
			$errorLog->filename = 'API / Usage.class.php';
			$errorLog->write();
		}
	}
	
	public function api($method, $function, $id, $apiKey){
		/*-----
		/{endpoint}/{function}/{api_key}
		currentUsage() -> /usage/currentMonth/{api_key}
		-----*/
		switch($method){
			case 'GET':
				switch($function){
					case 'currentMonth':
						$this->currentUsage($id, $apiKey, false);
						if(!$this->error){
							$this->json['id'] = $this->id;
							$this->json['object'] = 'usage';
							$this->json['api_key'] = $this->apiKey;
							$this->json['year'] = intval($this->year);
							$this->json['month'] = intval($this->month);
							$this->json['count'] = intval($this->count);
							$this->json['last_updated'] = intval($this->lastUpdated);
						}else{
							$this->json['error'] = true;
							$this->json['error_msg'] = $this->errorMsg;
						}
						break;
					default:
						$this->json['error'] = true;
						$this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';
						$this->responseCode = 404;
						
						$errorLog = new LogError();
						$errorLog->errorNumber = 130;
						$errorLog->errorMsg = 'Invalid function (/usage)';
						$errorLog->badData = $function;
						$errorLog->filename = 'API / Usage.class.php';
						$errorLog->write();
				}
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->json['error'] = true;
				$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
				$this->responseCode = 405;
				$this->responseHeader = 'Allow: GET';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 141;
				$errorLog->errorMsg = 'Invalid Method (/usage)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Usage.class.php';
				$errorLog->write();
		}
	}
}
?>