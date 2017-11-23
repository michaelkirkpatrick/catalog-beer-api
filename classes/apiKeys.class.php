<?php
class apiKeys {
	
	public $apiKey = '';
	public $userID = '';
	
	public $error = false;
	public $errorMsg = '';
	
	public function add($userID){
		// Already have a key?
		$apiKey = $this->getKey($userID);
		
		if(!$this->error){
			if(empty($apiKey)){
				// Generate API Key
				$uuid = new uuid();
				$this->apiKey = $uuid->generate('api_keys');
				if(!$uuid->error){
					// Add to Database
					$db = new Database();
					$dbAPIKey = $db->escape($this->apiKey);
					$dbUserID = $db->escape($userID);
					$db->query("INSERT INTO api_keys (id, userID) VALUES ('$dbAPIKey', '$dbUserID')");
					if($db->error){
						// Database Error
						$this->error = true;
						$this->errorMsg = $db->errorMsg;
					}
				}else{
					// API Key Generation Error
					$this->error = true;
					$this->errorMsg = $uuid->errorMsg;
				}
			}else{
				// Already have an API Key
				$this->error = true;
				$this->errorMsg = 'Whoops, it looks like this user_id already has an API key associated with it. Perhaps you want to use the endpoint "GET /users/{user_id}/api-key".';
			}
		}
	}
	
	public function validate($apiKey, $saveToClass){
		// Setup Variables
		$valid = false;
		
		if(!empty($apiKey)){
			// Connect to Database
			$db = new Database();
			$dbApiKey = $db->escape($apiKey);
			
			// Query Database
			$db->query("SELECT userID FROM api_keys WHERE id='$dbApiKey'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid API Key
					$valid = true;
					
					// Save to Class?
					if($saveToClass){
						$array = $db->resultArray();
						$this->apiKey = $apiKey;
						$this->userID = $array['userID'];
					}
				}else{
					// Invalid API Key
				}
			}else{
				// Database Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing API Key
			$this->error = true;
			$this->errorMsg = 'We seem to be missing your API key. Please try your request again and ensure you have included your API key.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 10;
			$errorLog->errorMsg = 'Missing API key';
			$errorLog->badData = '';
			$errorLog->filename = 'API / apiKeys.class.php';
			$errorLog->write();
		}
		
		// Return
		return $valid;
	}
	
	public function getKey($userID){
		// Return
		$apiKey = '';
		
		if(!empty($userID)){
			$users = new Users();
			if($users->validate($userID, false)){
				// Connect to Database
				$db = new Database();
				$dbUserID = $db->escape($userID);

				// Query Database
				$db->query("SELECT id FROM api_keys WHERE userID='$dbUserID'");
				if(!$db->error){
					if($db->result->num_rows == 1){
						$array = $db->resultArray();
						$apiKey = $array['id'];
					}
				}else{
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
				}
			}else{
				// Invalid UserID
				$this->error = true;
				$this->errorMsg = 'Sorry, we don\'t have a user with this user_id in our database.';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 81;
				$errorLog->errorMsg = 'Invalid userID';
				$errorLog->badData = "userID: $userID";
				$errorLog->filename = 'API / apiKeys.class.php';
				$errorLog->write();
			}
		}else{
			// Missing API Key
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 77;
			$errorLog->errorMsg = 'Missing userID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / apiKeys.class.php';
			$errorLog->write();
		}
		
		// Return
		return $apiKey;
	}
}
?>