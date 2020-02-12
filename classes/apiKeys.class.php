<?php
class apiKeys {
	
	// Variables
	public $apiKey = '';
	public $userID = '';
	
	// Error Handling
	public $error = false;
	public $errorMsg = '';
	public $responseCode = 200;
	
	public function add($userID){
		// Already have a key?
		$apiKey = $this->getKey($userID);
		
		if(!$this->error){
			if(empty($apiKey)){
				// Has the users's email been verified?
				$users = new Users();
				$users->validate($userID, true);
				
				if($users->emailVerified){
					// Generate API Key
					$uuid = new uuid();
					$apiKey = $uuid->generate('api_keys');
					if(!$uuid->error){
						// Add to Database
						$db = new Database();
						$dbAPIKey = $db->escape($apiKey);
						$dbUserID = $db->escape($userID);
						$db->query("INSERT INTO api_keys (id, userID) VALUES ('$dbAPIKey', '$dbUserID')");
						if(!$db->error){
							// Successfully added apiKey
							$this->userID = $userID;
							$this->apiKey = $apiKey;
						}else{
							// Database Error
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
							$this->responseCode = $db->responseCode;
						}
						$db->close();
					}else{
						// API Key Generation Error
						$this->error = true;
						$this->errorMsg = $uuid->errorMsg;
						$this->responseCode = $uuid->responseCode;
					}
				}else{
					// The user needs to verify their email before we will provide an API Key
					$this->error = true;
					$this->errorMsg = 'Before we can provide you with an API key, you will need to validate your email address.';
					$this->responseCode = 403;
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 150;
					$errorLog->errorMsg = 'Email verification required before API key will be issued.';
					$errorLog->badData = 'userID: ' . $userID;
					$errorLog->filename = 'API / apiKeys.class.php';
					$errorLog->write();
				}
			}else{
				// This user already has an API Key
				$this->error = true;
				$this->errorMsg = 'Whoops, it looks like this user_id already has an API key associated with it. Perhaps you want to use the endpoint "GET /users/{user_id}/api-key".';
				$this->responseCode = 400;
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
				}elseif($db->result->num_rows > 1){
					// Duplicate API Keys
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					$this->responseCode = 500;
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 148;
					$errorLog->errorMsg = 'Duplicate API Keys';
					$errorLog->badData = $apiKey;
					$errorLog->filename = 'API / apiKeys.class.php';
					$errorLog->write();
				}else{
					// Invalid API Key -- Does not exist in the database
					// Script will return default of $valid = false;
					// No further action required.
				}
			}else{
				// Database Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Missing API Key
			$this->error = true;
			$this->errorMsg = 'We seem to be missing your API key. Please try your request again and ensure you have included your API key.';
			$this->responseCode = 400;
			
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
					}elseif($db->result->num_rows > 1){
						// Duplicate API Keys for userID
						$this->error = true;
						$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
						$this->responseCode = 500;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 149;
						$errorLog->errorMsg = 'More than one API key for userID';
						$errorLog->badData = 'userID: ' . $userID;
						$errorLog->filename = 'API / apiKeys.class.php';
						$errorLog->write();
					}else{
						// This user doesn't have an API Key yet
						// Return default empty $apiKey = '';
					}
				}else{
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
				$db->close();
			}else{
				// Invalid UserID
				$this->error = true;
				$this->errorMsg = $users->errorMsg;
				$this->responseCode = $users->responseCode;
			}
		}else{
			// Missing userID
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			$this->responseCode = 500;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 77;
			$errorLog->errorMsg = 'Missing userID';
			$errorLog->badData = $userID;
			$errorLog->filename = 'API / apiKeys.class.php';
			$errorLog->write();
		}
		
		// Return
		return $apiKey;
	}
	
	public function deleteUser($userID){
		/*--
		Assume the following for this function
		1) userID has been validated
		2) User calling this function has been validated and has permission to perform this action.
		This function does not perform this validation so as to not do it every time.
		--*/
		
		// Prep for Database
		$db = new Database();
		$dbUserID = $db->escape($userID);
		
		// Delete API Keys for this userID
		$db->query("DELETE FROM api_keys WHERE userID='$dbUserID'");
		if($db->error){
			// Database Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = $db->responseCode;
		}
		$db->close();
	}
}
?>