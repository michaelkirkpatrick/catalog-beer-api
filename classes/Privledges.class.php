<?php
class Privledges {
	
	public $id = '';
	public $userID = '';
	public $brewerID = '';
	
	public $error = false;
	public $errorMsg = '';
	
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
				// Does user already have privledges for this brewerID?
				$brewerIDs = $this->brewerList($userID);
				
				if(!in_array($brewerID, $brewerIDs)){
					// userID not yet associated with this brewerID
					// Create UUID
					$uuid = new uuid();
					$privID = $uuid->generate('privledges');
					if(!$uuid->error){
						// Save to Class
						$this->id = $privID;

						// Prep for Database
						$db = new Database();
						$dbID = $db->escape($this->id);
						$dbUserID = $db->escape($userID);
						$dbBrewerID = $db->escape($brewerID);

						// Add to Database
						$db->query("INSERT INTO privledges (id, userID, brewerID) VALUES ('$dbID', '$dbUserID', '$dbBrewerID')");
						if($db->error){
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
						}

						// Close Database Connection
						$db->close();

					}else{
						// UUID Generation Error
						$this->error = true;
						$this->errorMsg = $uuid->errorMsg;
					}
				}
			}
		}else{
			// Invalid User
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			
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
		
		// Which brewer/breweries does this user have privledges for?
		$users = new Users();
		if($users->validate($userID, false)){
			// Prep for Database
			$db = new Database();
			$dbUserID = $db->escape($userID);
			
			// Query Database
			$db->query("SELECT brewerID FROM privledges WHERE userID='$dbUserID'");
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
}
?>