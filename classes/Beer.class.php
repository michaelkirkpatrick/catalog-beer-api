<?php
class Beer {
	
	// Properties
	public $beerID = '';
	public $brewerID = '';
	public $name = '';
	public $style = '';
	public $description = '';
	public $abv = 0;
	public $ibu = 0;
	public $cbVerified = false;
	public $brewerVerified = false;
	public $lastModified = 0;
	public $proposed = false;
	
	// Error Handling
	public $error = false;
	public $errorMsg = '';
	public $validState = array('brewer_id'=>'', 'name'=>'', 'style'=>'', 'description'=>'', 'abv'=>'', 'ibu'=>'');
	public $validMsg = array('brewer_id'=>'', 'name'=>'', 'style'=>'', 'description'=>'', 'abv'=>'', 'ibu'=>'');
	
	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();
	
	// Verification
	private $isBV = false;	// Is the brewery, brewerVerified?
	private $isCBV = false;	// Is the brewery, catalog.beer verified (cbVerified)?
	
	
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
			$this->errorMsg = $uuid->errorMsg;
			$this->responseCode = $uuid->responseCode;
			
		}
		
		if(!$this->error){
			// Get User Info
			$users = new Users();
			if($users->validate($userID, true)){
				// Check privileges
				$privileges = new Privileges();
				$breweryIDs = $privileges->brewerList($userID);
				
				if($this->isCBV){
					// Brewery is Catalog.beer Verified
					if($users->admin){
						// Beer is Catalog.beer Verified
						$this->cbVerified = true;
						$dbCBV = 1;
						$dbBV = 0;
					}elseif(in_array($this->brewerID, $breweryIDs)){
						// Beer is Brewery Verified
						$this->brewerVerified = true;
						$dbCBV = 0;
						$dbBV = 1;
					}else{
						// General User
						$dbCBV = 0;
						$dbBV = 0;
						$this->proposed = true;
					}
				}elseif($this->isBV){
					// Brewery is Brewer Verified
					if($users->admin){
						// Beer is Catalog.beer Verified
						$this->cbVerified = true;
						$dbCBV = 1;
						$dbBV = 0;
						// *** Stub for 'Notify Brewer' Workflow ***
					}elseif(in_array($this->brewerID, $breweryIDs)){
						// Beer is Brewery Verified
						$this->brewerVerified = true;
						$dbCBV = 0;
						$dbBV = 1;
					}else{
						// General User
						$dbCBV = 0;
						$dbBV = 0;
						$this->proposed = true;
					}
				}else{
					// Neither BV or CBV
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
				if($this->proposed){
					$dbProposed = 1;
				}else{
					$dbProposed = 0;
				}

				// Add to Database
				$db->query("INSERT INTO beer (id, brewerID, name, style, description, abv, ibu, cbVerified, brewerVerified, lastModified, proposed) VALUES ('$dbBeerID', '$dbBrewerID', '$dbName', '$dbStyle', '$dbDescription', '$dbABV', '$dbIBU', '$dbCBV', '$dbBV', '$dbLastModified', '$dbProposed')");
				if(!$db->error){
					$this->responseCode = 201;
					$responseHeaderString = 'Location: https://';
					if(ENVIRONMENT == 'staging'){
						$responseHeaderString .= 'staging.';
					}
					$this->responseHeader = $responseHeaderString . 'catalog.beer/beer/' . $this->beerID;
					if($this->proposed){
						// *** Stub for 'Proposed' Workflow ***
					}
				}else{
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
				$db->close();
			}else{
				// User Validation Error
				$this->error = true;
				$this->errorMsg = $users->errorMsg;
				$this->responseCode = $users->responseCode;
			}
		}
	}
	
	private function validateBrewery(){
		// Validate Brewer ID
		$brewer = new Brewer();
		if($brewer->validate($this->brewerID, true)){
			// Valid Brewer
			$this->brewerID = $brewer->brewerID;
			$this->isBV = $brewer->brewerVerified;
			$this->isCBV = $brewer->cbVerified;
			$this->validState['brewer_id'] = 'valid';
		}else{
			// Invalid Brewer
			$this->error = true;
			$this->validState['brewer_id'] = 'invalid';
			$this->responseCode = 400;
			$this->validMsg['brewer_id'] = $brewer->errorMsg;
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
				$this->responseCode = 400;
				
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
			$this->responseCode = 400;
			
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
				$this->responseCode = 400;
				
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
			$this->responseCode = 400;
			
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
				$this->responseCode = 400;
				
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
				$this->responseCode = 400;
				
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
			$this->responseCode = 400;

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
					$this->responseCode = 400;

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
				$this->responseCode = 400;

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
			$db->query("SELECT brewerID, name, style, description, abv, ibu, cbVerified, brewerVerified, lastModified, proposed FROM beer WHERE id='$dbBeerID'");
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
						if($array['proposed']){
							$this->proposed = true;
						}else{
							$this->proposed = false;
						}
					}
				}elseif($db->result->num_rows > 1){
					// Duplicate Results
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					$this->responseCode = 500;
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 136;
					$errorLog->errorMsg = 'Duplicate beerID\'s found';
					$errorLog->badData = $beerID;
					$errorLog->filename = 'API / Beer.class.php';
					$errorLog->write();
				}else{
					// No Results Found
					$this->error = true;
					$this->errorMsg = "Sorry, we couldn't find a beer with the beer_id you provided.";
					$this->responseCode = 404;
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 137;
					$errorLog->errorMsg = 'beerID Not Found';
					$errorLog->badData = $beerID;
					$errorLog->filename = 'API / Beer.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Missing beerID
			$this->error = true;
			$this->errorMsg = 'Whoops, we seem to be missing the beer_id for the beer. Please check your request and try again.';
			$this->responseCode = 400;
			
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
					$this->responseCode = 400;

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
					$this->responseCode = 400;

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
				$this->responseCode = 400;

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
			$this->responseCode = 400;
			
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
			$db->query("SELECT id, name FROM beer WHERE proposed=0 ORDER BY name LIMIT $offset, $count");
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
				$this->responseCode = $db->responseCode;
			}
			$db->close();
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
		$db->query("SELECT COUNT('id') AS numBeers FROM beer WHERE proposed=0");
		if(!$db->error){
			$array = $db->resultArray();
			return intval($array['numBeers']);
		}else{
			// Query Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = $db->responseCode;
		}
		$db->close();
		
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
				$db->query("SELECT id, name, style FROM beer WHERE brewerID='$dbBrewerID' AND proposed=0 ORDER BY name");
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
				$db->close();
			}else{
				// Invalid BrewerID
				$this->error = true;
				$this->errorMsg = $brewer->errorMsg;
				$this->responseCode = $brewer->responseCode;
			}
		}else{
			// Missing Brewer ID
			$this->error = true;
			$this->errorMsg = 'Sorry, we seem to be missing the brewer_id. Please check your request and try again.';
			$this->responseCode = 400;
			
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
			$this->responseCode = $db->responseCode;
		}
		$db->close();
		
		// Return
		return $lastModified;
	}
	
	public function lastModified($beerID){
		// Return
		$lastModified = 0;
		
		if(!empty($beerID)){
			if($this->validate($beerID, true)){
				$lastModified = $this->lastModified;
			}
		}else{
			// Missing BrewerID
			$this->error = true;
			$this->errorMsg = 'Missing beerID';
			$this->responseCode = 400;
			
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
	
	public function proposedAdd($beerID, $brewerID){
		// Are there any users with Brewery privileges?
		$privileges = new Privileges();
		$userIDs = $privileges->userList($brewerID);
		if(!$privileges->error){
			$users = new Users();
			if(empty($userIDs)){
				// No users with Brewery privileges, email Catalog.beer Admin
				$emails = $users->getAdminEmails();
				if(!$users->error){
					// POST https://api.catalog.beer/beer/{beer_id}/approve/{authorization_code}
					// POST https://api.catalog.beer/beer/{beer_id}/deny/{authorization_code}
					
					
					// Send Email to Catalog.beer Admin
					echo $users->errorMsg;
				}else{
					// Error Retreiving email addresses
					$this->error = true;
					$this->errorMsg = $users->errorMsg;
					$this->responseCode = $users->responseCode;
				}
			}
		}else{
			$this->error = true;
			$this->errorMsg = $privileges->errorMsg;
			$this->responseCode = $privileges->responseCode;
		}
	}
	
	public function deleteBeer($beerID, $userID){
		if($this->validate($beerID, false)){
			$users = new Users();
			$users->validate($userID, true);
			if($users->admin){
				// Delete Beer
				$db = new Database();
				$dbBeerID = $db->escape($beerID);
				$db->query("DELETE FROM beer WHERE id='$dbBeerID'");
				if($db->error){
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
				$db->close();
			}else{
				// Not an Admin - Not Allowed to Delete
				$this->error = true;
				$this->errorMsg = 'Sorry, you do not have permission to delete a beer.';
				$this->responseCode = 403;
			}
		}
	}
	
	public function deleteBrewerBeers($brewerID){
		/*---
		Assume the following for this function
		1) Brewer has been validated
		2) User has been validated and has permission to perform this action
		This function does not perform this validation so as to not do it every time.
		---*/
		
		// Prep for Database
		$db = new Database();
		$dbBrewerID = $db->escape($brewerID);
		
		// Delete Beers
		$db->query("DELETE FROM beer WHERE brewerID='$dbBrewerID'");
		if($db->error){
			// Database Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = $db->responseCode;
		}
		$db->close();
	}
	
	public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
		/*---
		{METHOD} https://api.catalog.beer/beer/{function}
		{METHOD} https://api.catalog.beer/beer/{id}/{function}
		
		GET https://api.catalog.beer/beer
		GET https://api.catalog.beer/beer/count
		GET https://api.catalog.beer/beer/last-modified
		GET https://api.catalog.beer/beer/{beer_id}
		GET https://api.catalog.beer/beer/{beer_id}/last-modified
		
		POST https://api.catalog.beer/beer
		
		DELETE https://api.catalog.beer/beer/{beer_id}
		---*/
		switch($method){
			case 'GET':
				if(!empty($id) && empty($function)){
					// GET https://api.catalog.beer/beer/{beer_id}
					// Validate ID
					if($this->validate($id, true)){
						// Validate Brewery
						$brewer = new Brewer();
						if($brewer->validate($this->brewerID, true)){
							// Beer Info
							$this->json['id'] = $this->beerID;
							$this->json['object'] = 'beer';
							$this->json['name'] = $this->name;
							$this->json['style'] = $this->style;
							$this->json['description'] = $this->description;
							$this->json['abv'] = $this->abv;
							$this->json['ibu'] = $this->ibu;
							$this->json['cb_verified'] = $this->cbVerified;
							$this->json['brewer_verified'] = $this->brewerVerified;

							// Brewer Info
							$this->json['brewer']['id'] = $brewer->brewerID;
							$this->json['brewer']['object'] = 'brewer';
							$this->json['brewer']['name'] = $brewer->name;
							$this->json['brewer']['description'] = $brewer->description;
							$this->json['brewer']['short_description'] = $brewer->shortDescription;
							$this->json['brewer']['url'] = $brewer->url;
							$this->json['brewer']['cb_verified'] = $brewer->cbVerified;
							$this->json['brewer']['brewer_verified'] = $brewer->brewerVerified;
							$this->json['brewer']['facebook_url'] = $brewer->facebookURL;
							$this->json['brewer']['twitter_url'] = $brewer->twitterURL;
							$this->json['brewer']['instagram_url'] = $brewer->instagramURL;
						}else{
							// Brewer Validation Error
							$this->responseCode = $brewer->responseCode;
							$this->json['error'] = true;
							$this->json['error_msg'] = $brewer->errorMsg;
						}
					}else{
						// Beer Validation Error
						$this->json['error'] = true;
						$this->json['error_msg'] = 'Sorry, we don\'t have any beer with that beer_id. Please check your request and try again.';
					}
				}else{
					if(!empty($function)){
						switch($function){
							case 'count':
								// GET https://api.catalog.beer/beer/count
								$numBeers = $this->countBeers();
								if(!$this->error){
									$this->json['object'] = 'count';
									$this->json['url'] = '/beer/count';
									$this->json['value'] = $numBeers;
								}else{
									$this->json['error'] = true;
									$this->json['error_msg'] = $this->errorMsg;
								}
								break;
							case 'last-modified':
								if(!empty($id)){
									// GET https://api.catalog.beer/beer/{beer_id}/last-modified
									// Individual Brewer
									$lastModified = $this->lastModified($id);
									if(!$this->error){
										$this->json['object'] = 'timestamp';
										$this->json['url'] = '/beer/' . $id . '/last-modified';
										$this->json['beer_id'] = $id;
										$this->json['last_modified'] = $lastModified;
									}else{
										$this->json['error'] = true;
										$this->json['error_msg'] = $this->errorMsg;
									}
								}else{
									// GET https://api.catalog.beer/beer/last-modified
									// All Brewers
									$latestModified = $this->latestModified();
									if(!$this->error){
										$this->json['object'] = 'timestamp';
										$this->json['url'] = '/beer/last-modified';
										$this->json['last_modified'] = $latestModified;
									}else{
										$this->json['error'] = true;
										$this->json['error_msg'] = $this->errorMsg;
									}
								}
								break;
							default:
								// Invalid Function
								$this->responseCode = 404;
								$this->json['error'] = true;
								$this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 70;
								$errorLog->errorMsg = 'Invalid Function (/beer)';
								$errorLog->badData = $function;
								$errorLog->filename = 'API / Beer.class.php';
								$errorLog->write();
						}
					}else{
						// GET https://api.catalog.beer/beer
						// List Beers
						// Defaults
						$cursor = base64_encode('0');	// Page
						$count = 500;

						// Get Variables
						if(isset($_GET['cursor'])){
							$cursor = $_GET['cursor'];
						}
						if(isset($_GET['count'])){
							$count = $_GET['count'];
						}

						// Query
						$beerArray = $this->getBeers($cursor, $count);
						if(!$this->error){
							// Start JSON
							$this->json['object'] = 'list';
							$this->json['url'] = '/beer';

							// Next Cursor
							$nextCursor = $this->nextCursor($cursor, $count);
							if(!empty($nextCursor)){
								$this->json['has_more'] = true;
								$this->json['next_cursor'] = $nextCursor;
							}else{
								$this->json['has_more'] = false;
							}

							// Append Data
							$this->json['data'] = $beerArray;
						}else{
							$this->json['error'] = true;
							$this->json['error_msg'] = $this->errorMsg;
						}
					}
				}
				break;
			case 'POST':
				// POST https://api.catalog.beer/beer
				// Handle Empty Fields
				if(empty($data->brewer_id)){$data->brewer_id = '';}
				if(empty($data->name)){$data->name = '';}
				if(empty($data->style)){$data->style = '';}
				if(empty($data->description)){$data->description = '';}
				if(empty($data->abv)){$data->abv = '';}
				if(empty($data->ibu)){$data->ibu = '';}
				
				// Validate API Key for userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);
				
				// Add Beer
				$this->add($data->brewer_id, $data->name, $data->style, $data->description, $data->abv, $data->ibu, $apiKeys->userID);
				if(!$this->error){
					$this->json['id'] = $this->beerID;
					$this->json['object'] = 'beer';
					$this->json['name'] = $this->name;
					$this->json['style'] = $this->style;
					$this->json['description'] = $this->description;
					$this->json['abv'] = floatval($this->abv);
					$this->json['ibu'] = intval($this->ibu);
					$this->json['cb_verified'] = $this->cbVerified;
					$this->json['brewer_verified'] = $this->brewerVerified;
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'DELETE':
				// DELETE https://api.catalog.beer/beer/{{location_id}}
				// Get userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Delete Location
				$this->deleteBeer($id, $apiKeys->userID);
				if(!$this->error){
					// Successful Delete
					$this->responseCode = 200;
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
				}
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->responseCode = 405;
				$this->json['error'] = true;
				$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
				$this->responseHeader = 'Allow: GET, POST, DELETE';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 71;
				$errorLog->errorMsg = 'Invalid Method (/beer)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
		}
	}
}
?>