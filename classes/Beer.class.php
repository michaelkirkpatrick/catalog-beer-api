<?php
class Beer {
	
	public $beerID = '';
	public $brewerID = '';
	public $name = '';
	public $style = '';
	public $description = '';	// Optional
	public $abv = 0;
	public $ibu = 0;					// Optional
	public $cbVerified = false;
	public $brewerVerified = false;
	public $lastModified = 0;
	
	public $error = false;
	public $errorMsg = '';
	public $validState = array('brewer_id'=>'', 'name'=>'', 'style'=>'', 'description'=>'', 'abv'=>'', 'ibu'=>'');
	public $validMsg = array('brewer_id'=>'', 'name'=>'', 'style'=>'', 'description'=>'', 'abv'=>'', 'ibu'=>'');
	
	public function add($brewerID, $name, $style, $description, $abv, $ibu, $userID){
		// Save to Class
		$this->brewerID = $brewerID;
		$this->name = $name;
		$this->style = $style;
		$this->description = $description;
		$this->abv = $abv;
		$this->ibu = $ibu;
		
		// Validate Fields
		$this->validateBrewery();
		$this->validateName();
		$this->validateStyle();
		$this->validateDescription();
		$this->validateABV();
		$this->validateIBU();
		
		// Generate UUID
		$uuid = new uuid();
		$var = $uuid->generate('beer');
		if(!$uuid->error){
			// Save to Class
			$this->beerID = $var;
		}else{
			// UUID Generation Error
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
		}
		
		if(!$this->error){
			// Get User Info
			$users = new Users();
			if($users->validate($userID, true)){
				if($users->admin){
						// Catalog.beer Verified
						$this->cbVerified = true;
						$dbCBV = 1;
						$dbBV = 0;
					}else{
						// Not Catalog.beer Verified
						$dbCBV = 0;
						$dbBV = 0;
					}

				// Prep for Database
				$db = new Database();
				$dbBeerID = $db->escape($this->beerID);
				$dbBrewerID = $db->escape($this->brewerID);
				$dbName = $db->escape($this->name);
				$dbStyle = $db->escape($this->style);
				$dbDescription = $db->escape($this->description);
				$dbABV = $db->escape($this->abv);
				$dbIBU = $db->escape($this->ibu);
				$dbLastModified = $db->escape(time());

				$db->query("INSERT INTO beer (id, brewerID, name, style, description, abv, ibu, cbVerified, brewerVerified, lastModified) VALUES ('$dbBeerID', '$dbBrewerID', '$dbName', '$dbStyle', '$dbDescription', '$dbABV', '$dbIBU', '$dbCBV', '$dbBV', '$dbLastModified')");
				if($db->error){
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
				}
			}else{
				// User Validation Error
				$this->error = true;
				$this->errorMsg = $users->errorMsg;
			}
		}
	}
	
