<?php
class Brewer {
	
	// Properties
	public $brewerID = '';
	public $name = '';
	public $description = ''; 			// Optional
	public $shortDescription = '';	// Optional
	public $url = '';								// Optional
	public $cbVerified = false;
	public $brewerVerified = false;
	public $facebookURL = '';				// Optional
	public $twitterURL = '';				// Optional
	public $instagramURL = '';			// Optional
	public $lastModified = 0;
	
	public $error = false;
	public $errorMsg = '';
	public $validState = array('name'=>'', 'url'=>'', 'description'=>'', 'short_description'=>'', 'facebook_url'=>'', 'twitter_url'=>'', 'instagram_url'=>'');
	public $validMsg = array('name'=>'', 'url'=>'', 'description'=>'', 'short_description'=>'', 'facebook_url'=>'', 'twitter_url'=>'', 'instagram_url'=>'');
	
	// Add Brewer
	public function add($name, $description, $shortDescription, $url, $facebookURL, $twitterURL, $instagramURL, $userID){		
		// Validate Name
		$this->name = $name;
		$this->validateName();
		
		// Validate URLs
		$this->url = $this->validateURL($url, 'url');
		$this->facebookURL = $this->validateURL($facebookURL, 'facebook_url');
		$this->twitterURL = $this->validateURL($twitterURL, 'twitter_url');
		$this->instagramURL = $this->validateURL($instagramURL, 'instagram_url');
		
		// Validate Social URLs
		if(!empty($this->facebookURL)){
			if(substr($this->facebookURL, 0, 24) != 'https://www.facebook.com/'){
				// Invalid Facebook URL
				$this->error = true;
				$this->validState['facebook_url'] = 'invalid';
				$this->validMsg['facebook_url'] = 'We were expecting the Facebook URL to start with "https://www.facebook.com/". Please double check the Facebook URL you submitted.';
			}
		}
		if(!empty($this->twitterURL)){
			if(substr($this->twitterURL, 0, 19) != 'https://twitter.com/'){
				// Invalid Twitter URL
				$this->error = true;
				$this->validState['twitter_url'] = 'invalid';
				$this->validMsg['twitter_url'] = 'We were expecting the Twitter URL to start with "https://twitter.com/". Please double check the Twitter URL you submitted.';
			}
		}
		if(!empty($this->instagramURL)){
			if(substr($this->instagramURL, 0, 25) != 'https://www.instagram.com/'){
				// Invalid Instagram URL
				$this->error = true;
				$this->validState['instagram_url'] = 'invalid';
				$this->validMsg['instagram_url'] = 'We were expecting the Instagram URL to start with "https://www.instagram.com/". Please double check the Instagram URL you submitted.';
			}
		}
		
		// Validate Description
		$this->description = trim($description);
		$this->validateDescription();
		
		// Validate Short Description
		$this->shortDescription = $shortDescription;
		$this->validateShortDescription();
		
		// Generate UUID
		$uuid = new uuid();
		$this->brewerID = $uuid->generate('brewer');
		if($uuid->error){
			// UUID Generation Error
			$this->error = true;
			$this->errorMsg = $uuid->errorMsg;
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
				$dbBrewerID = $db->escape($this->brewerID);
				$dbName = $db->escape($this->name);
				$dbDescription = $db->escape($this->description);
				$dbShortDescription = $db->escape($this->shortDescription);
				$dbURL = $db->escape($this->url);
				$dbFacebookURL = $db->escape($this->facebookURL);
				$dbTwitterURL = $db->escape($this->twitterURL);
				$dbInstagramURL = $db->escape($this->instagramURL);
				$dbLastModified = $db->escape(time());
				
				// Query
				$db->query("INSERT INTO brewer (id, name, description, shortDescription, url, cbVerified, brewerVerified, facebookURL, twitterURL, instagramURL, lastModified) VALUES ('$dbBrewerID', '$dbName', '$dbDescription', '$dbShortDescription', '$dbURL', '$dbCBV', '$dbBV', '$dbFacebookURL', '$dbTwitterURL', '$dbInstagramURL', '$dbLastModified')");
				if($db->error){
					// Query Error
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
	
	public function update($name, $description, $shortDescription, $url, $facebookURL, $twitterURL, $instagramURL, $userID, $brewerID){
		// Validate BrewerID
		if($this->validate($brewerID, false)){
			// Save BrewerID
			$this->brewerID = $brewerID;
		}else{
			// Invalid Brewer ID
			$this->error = true;
			$this->errorMsg = 'Sorry, the brewerID you provided appears to be invalid. Please double check that you are submitted a valid brewerID.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 105;
			$errorLog->errorMsg = 'Invalid brewerID (update brewer)';
			$errorLog->badData = $brewerID;
			$errorLog->filename = 'API / Brewer.class.php';
			$errorLog->write();
		}
		
		// SQL String
		$sqlString = array();
		$db = new Database();
		
		// Validate Name
		if(!empty($name)){
			$this->name = $name;
			$this->validateName();
			if(!$this->error){
				// Add to SQL String
				$dbName = $db->escape($this->name);
				$sqlString[] = "name='$dbName'";
			}
		}
		
		// Validate URL
		if(!empty($url)){
			$this->url = $this->validateURL($url, 'url');
			if(!$this->error){
				// Add to SQL String
				$dbURL = $db->escape($this->url);
				$sqlString[] = "url='$dbURL'";
			}
		}
		
		// Validate Facebook URL
		if(!empty($facebookURL)){
			$this->facebookURL = $this->validateURL($facebookURL, 'facebook_url');
			if(!$this->error){
				if(!substr($this->facebookURL, 0, 24) == 'https://www.facebook.com/'){
					// Invalid Facebook URL
					$this->error = true;
					$this->validState['facebook_url'] = 'invalid';
					$this->validMsg['facebook_url'] = 'We were expecting the Facebook URL to start with "https://www.facebook.com/". Please double check the Facebook URL you submitted.';
				}else{
					// Add to SQL String
					$dbFacebookURL = $db->escape($this->facebookURL);
					$sqlString[] = "facebookURL='$dbFacebookURL'";
				}
			}
		}
		
		// Validate Twitter URL
		if(!empty($twitterURL)){
			$this->twitterURL = $this->validateURL($twitterURL, 'twitter_url');
			if(!$this->error){
				if(!substr($this->twitterURL, 0, 19) == 'https://twitter.com/'){
					// Invalid Twitter URL
					$this->error = true;
					$this->validState['twitter_url'] = 'invalid';
					$this->validMsg['twitter_url'] = 'We were expecting the Twitter URL to start with "https://twitter.com/". Please double check the Twitter URL you submitted.';
				}else{
					// Add to SQL String
					$dbTwitterURL = $db->escape($this->twitterURL);
					$sqlString[] = "twitterURL='$dbTwitterURL'";
				}
			}
		}
		
		// Validate Instagram URL
		if(!empty($instagramURL)){
			$this->instagramURL = $this->validateURL($instagramURL, 'instagram_url');
			if(!$this->error){
				if(!substr($this->instagramURL, 0, 25) == 'https://www.instagram.com/'){
					// Invalid Instagram URL
					$this->error = true;
					$this->validState['instagram_url'] = 'invalid';
					$this->validMsg['instagram_url'] = 'We were expecting the Instagram URL to start with "https://www.instagram.com/". Please double check the Instagram URL you submitted.';
				}else{
					// Add to SQL String
					$dbInstagramURL = $db->escape($this->instagramURL);
					$sqlString[] = "instagramURL='$dbInstagramURL'";
				}
			}
		}
		
		// Validate Description
		if(!empty($description)){
			$this->description = trim($description);
			$this->validateDescription();
			if(!$this->error){
				// Add to SQL String
				$dbDescription = $db->escape($this->description);
				$sqlString[] = "description='$dbDescription'";
			}
		}
		
		// Validate Short Description
		if(!empty($shortDescription)){
			$this->shortDescription = $shortDescription;
			$this->validateShortDescription();
			if(!$this->error){
				// Add to SQL String
				$dbShortDescription = $db->escape($this->shortDescription);
				$sqlString[] = "shortDescription='$dbShortDescription'";
			}
		}
		
		if(!$this->error){
			// Get User Info
			$users = new Users();
			if($users->validate($userID, true)){
				if($users->admin){
					// Catalog.beer Verified
					$this->cbVerified = true;
					$dbCBV = 1;
				}else{
					// Not Catalog.beer Verified
					$dbCBV = 0;
				}
				
				// Prep for Database
				$dbBrewerID = $db->escape($this->brewerID);
				$dbLastModified = $db->escape(time());
				
				// Query
				$updateText = '';
				foreach($sqlString as &$sqlSetStmt){
					$updateText .= $sqlSetStmt . ', ';
				}
				$updateText = substr($updateText, 0, -2);
				$queryString = "UPDATE brewer SET $updateText, lastModified='$dbLastModified', cbVerified='$dbCBV' WHERE id='$dbBrewerID'"; 
				$db->query($queryString);
				if($db->error){
					// Query Error
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
	
	private function validateName(){
		// Must set $this->name
		$this->name = trim($this->name);
		
		if(!empty($this->name)){
			if(strlen($this->name) <= 255){
				// Valid
				$this->validState['name'] = 'valid';
			}else{
				// Name Too Long
				$this->error = true;
				$this->validState['name'] = 'invalid';
				$this->validMsg['name'] = 'We hate to say it but your brewery name is too long for our database. Brewery names are limited to 255 bytes. Any chance you can shorten it?';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 21;
				$errorLog->errorMsg = 'Brewery name too long (>255 Characters)';
				$errorLog->badData = $this->name;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Name
			$this->error = true;
			$this->validState['name'] = 'invalid';
			$this->validMsg['name'] = 'Please give us the name of the brewery you\'d like to add.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 1;
			$errorLog->errorMsg = 'Missing brewery name';
			$errorLog->badData = '';
			$errorLog->filename = 'API / brewer.class.php';
			$errorLog->write();
		}
	}
	
	private function validateDescription(){
		// Must set $this->description
		$this->description = trim($this->description);
		
		if(!empty($this->description)){
			if(strlen($this->description <= 65536)){
				// Valid
				$this->validState['description'] = 'valid';
			}else{
				// Description Too Long
				$this->error = true;
				$this->validState['description'] = 'invalid';
				$this->validMsg['description'] = 'We hate to say it but this brewery description is too long for our database. Descriptions are limited to 65,536 bytes. Any chance you can shorten it?';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 20;
				$errorLog->errorMsg = 'Brewery description too long (>65536 Characters)';
				$errorLog->badData = $this->description;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}
	}
	
	private function validateShortDescription(){
		// Must set $this->shortDescription
		$this->shortDescription = trim($this->shortDescription);
		
		if(!empty($this->shortDescription)){
			if(strlen($this->shortDescription <= 160)){
				// Valid
				$this->validState['short_description'] = 'valid';
			}else{
				// Missing Name
				$this->error = true;
				$this->validState['short_description'] = 'invalid';
				$this->validMsg['short_description'] = 'Sorry, we\'re looking for a short description that is 160 character or less in length. Please shorten the brewery\'s short description to 160 characters or less.';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 92;
				$errorLog->errorMsg = 'Short description too long';
				$errorLog->badData = $this->shortDescription;
				$errorLog->filename = 'brewer.class.php';
				$errorLog->write();
			}
		}
	}
	
	public function validateURL($url, $type){
		// Return
		$returnURL = '';
		
		// Counter
		$i = 1;
		$maxCount = 30;
		
		$url = trim($url);
		if(!empty($url)){
			// Add HTTP?
			if(!preg_match('/^https?:\/\//', $url)){
				// Add HTTP
				$url = 'http://' . $url;
			}
			
			// Check URL Symantics
			if(filter_var($url, FILTER_VALIDATE_URL)){
				$returnURL = $url;
				$continue = true;
				while($continue){
					// Perform cURL
					$curlResponse = $this->curlRequest($url, $type);
					$i++;
					
					if($curlResponse['httpCode'] == 200){
						if(!empty($curlResponse['url'])){
							// Test New URL
							$url = $curlResponse['url'];
							$curlResponse = $this->curlRequest($url, $type);
							$i++;
						}elseif(!preg_match('/^https:\/\//', $url)){
							// Check https
							$secureURL = str_replace('http://', 'https://', $url);
							$curlResponse = $this->curlRequest($secureURL, $type);
							$i++;
							if($curlResponse['httpCode'] == 200){
								// Use HTTPS
								$returnURL = $secureURL;
								$this->validState[$type] = 'valid';
								
								// Stop Loop
								$continue = false;
							}else{
								// HTTPS Not Valid, use HTTP
								$returnURL = $url;
								$this->validState[$type] = 'valid';
																
								// Stop Loop
								$continue = false;
							}
						}else{
							// Already HTTPS, good to go
							$returnURL = $url;
							$this->validState[$type] = 'valid';
														
							// Stop Loop
							$continue = false;
						}
					}else{
						// Invalid URL
						$this->error = true;
						$this->validState[$type] = 'invalid';
						$this->validMsg[$type] = 'Sorry, something seems to be wrong with your URL. Please check it and try again.';
						$returnURL = '';

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 14;
						$errorLog->errorMsg = 'Invalid URL / Failed cURL http';
						$errorLog->badData = $url;
						$errorLog->filename = 'brewer.class.php';
						$errorLog->write();
						
						// Stop Loop
						$continue = false;
					}
					
					if($i==$maxCount){
						// Too Many Redirects
						$this->error = true;
						$this->validState[$type] = 'invalid';
						$this->validMsg[$type] = 'Sorry, something seems to be wrong with your URL. Please check it and try again.';
						$returnURL = '';

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 98;
						$errorLog->errorMsg = 'Too many redirects (+30)';
						$errorLog->badData = $url;
						$errorLog->filename = 'brewer.class.php';
						$errorLog->write();

						// Stop Loop
						$continue = false;
					}
				}
				
				// Check Length
				if(strlen($url) > 255){
					// URL Too Long
					$this->error = true;
					$this->validStatee[$type] = 'invalid';
					$this->validMsg[$type] = 'Sorry, but URL strings are limited to 255 bytes in length. Any chance there is a shorter URL you can use?';
				}
			}else{
				// Invalid URL
				$this->error = true;
				$this->validState[$type] = 'invalid';
				$this->validMsg[$type] = 'Sorry, something seems to be wrong with your URL. Please check it and try again.';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 13;
				$errorLog->errorMsg = 'Invalid URL';
				$errorLog->badData = $url;
				$errorLog->filename = 'brewer.class.php';
				$errorLog->write();
			}
		}else{
			// Return Blank URL
			$returnURL = '';
		}
		
		// Return
		return $returnURL;
	}
	
	private function curlRequest($url, $type){		
		// Return URL
		$returnURL = '';
		
		// Initialize Curl
		$curl = curl_init();
		
		// URL to Test
		curl_setopt($curl, CURLOPT_URL, $url);

		// Headers
		curl_setopt($curl, CURLOPT_NOBODY, true);
		if(preg_match('/^https:\/\//', $url)){
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		}
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_USERAGENT, 'curl/7.53.1');
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		
		// Send Request, Get Output
		$output = curl_exec($curl);
		
		// Response HTTP Code
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if($type == 'instagram_url' && $httpCode == 405){
			// Override HTTP Code
			$httpCode = 200;
		}
		
		if(curl_errno($curl)){
			// cURL Error
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 16;
			$errorLog->errorMsg = 'cURL Error';
			$errorLog->badData = "URL: $url / cURL Error: " . curl_error($curl);
			$errorLog->filename = 'brewer.class.php';
			$errorLog->write();
		}
		
		// Process Output?
		if(gettype($output) == 'string'){
			$exploded = explode("\n", $output);
			foreach($exploded as &$returnLine){
				if(preg_match('/^[lL]ocation: (.+)/', $returnLine, $matches)){
					$newLineChars = array("\n", "\r");
					$returnURL = str_replace($newLineChars, '', $matches[1]);
				}
			}
		}

		// Close curl
		curl_close($curl);
		
		// Return
		return array('httpCode'=>$httpCode, 'url'=>$returnURL);
	}
	
	// Validate Brewer
	public function validate($brewerID, $saveToClass){
		// Valid?
		$valid = false;
		
		// Trim
		$brewerID = trim($brewerID);
		
		if(!empty($brewerID)){
			// Prep for Database
			$db = new Database();
			$dbBrewerID = $db->escape($brewerID);
			$db->query("SELECT name, description, shortDescription, url, cbVerified, brewerVerified, facebookURL, twitterURL, instagramURL, lastModified FROM brewer WHERE id='$dbBrewerID'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid
					$valid = true;
					
					if($saveToClass){
						// Get Result Array
						$array = $db->resultArray();
												
						// Save to Class
						$this->brewerID = $brewerID;
						$this->name = stripcslashes($array['name']);
						$this->description = stripcslashes($array['description']);
						$this->shortDescription = stripcslashes($array['shortDescription']);
						$this->url = $array['url'];
						$this->facebookURL = $array['facebookURL'];
						$this->twitterURL = $array['twitterURL'];
						$this->instagramURL = $array['instagramURL'];
						$this->lastModified = intval($array['lastModified']);
						
						if($array['cbVerified']){
							$this->cbVerified = true;
						}if($array['brewerVerified']){
							$this->brewerVerified = true;
						}
					}
				}elseif($db->result->num_rows > 1){
					// Unexpected number of results
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 19;
					$errorLog->errorMsg = 'Unexpected number of results';
					$errorLog->badData = "brewerID: $brewerID";
					$errorLog->filename = 'brewer.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing BrewerID
			$this->error = true;
			$this->errorMsg = 'Whoops, we seem to be missing the brewer_id for the brewer. Please check your request and try again.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 18;
			$errorLog->errorMsg = 'Missing brewer ID';
			$errorLog->badData = '';
			$errorLog->filename = 'brewer.class.php';
			$errorLog->write();
		}
								
		// Return
		return $valid;
	}
	
	// Get BrewerIDs
	public function getBrewers($cursor, $count){
		// Return Array
		$brewerArray = array();
		
		// Prep Variables
		$offset = intval(base64_decode($cursor));
		$count = intval($count);
		
		if(is_int($offset) && $offset >= 0){
			if(is_int($count)){
				// Within Limits?
				$numBrewers = $this->countBrewers();
				if($offset > $numBrewers){
					// Outside Range
					$this->error = true;
					$this->errorMsg = 'Sorry, the cursor value you supplied is outside our data range.';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 96;
					$errorLog->errorMsg = 'Offset value outside range';
					$errorLog->badData = "Offset: $offset / numBrewers: $numBrewers";
					$errorLog->filename = 'API / Brewer.class.php';
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
					$errorLog->filename = 'API / Brewer.class.php';
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
				$errorLog->filename = 'API / Brewer.class.php';
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
			$errorLog->filename = 'API / Brewer.class.php';
			$errorLog->write();
		}
		
		if(!$this->error){
			// Prep for Database
			$db = new Database();
			$db->query("SELECT id, name FROM brewer ORDER BY name LIMIT $offset, $count");
			if(!$db->error){
				while($array = $db->resultArray()){
					$brewerInfo = array('id'=>$array['id'], 'name'=>$array['name']);
					$brewerArray[] = $brewerInfo;
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}
		
		// Return
		return $brewerArray;
	}
	
	public function nextCursor($cursor, $count){
		// Number of Brewers
		$numBrewers = $this->countBrewers();
		
		// Next Cursor
		$offset = base64_decode($cursor);
		$nextCursor = $offset + $count;
		
		if($nextCursor <= $numBrewers){
			// Return Next Page
			return base64_encode($nextCursor);
		}else{
			return '';
		}
	}
	
	// Number of Brewers
	public function countBrewers(){
		// Return
		$count = 0;
		
		// Query Database
		$db = new Database();
		$db->query("SELECT COUNT('id') AS numBrewers FROM brewer");
		if(!$db->error){
			$array = $db->resultArray();
			return intval($array['numBrewers']);
		}else{
			// Query Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
		}
		
		return $count;
	}
	
	// Last Modified
	public function updateModified($brewerID){
		if(!empty($brewerID)){
			if($this->validate($brewerID, false)){
				// Update Modified Timestamp
				$db = new Database();
				$dbLastModified = $db->escape(time());
				$dbBrewerID = $db->escape($brewerID);
				$db->query("UPDATE brewer SET lastModified='$dbLastModified' WHERE id='$dbBrewerID'");
			}else{
				// Invalid brewerID
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 100;
				$errorLog->errorMsg = 'Invalid brewerID';
				$errorLog->badData = $brewerID;
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing brewerID
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 99;
			$errorLog->errorMsg = 'Missing brewerID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Brewer.class.php';
			$errorLog->write();
		}
	}
	
	public function latestModified(){
		// Return
		$lastModified = 0;
		
		// Connect to Database
		$db = new Database();
		$db->query("SELECT MAX(lastModified) AS lastModified FROM brewer");
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
	
	public function lastModified($brewerID){
		// Return
		$lastModified = 0;
		
		if(!empty($brewerID)){
			if($this->validate($brewerID, true)){
				$lastModified = $this->lastModified;
			}else{
				// Invalid Brewer
				$this->error = true;
				$this->errorMsg = 'Missing brewerID';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 103;
				$errorLog->errorMsg = 'Invalid brewerID';
				$errorLog->badData = $brewerID;
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing BrewerID
			$this->error = true;
			$this->errorMsg = 'Missing brewerID';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 102;
			$errorLog->errorMsg = 'Missing brewerID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Brewer.class.php';
			$errorLog->write();
		}
		
		// Return
		return $lastModified;
	}
}
?>