<?php
class Privileges {
	
	public $id = '';
	public $userID = '';
	public $brewerID = '';
	
	public $error = false;
	public $errorMsg = '';
	public $responseCode = 200;
	
	public function add($userID, $brewerID, $newBrewery){
		// Associate a new user as an "Admin" for a brewery
		// Validate User
		$users = new Users();
		if($users->validate($userID, false)){
			// Need to validate brewer?
			if($newBrewery){
				// Valid
				$validBrewer = true;
			}else{
				$brewer = new Brewer();
				$validBrewer = $brewer->validate($brewerID, false);
				if(!$validBrewer){
					// Invalid Brewer
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					$this->responseCode = 500;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 126;
					$errorLog->errorMsg = 'Invalid Brewer';
					$errorLog->badData = $brewerID;
					$errorLog->filename = 'API / Permissions.class.php';
					$errorLog->write();
				}
			}
			
			if($validBrewer){
				// Does user already have privileges for this brewerID?
				$brewerIDs = $this->brewerList($userID);
				
				if(!in_array($brewerID, $brewerIDs)){
					// userID not yet associated with this brewerID
					// Create UUID
					$uuid = new uuid();
					$privID = $uuid->generate('privileges');
					if(!$uuid->error){
						// Save to Class
						$this->id = $privID;

						// Prep for Database
						$db = new Database();
						$dbID = $db->escape($this->id);
						$dbUserID = $db->escape($userID);
						$dbBrewerID = $db->escape($brewerID);

						// Add to Database
						$db->query("INSERT INTO privileges (id, userID, brewerID) VALUES ('$dbID', '$dbUserID', '$dbBrewerID')");
						if($db->error){
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
							$this->responseCode = $db->responseCode;
						}

						// Close Database Connection
						$db->close();

					}else{
						// UUID Generation Error
						$this->error = true;
						$this->errorMsg = $uuid->errorMsg;
						$this->responseCode = $uuid->responseCode;
					}
				}
			}
		}else{
			// Invalid User
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			$this->responseCode = 500;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 125;
			$errorLog->errorMsg = 'Invalid User';
			$errorLog->badData = $userID;
			$errorLog->filename = 'API / Permissions.class.php';
			$errorLog->write();
		}
	}
	
	public function brewerList($userID){
		// Return
		$brewerIDs = array();
		
		// Which brewer/breweries does this user have privileges for?
		$users = new Users();
		if($users->validate($userID, false)){
			// Prep for Database
			$db = new Database();
			$dbUserID = $db->escape($userID);
			
			// Query Database
			$db->query("SELECT brewerID FROM privileges WHERE userID='$dbUserID'");
			if(!$db->error){
				// Loop through Results
				while($array = $db->resultArray()){
					$brewerIDs[] = $array['brewerID'];
				}
			}
			
			// Close Database Connection
			$db->close();
		}else{
			// Invalid UserID
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			$this->responseCode = 500;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 127;
			$errorLog->errorMsg = 'Invalid User';
			$errorLog->badData = $userID;
			$errorLog->filename = 'API / Permissions.class.php';
			$errorLog->write();
		}
		
		// Return
		return $brewerIDs;
	}
	
	public function userList($brewerID){
		// Return
		$userIDs = array();
		
		// Which users have privileges for this brewer?
		$brewer = new Brewer();
		if($brewer->validate($brewerID, false)){
			// Prep for Database
			$db = new Database();
			$dbBrewerID = $db->escape($brewerID);
			
			// Query Database
			$db->query("SELECT userID FROM privileges WHERE brewerID='$dbBrewerID'");
			if(!$db->error){
				// Loop through Results
				while($array = $db->resultArray()){
					$userIDs[] = $array['userID'];
				}
			}else{
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			
			// Close Database Connection
			$db->close();
		}else{
			// Invalid brewerID
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			$this->responseCode = 500;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 132;
			$errorLog->errorMsg = 'Invalid brewerID';
			$errorLog->badData = $brewerID;
			$errorLog->filename = 'API / Permissions.class.php';
			$errorLog->write();
		}
		
		// Return
		return $userIDs;
	}
	
	public function deleteBrewer($brewerID){
		/*---
		Assume the following for this function
		1) brewerID has been validated
		2) User calling this function has been validated and has permission to perform this action.
		This function does not perform this validation so as to not do it every time.
		---*/
		
		// Prep for Database
		$db = new Database();
		$dbBrewerID = $db->escape($brewerID);
		
		// Delete this brewerID and associated user privileges
		$db->query("DELETE FROM privileges WHERE brewerID='$dbBrewerID'");
		if($db->error){
			// Database Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = $db->responseCode;
		}
		$db->close();
	}
	
	public function deleteUser($userID){
		/*---
		Assume the following for this function
		1) userID has been validated
		2) User calling this function has been validated and has permission to perform this action.
		This function does not perform this validation so as to not do it every time.
		---*/
		
		// Prep for Database
		$db = new Database();
		$dbUserID = $db->escape($userID);
		
		// Delete all brewery privileges for this user
		$db->query("DELETE FROM privileges WHERE userID='$dbUserID'");
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