	private function validateBrewery(){
		// Validate Brewer ID
		$brewer = new Brewer();
		if($brewer->validate($this->brewerID, true)){
			// Valid Brewer
			$this->brewerID = $brewer->brewerID;
			$this->validState['brewer_id'] = 'valid';
		}else{
			// Invalid Brewer
			$this->error = true;
			$this->validState['brewer_id'] = 'invalid';
			
			// Trim, Empty?
			$this->brewerID = trim($brewer->brewerID);
			if(empty($this->brewerID)){
				// Empty brewerID
				$this->validMsg['brewer_id'] = 'Who brews this beer? We\'re missing the brewer_id';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 14;
				$errorLog->errorMsg = 'Missing brewerID';
				$errorLog->badData = '';
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}else{
				if(!empty($brewerID->errorMsg)){
					// Invalid from $brewer->validate()
					$this->validMsg['brewer_id'] = $brewerID->errorMsg;
				}else{
					// Not found
					$this->validMsg['brewer_id'] = 'Sorry, we don\'t have any breweries with that brewer_id. Please check your request and try again.';
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 13;
					$errorLog->errorMsg = 'Invalid brewerID';
					$errorLog->badData = $this->brewerID;
					$errorLog->filename = 'API / Beer.class.php';
					$errorLog->write();
				}
			}
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 11;
			$errorLog->errorMsg = 'Invalid Brewer ID';
			$errorLog->badData = $this->brewerID;
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
	}
	
	private function validateName(){
		// Trim
		$this->name = trim($this->name);
		
		if(!empty($this->name)){
			if(strlen($this->name) <= 255){
				// Valid Name
				$this->validState['name'] = 'valid';
			}else{
				// Name Too Long
				$this->error = true;
				$this->validState['name'] = 'invalid';
				$this->validMsg['name'] = 'We hate to say it but your beer name is too long for our database. Beer names are limited to 255 bytes. Any chance you can shorten it?';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 15;
				$errorLog->errorMsg = 'Beer name too long (>255 Characters)';
				$errorLog->badData = $this->name;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Name
			$this->error = true;
			$this->validState['name'] = 'invalid';
			$this->validMsg['name'] = 'What\'s the name of this beer? We seem to be missing the name.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 12;
			$errorLog->errorMsg = 'Missing Beer Name';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
	}
	
	private function validateStyle(){
		// Trim
		$this->style = trim($this->style);
		
		if(!empty($this->style)){
			if(strlen($this->style) <= 255){
				// Valid Style
				$this->validState['style'] = 'valid';
			}else{
				// Style Too Long
				$this->error = true;
				$this->validState['style'] = 'invalid';
				$this->validMsg['style'] = 'We hate to say it but this beer style is too long for our database. Style names are limited to 255 bytes. Any chance you can shorten it?';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 16;
				$errorLog->errorMsg = 'Beer style name too long (>255 Characters)';
				$errorLog->badData = $this->style;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Name
			$this->error = true;
			$this->validState['style'] = 'invalid';
			$this->validMsg['style'] = 'What\'s the style of this beer? We seem to be missing its style.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 17;
			$errorLog->errorMsg = 'Missing Beer Style';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
	}
	
	private function validateDescription(){
		// Trim
		$this->description = trim($this->description);
		
		if(!empty($this->description)){
			if(strlen($this->description) <= 65536){
				// Valid Style
				$this->validState['description'] = 'valid';
			}else{
				// Description Too Long
				$this->error = true;
				$this->validState['description'] = 'invalid';
				$this->validMsg['description'] = 'We hate to say it but this beer description is too long for our database. Descriptions are limited to 65,536 bytes. Any chance you can shorten it?';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 18;
				$errorLog->errorMsg = 'Beer description too long (>65536 Characters)';
				$errorLog->badData = $this->description;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}
	}
	
	private function validateABV(){
		// Validate ABV
		if(is_numeric($this->abv)){
			// It's a number
			$this->abv = round($this->abv, 1);

			// Between Limits?
			if($this->abv >= 0 && $this->abv < 100){
				// Success
				$this->validState['abv'] = 'valid';
			}else{
				// Not within range (0-100)
				$this->error = true;
				$this->validState['abv'] = 'invalid';
				$this->validMsg['abv'] = 'ABV must be between 0 and 99.9.';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 22;
				$errorLog->errorMsg = $this->validMsg['abv'];
				$errorLog->badData = $this->abv;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			$this->error = true;
			$this->validState['abv'] = 'invalid';
			$this->validMsg['abv'] = 'The number you entered appears to be non-numeric. Please enter a number for the ABV percentage.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 23;
			$errorLog->errorMsg = $this->validMsg['abv'];
			$errorLog->badData = $this->abv;
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
	}
	
	private function validateIBU(){
		// Validate IBU
		if(!empty($this->ibu)){
			// Save as integer
			$this->ibu = intval($this->ibu);
			
			// Process
			if(is_int($this->ibu)){
				if($this->ibu > 0 && $this->ibu <= 9999){
					$this->validState['ibu'] = 'valid';
				}else{
					$this->error = true;
					$this->validMsg['ibu'] = 'The range for IBU values we can accept is (0, 9999].';
					$this->validState['ibu'] = 'invalid';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 24;
					$errorLog->errorMsg = $this->validMsg['ibu'];
					$errorLog->badData = $this->ibu;
					$errorLog->filename = 'API / Beer.class.php';
					$errorLog->write();
				}
			}else{
				$this->error = true;
				$this->validMsg['ibu'] = 'Please enter an integer value for IBU\'s.';
				$this->validState['ibu'] = 'invalid';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 25;
				$errorLog->errorMsg = "Not an integer";
				$errorLog->badData = $this->ibu . " Type: " . gettype($this->ibu);
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			// Empty, IBU not provided, input zero
			$this->ibu = 0;
		}
	}
	
	// Validate Beer
	public function validate($beerID, $saveToClass){
		// Valid
		$valid = false;
		
		// Trim
		$beerID = trim($beerID);
		
		if(!empty($beerID)){
			// Prep for Database
			$db = new Database();
			$dbBeerID = $db->escape($beerID);
			$db->query("SELECT brewerID, name, style, description, abv, ibu, cbVerified, brewerVerified, lastModified FROM beer WHERE id='$dbBeerID'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid Result
					$valid = true;
					
					if($saveToClass){
						$array = $db->resultArray();
						
						$this->beerID = $beerID;
						$this->brewerID = $array['brewerID'];
						$this->name = stripcslashes($array['name']);
						$this->style = stripcslashes($array['style']);
						$this->description = stripcslashes($array['description']);
						$this->abv = floatval($array['abv']);
						$this->ibu = intval($array['ibu']);
						$this->lastModified = intval($array['lastModified']);
						if($array['cbVerified']){
							$this->cbVerified = true;
						}else{
							$this->cbVerified = false;
						}
						if($array['brewerVerified']){
							$this->brewerVerified = true;
						}else{
							$this->brewerVerified = false;
						}
					}
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing beerID
			$this->error = true;
			$this->errorMsg = 'Whoops, we seem to be missing the beer_id for the beer. Please check your request and try again.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 52;
			$errorLog->errorMsg = 'Missing beerID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
		
		return $valid;
	}
	
	// Get Beer IDs
	public function getBeers($cursor, $count){
		// Return Array
		$beerArray = array();
		
		// Prep Variables
		$offset = intval(base64_decode($cursor));
		$count = intval($count);
		
		if(is_int($offset) && $offset >= 0){
			if(is_int($count)){
				// Within Limits?
				$numBeers = $this->countBeers();
				if($offset > $numBeers){
					// Outside Range
					$this->error = true;
					$this->errorMsg = 'Sorry, the cursor value you supplied is outside our data range.';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 96;
					$errorLog->errorMsg = 'Offset value outside range';
					$errorLog->badData = "Offset: $offset / numBeers: $numBeers";
					$errorLog->filename = 'API / Beer.class.php';
					$errorLog->write();
				}
				
				if($count > 1000000 || $count < 1){
					// Outside Range
					$this->error = true;
					$this->errorMsg = 'Sorry, the count value you specified is outside our acceptable range. The range we will accept is [1, 1,000,000].';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 97;
					$errorLog->errorMsg = 'Count value outside range';
					$errorLog->badData = $count;
					$errorLog->filename = 'API / Beer.class.php';
					$errorLog->write();
				}
			}else{
				// Not an integer offset
				$this->error = true;
				$this->errorMsg = 'Sorry, the count value you supplied is invalid. Please ensure you are sending an integer value.';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 95;
				$errorLog->errorMsg = 'Non-integer count value';
				$errorLog->badData = $count;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			// Not an integer offset
			$this->error = true;
			$this->errorMsg = 'Sorry, the cursor value you supplied is invalid.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 94;
			$errorLog->errorMsg = 'Invalid cursor value';
			$errorLog->badData = $offset;
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
		
		if(!$this->error){
			// Prep for Database
			$db = new Database();
			$brewer = new Brewer();
			$db->query("SELECT id, name FROM beer ORDER BY name LIMIT $offset, $count");
			if(!$db->error){
				while($array = $db->resultArray()){
					// Brewer Info
					$beerInfo = array('id'=>$array['id'], 'name'=>$array['name']);
					$beerArray[] = $beerInfo;
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}
		
		// Return
		return $beerArray;
	}
	
	public function nextCursor($cursor, $count){
		// Number of Beers
		$numBeers = $this->countBeers();
		
		// Next Cursor
		$offset = base64_decode($cursor);
		$nextCursor = $offset + $count;
		
		if($nextCursor <= $numBeers){
			// Return Next Page
			return base64_encode($nextCursor);
		}else{
			return '';
		}
	}
	
	// Number of Beers
	public function countBeers(){
		// Return
		$count = 0;
		
		// Query Database
		$db = new Database();
		$db->query("SELECT COUNT('id') AS numBeers FROM beer");
		if(!$db->error){
			$array = $db->resultArray();
			return $array['numBeers'];
		}else{
			// Query Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
		}
		
		return $count;
	}
	
	// Beers by Brewer
	public function brewerBeers($brewerID){
		// Return Array
		$beerInfo = array();
		
		if(!empty($brewerID)){
			// Validate Brewer ID
			$brewer = new Brewer();
			if($brewer->validate($brewerID, true)){
				// Start Array
				$beerInfo['object'] = 'list';
				$beerInfo['url'] = '/brewer/' . $brewerID . '/beer';
				$beerInfo['has_more'] = false;
				$beerInfo['brewer']['id'] = $brewer->brewerID;
				$beerInfo['brewer']['object'] = 'brewer';
				$beerInfo['brewer']['name'] = $brewer->name;
				$beerInfo['brewer']['description'] = $brewer->description;
				$beerInfo['brewer']['short_description'] = $brewer->shortDescription;
				$beerInfo['brewer']['url'] = $brewer->url;
				$beerInfo['brewer']['cb_verified'] = $brewer->cbVerified;
				$beerInfo['brewer']['brewer_verified'] = $brewer->brewerVerified;
				$beerInfo['brewer']['facebook_url'] = $brewer->facebookURL;
				$beerInfo['brewer']['twitter_url'] = $brewer->twitterURL;
				$beerInfo['brewer']['instagram_url'] = $brewer->instagramURL;
				$beerInfo['data'] = array();
				
				// Prep for Query
				$db = new Database();
				$dbBrewerID = $db->escape($brewerID);
				$db->query("SELECT id, name, style FROM beer WHERE brewerID='$dbBrewerID' ORDER BY name");
				if(!$db->error){
					if($db->result->num_rows >= 1){
						// Has Beers associated with it
						$i=0;
						while($array = $db->resultArray()){
							$beerInfo['data'][$i]['id'] = $array['id'];
							$beerInfo['data'][$i]['name'] = $array['name'];
							$beerInfo['data'][$i]['style'] = $array['style'];
							$i++;
						}
					}
				}
			}else{
				// Invalid BrewerID
				$this->error = true;
				$this->errorMsg = 'Sorry, we don\'t have any brewers with that brewer_id. Please check your request and try again.';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 47;
				$errorLog->errorMsg = 'Invalid brewerID';
				$errorLog->badData = '';
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Brewer ID
			$this->error = true;
			$this->errorMsg = 'Sorry, we seem to be missing the brewer_id. Please check your request and try again.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 46;
			$errorLog->errorMsg = 'Missing brewerID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
		
		// Return
		return $beerInfo;
	}
	
	// Last Modified
	public function latestModified(){
		// Return
		$lastModified = 0;
		
		// Connect to Database
		$db = new Database();
		$db->query("SELECT MAX(lastModified) AS lastModified FROM beer");
		if(!$db->error){
			// Save Last Modified
			$lastModified = intval($db->singleResult('lastModified'));
		}else{
			// Query Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
		}
		
		// Return
		return $lastModified;
	}
	
	public function lastModified($beerID){
		// Return
		$lastModified = 0;
		
		if(!empty($beerID)){
			if($this->validate($beerID, true)){
				$lastModified = $this->lastModified;
			}else{
				// Invalid Brewer
				$this->error = true;
				$this->errorMsg = 'Missing beerID';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 104;
				$errorLog->errorMsg = 'Invalid beerID';
				$errorLog->badData = $beerID;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing BrewerID
			$this->error = true;
			$this->errorMsg = 'Missing beerID';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 102;
			$errorLog->errorMsg = 'Missing beerID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Beer.class.php';
			$errorLog->write();
		}
		
		// Return
		return $lastModified;
	}
}
?>