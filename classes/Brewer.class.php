<?php
class Brewer {

	// Properties
	public $brewerID = '';
	public $name = '';
	public $description = ''; 			// Optional
	public $shortDescription = '';		// Optional
	public $url = '';					// Optional
	public $domainName = '';			// Optional
	public $cbVerified = false;
	public $brewerVerified = false;
	public $facebookURL = '';			// Optional
	public $twitterURL = '';			// Optional
	public $instagramURL = '';			// Optional
	public $lastModified = 0;

	// Error Handling
	public $error = false;
	public $errorMsg = null;
	public $validState = array('name'=>null, 'url'=>null, 'description'=>null, 'short_description'=>null, 'facebook_url'=>null, 'twitter_url'=>null, 'instagram_url'=>null);
	public $validMsg = array('name'=>null, 'url'=>null, 'description'=>null, 'short_description'=>null, 'facebook_url'=>null, 'twitter_url'=>null, 'instagram_url'=>null);

	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();

	// Add Brewer
	public function add($name, $description, $shortDescription, $url, $facebookURL, $twitterURL, $instagramURL, $userID, $method, $brewerID, $patchFields){
		
		// Required Classes
		$db = new Database();
		$users = new Users();
		$privileges = new Privileges();
		
		// ----- brewerID -----
		$uuid = new uuid();
		$newBrewer = false;
		$urlVerified = false;
		switch($method){
			case 'POST':
				// Generate a new brewer_id
				$newBrewer = true;
				$this->brewerID = $uuid->generate('brewer');
				if(!$uuid->error){
					// Get Brewer domain name for brewerVerified by validating URL
					// Populates $this->domainName
					$this->url = $this->validateURL($url, 'url', 'brewer');
					$urlVerified = true;
				}else{
					// UUID Generation Error
					$this->error = true;
					$this->errorMsg = $uuid->errorMsg;
					$this->responseCode = $uuid->responseCode;
				}
				break;
			case 'PUT':
				if($this->validate($brewerID, false)){
					// Valid Brewer - Update Existing Entry
					$this->brewerID = $brewerID;
					
					// Get Brewer domain name for brewerVerified by querying database
					$dbBrewerID = $db->escape($brewerID);
					$db->query("SELECT domainName FROM brewer WHERE id='$dbBrewerID'");
					if(!$db->error){
						// Save Domain Name
						$this->domainName = $db->singleResult('domainName');
					}else{
						// Database Error
						$this->error = true;
						$this->errorMsg = $db->errorMsg;
						$this->responseCode = $db->responseCode;
					}
				}else{
					// Brewer doesn't exist, they'd like to add it
					// Reset Errors from $this->validate()
					$this->error = false;
					$this->errorMsg = null;
					$this->responseCode = 200;
					
					// Validate UUID
					if($uuid->validate($brewerID)){
						// Save submitted UUID as brewerID
						$newBrewer = true;
						$this->brewerID = $brewerID;
						
						// Get Brewer domain name for brewerVerified by validating URL
						// Populates $this->domainName
						$this->url = $this->validateURL($url, 'url', 'brewer');
						$urlVerified = true;
					}else{
						// Invalid UUID Submission
						$this->error = true;
						$this->errorMsg = $uuid->errorMsg;
						$this->responseCode = $uuid->responseCode;
					}
				}
				break;
			case 'PATCH':
				if($this->validate($brewerID, true)){
					// Valid Brewer - Update Existing Entry
					// $this->domainName saved via $this->validate() function above
					$this->brewerID = $brewerID;
				}
				break;
			default:
				// Invalid Method
				$this->error = true;
				$this->errorMsg = 'Invalid Method.';
				$this->responseCode = 405;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 160;
				$errorLog->errorMsg = 'Invalid Method';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
		}
		
		// ----- Permissions & Validation Badge -----
		
		if(!$this->error){
			if($users->validate($userID, true)){
				// Get User's Email Domain Name
				$userEmailDomain = $users->emailDomainName($users->email);

				// Get User Privileges
				$userBrewerPrivileges = $privileges->brewerList($userID);

				// ----- Permissions Check -----
				if($method == 'PUT' || $method == 'PATCH'){
					if(!$newBrewer){
						// Attempting to PUT or PATCH existing Brewery
						// Get cb_verified and brewer_verified flags
						$dbBrewerID = $db->escape($this->brewerID);
						$db->query("SELECT cbVerified, brewerVerified FROM brewer WHERE id='$dbBrewerID'");
						$resultArray = $db->resultArray();
						$cbVerified = $resultArray['cbVerified'];
						$brewerVerified = $resultArray['brewerVerified'];

						if($cbVerified){
							if($userEmailDomain == $this->domainName || in_array($this->brewerID, $userBrewerPrivileges)){
								// Allow PUT/PATCH. User is brewery staff.
							}else{
								if(!$users->admin){
									// Deny
									$this->error = true;
									$this->errorMsg = 'Sorry, because this brewer is cb_verified, we limit editing capabilities to Catalog.beer Admins. If you would like to see an update made to this brewer, please [contact us](https://catalog.beer/contact)';
									$this->responseCode = 403;

									// Log Error
									$errorLog = new LogError();
									$errorLog->errorNumber = 161;
									$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /brewer, cb_verified';
									$errorLog->badData = "User: $userID / Brewer: $this->brewerID";
									$errorLog->filename = 'API / Brewer.class.php';
									$errorLog->write();
								}
							}
						}else{
							if($brewerVerified){
								if($userEmailDomain == $this->domainName || in_array($this->brewerID, $userBrewerPrivileges)){
									// Allow PUT/PATCH. User is brewery staff.
								}else{
									if(!$users->admin){
										// Deny
										$this->error = true;
										$this->errorMsg = 'Sorry, because this brewer is brewer_verified, we limit editing capabilities to brewery staff. If you would like to see an update made to this brewer, please [contact us](https://catalog.beer/contact)';
										$this->responseCode = 403;

										// Log Error
										$errorLog = new LogError();
										$errorLog->errorNumber = 168;
										$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /brewer, brewer_verified';
										$errorLog->badData = "User: $userID / Brewer: $this->brewerID";
										$errorLog->filename = 'API / Brewer.class.php';
										$errorLog->write();
									}
								}
							}
						}
					}
				}

				// ----- Verification Badges -----
				$this->cbVerified = false;
				$dbCBV = b'0';
				$this->brewerVerified = false;
				$dbBV = b'0';
				$addPrivileges = false;
				$removePrivileges = false;

				// Get User Info
				if($users->admin){
					// Catalog.beer Verified
					$this->cbVerified = true;
					$dbCBV = b'1';
				}else{
					// Not Catalog.beer Verified
					if(!empty($this->domainName)){
						// URL/Domain Name Present
						if($newBrewer){
							if($userEmailDomain == $this->domainName){
								// User has email associated with the brewery, give breweryValidated flag.
								$this->brewerVerified = true;
								$dbBV = b'1';
								$addPrivileges = true;
							}
						}else{
							if(!empty($url)){
								// Current Domain Name: $this->domainName
								// Get Domain Name for: $url
								$newDomainName = $this->urlDomainName($url);
								if($newDomainName == $this->domainName){
									// Domain Name is staying the same	
									if(in_array($this->brewerID, $userBrewerPrivileges)){
										// User has Brewery Privileges, add breweryValidate flag
										$this->brewerVerified = true;
										$dbBV = b'1';
									}elseif($userEmailDomain == $this->domainName){
										// User has email associated with the brewery, give breweryValidated flag.
										$this->brewerVerified = true;
										$dbBV = b'1';
										$addPrivileges = true;
									}
								}else{
									// New Domain Name
									if($userEmailDomain == $newDomainName){
										// Retain Brewer Privileges
										$this->brewerVerified = true;
										$dbBV = b'1';
									}else{
										// Remove Brewer Privileges
										$removePrivileges = true;
									}
								}
							}else{
								// URL Not being Updated
								if(in_array($this->brewerID, $userBrewerPrivileges)){
									// User has Brewery Privileges, add breweryValidate flag
									$this->brewerVerified = true;
									$dbBV = b'1';
								}elseif($userEmailDomain == $this->domainName){
									// User has email associated with the brewery, give breweryValidated flag.
									$this->brewerVerified = true;
									$dbBV = b'1';
									$addPrivileges = true;
								}
							}
						}
					}
				}
			}else{
				// User Validation Error
				$this->error = true;
				$this->errorMsg = $users->errorMsg;
				$this->responseCode = $users->responseCode;
			}
		}
		
		// ----- Validate Fields -----
		// Don't waste processing resources if there's been an error in the steps above.
		if(!$this->error){
			// Default SQL
			$sql = '';
			
			if($method == 'POST' || $method == 'PUT'){
				// Validate Name
				$this->name = $name;
				$this->validateName();

				// Validate URLs
				if(!$urlVerified){
					// Validate Submitted URL
					$this->url = $this->validateURL($url, 'url', 'brewer');
				}
				$this->facebookURL = $this->validateURL($facebookURL, 'facebook_url', 'brewer');
				$this->twitterURL = $this->validateURL($twitterURL, 'twitter_url', 'brewer');
				$this->instagramURL = $this->validateURL($instagramURL, 'instagram_url', 'brewer');

				// Validate Description
				$this->description = $description;
				$this->validateDescription();

				// Validate Short Description
				$this->shortDescription = $shortDescription;
				$this->validateShortDescription();

				if(!$this->error){
					// Escape for Database
					$dbBrewerID = $db->escape($this->brewerID);
					$dbName = $db->escape($this->name);
					$dbDescription = $db->escape($this->description);
					$dbShortDescription = $db->escape($this->shortDescription);
					$dbURL = $db->escape($this->url);
					$dbDomainName = $db->escape($this->domainName);
					$dbFacebookURL = $db->escape($this->facebookURL);
					$dbTwitterURL = $db->escape($this->twitterURL);
					$dbInstagramURL = $db->escape($this->instagramURL);
					$this->lastModified = time();
					$dbLastModified = $db->escape($this->lastModified);

					// Construct SQL Statement
					if($newBrewer){
						// Add Brewer (POST/PUT)
						$columns = '';
						$values = ") VALUES ('$dbBrewerID', '$dbName', $dbCBV, $dbBV, $dbLastModified, ";
						if(!empty($dbDescription)){
							$columns .= 'description, ';
							$values .= "'$dbDescription', ";
						}
						if(!empty($dbShortDescription)){
							$columns .= 'shortDescription, ';
							$values .= "'$dbShortDescription', ";
						}
						if(!empty($dbURL)){
							$columns .= 'url, domainName, ';
							$values .= "'$dbURL', '$dbDomainName', ";
						}
						if(!empty($dbFacebookURL)){
							$columns .= 'facebookURL, ';
							$values .= "'$dbFacebookURL', ";
						}
						if(!empty($dbTwitterURL)){
							$columns .= 'twitterURL, ';
							$values .= "'$dbTwitterURL', ";
						}
						if(!empty($dbInstagramURL)){
							$columns .= 'instagramURL, ';
							$values .= "'$dbInstagramURL', ";
						}
						if(!empty($columns)){
							$sql = "INSERT INTO brewer (id, name, cbVerified, brewerVerified, lastModified, " . substr($columns, 0, strlen($columns)-2) . substr($values, 0, strlen($values)-2) . ")";
						}else{
							$sql = "INSERT INTO brewer (id, name, cbVerified, brewerVerified, lastModified" . substr($values, 0, strlen($values)-2) . ")";
						}
					}else{
						// Update Brewer (PUT)
						$sqlUpdate = '';
						if(!empty($dbDescription)){
							$sqlUpdate .= "description='$dbDescription', ";
						}
						if(!empty($dbShortDescription)){
							$sqlUpdate .= "shortDescription='$dbShortDescription', ";
						}
						if(!empty($dbURL)){
							$sqlUpdate .= "url='$dbURL', domainName='$dbDomainName', ";
						}
						if(!empty($dbFacebookURL)){
							$sqlUpdate .= "facebookURL='$dbFacebookURL', ";
						}
						if(!empty($dbTwitterURL)){
							$sqlUpdate .= "twitterURL='$dbTwitterURL', ";
						}
						if(!empty($dbInstagramURL)){
							$sqlUpdate .= "instagramURL='$dbInstagramURL', ";
						}
						$sql = "UPDATE brewer SET name='$dbName', cbVerified=$dbCBV, brewerVerified=$dbBV, lastModified=$dbLastModified, " . substr($sqlUpdate, 0, strlen($sqlUpdate)-2) . " WHERE id='$dbBrewerID'";
					}
				}
			}elseif($method == 'PATCH'){
				/*-- 
				Validate the field if it's different than what is currently stored.
				Check against the $this->{var} which we have from performing a $this->validate($brewerID, true) in the brewerID flow above for PATCH.
				--*/
				
				// SQL Update
				$sqlArray = array();
				
				// Validate Name
				if(in_array('name', $patchFields)){
					if($name != $this->name){
						// Validate Name
						$this->name = $name;
						$this->validateName();
						if(!$this->error){
							$dbName = $db->escape($this->name);
							$sqlArray[] = "name='$dbName'";
						}
					}
				}

				// Validate URLs
				if(in_array('url', $patchFields)){
					if($url != $this->url){
						$this->url = $this->validateURL($url, 'url', 'brewer');
						if(!$this->error){
							$dbURL = $db->escape($this->url);
							$dbDomainName = $db->escape($this->domainName);
							$sqlArray[] = "url='$dbURL', domainName='$dbDomainName'";
						}
					}
				}
				if(in_array('facebook_url', $patchFields)){
					if($facebookURL != $this->facebookURL){
						$this->facebookURL = $this->validateURL($facebookURL, 'facebook_url', 'brewer');
						if(!$this->error){
							$dbFacebookURL = $db->escape($this->facebookURL);
							$sqlArray[] = "facebookURL='$dbFacebookURL'";
						}
					}
				}
				if(in_array('twitter_url', $patchFields)){
					if($twitterURL != $this->twitterURL){
						$this->twitterURL = $this->validateURL($twitterURL, 'twitter_url', 'brewer');
						if(!$this->error){
							$dbTwitterURL = $db->escape($this->twitterURL);
							$sqlArray[] = "twitterURL='$dbTwitterURL'";
						}
					}
				}
				if(in_array('instagram_url', $patchFields)){
					if($instagramURL != $this->instagramURL){
						$this->instagramURL = $this->validateURL($instagramURL, 'instagram_url', 'brewer');
						if(!$this->error){
							$dbInstagramURL = $db->escape($this->instagramURL);
							$sqlArray[] = "instagramURL='$dbInstagramURL'";
						}
					}
				}

				// Validate Description
				if(in_array('description', $patchFields)){
					if($description != $this->description){
						$this->description = $description;
						$this->validateDescription();
						if(!$this->error){
							$dbDescription = $db->escape($this->description);
							$sqlArray[] = "description='$dbDescription'";
						}
					}
				}

				// Validate Short Description
				if(in_array('short_description', $patchFields)){
					if($shortDescription != $this->shortDescription){
						$this->shortDescription = $shortDescription;
						$this->validateShortDescription();
						if(!$this->error){
							$dbShortDescription = $db->escape($this->shortDescription);
							$sqlArray[] = "shortDescription='$dbShortDescription'";
						}
					}
				}
				
				if(!$this->error && !empty($sqlArray)){
					// Prep for Database
					$dbBrewerID = $db->escape($this->brewerID);
					$dbLastModified = $db->escape(time());
					
					// Construct SQL Statement
					$sql = "UPDATE brewer SET lastModified=$dbLastModified, cbVerified=$dbCBV, brewerVerified=$dbBV";
					
					$totalUpdates = count($sqlArray);
					if($totalUpdates > 0){$sql .= ", ";}
					$lastUpdate = $totalUpdates - 1;
					for($i=0;$i<$totalUpdates; $i++){
						if($i == $lastUpdate){
							$sql .= $sqlArray[$i];
						}else{
							$sql .= $sqlArray[$i] . ", ";
						}
					}
					$sql .= " WHERE id='$dbBrewerID'";
				}
			}
			
			if(!$this->error && !empty($sql)){
				// Query
				$db->query($sql);
				if(!$db->error){
					// Successful database operation
					if($newBrewer){
						// Created New Brewer
						$this->responseCode = 201;
						$responseHeaderString = 'Location: https://';
						if(ENVIRONMENT == 'staging'){
							$responseHeaderString .= 'staging.';
						}
						$this->responseHeader = $responseHeaderString . 'catalog.beer/brewer/' . $this->brewerID;
					}else{
						$this->responseCode = 200;
					}
					
					// Add Privileges?
					if($addPrivileges){
						$privileges->add($userID, $this->brewerID, true);
					}elseif($removePrivileges){
						$privileges->remove($userID, $this->brewerID);
					}
				}else{
					// Query Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}

				// Close Database Connection
				$db->close();
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
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 21;
				$errorLog->errorMsg = 'Brewery name too long (>255 Characters)';
				$errorLog->badData = $this->name;
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Name
			$this->error = true;
			$this->validState['name'] = 'invalid';
			$this->validMsg['name'] = 'Please give us the name of the brewery you\'d like to add.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 1;
			$errorLog->errorMsg = 'Missing brewery name';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Brewer.class.php';
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
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 20;
				$errorLog->errorMsg = 'Brewery description too long (>65536 Characters)';
				$errorLog->badData = $this->description;
				$errorLog->filename = 'API / Brewer.class.php';
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
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 92;
				$errorLog->errorMsg = 'Short description too long';
				$errorLog->badData = $this->shortDescription;
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
			}
		}
	}

	public function validateURL($url, $type, $class){
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
			
			// Add HTTPS for Facebook, Twitter, and Instagram
			if($type == 'instagram_url' || $type == 'facebook_url' || $type == 'twitter_url'){
				if(!preg_match('/^https:\/\//', $url)){
					// Add HTTPS
					$url = 	str_replace('http://', 'https://', $url);
				}
			}

			// Check URL Symantics
			if(filter_var($url, FILTER_VALIDATE_URL)){
				$returnURL = $url;
				$continue = true;
				while($continue){
					// Perform cURL
					$curlResponse = $this->curlRequest($url, $type);
					$i++;

					if($curlResponse['httpCode'] >= 200 && $curlResponse['httpCode'] <= 206){
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
					}elseif($curlResponse['httpCode'] == 301){
						// Moved Permanently. Save new location.
						$returnURL = $curlResponse['url'];
						$this->validState[$type] = 'valid';

						// Stop Loop
						$continue = false;
					}elseif($curlResponse['httpCode'] == 302 && $type == 'instagram_url'){
						// Found, Redirect to Login Page
						if(preg_match('/next=(.+)/', $curlResponse['url'], $matches)){
							// Rewrite without login page
							$returnURL = 'https://www.instagram.com' . $matches[1];
						}else{
							$returnURL = $curlResponse['url'];
						}
						
						// Stop Loop
						$continue = false;
					}elseif($curlResponse['httpCode'] == 405 && $type == 'instagram_url'){
						// Instagram doesn't like HEAD and prefers GET
						// Assume Valid
						$returnURL = $url;
						
						// Stop Loop
						$continue = false;
					}else{
						// Invalid URL
						$this->error = true;
						$this->validState[$type] = 'invalid';
						$this->validMsg[$type] = 'Sorry, something seems to be wrong with your URL. Please check it and try again.';
						$returnURL = '';
						$this->responseCode = 400;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 107;
						$errorLog->errorMsg = 'Invalid URL / Failed cURL http';
						$errorLog->badData = 'URL: ' . $url . ' / HTTP Response Code: ' . $curlResponse['httpCode'];
						$errorLog->filename = 'API / Brewer.class.php';
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
						$this->responseCode = 400;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 98;
						$errorLog->errorMsg = 'Too many redirects (+30)';
						$errorLog->badData = $url;
						$errorLog->filename = 'API / Brewer.class.php';
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
					$this->responseCode = 400;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 147;
					$errorLog->errorMsg = 'URL Too Long';
					$errorLog->badData = $url;
					$errorLog->filename = 'API / Brewer.class.php';
					$errorLog->write();
				}
			}else{
				// Invalid URL
				$this->error = true;
				$this->validState[$type] = 'invalid';
				$this->validMsg[$type] = 'Sorry, something seems to be wrong with your URL. Please check it and try again.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 13;
				$errorLog->errorMsg = 'Invalid URL';
				$errorLog->badData = $url;
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
			}
		}else{
			// Return Blank URL
			$returnURL = '';
		}

		// Validate URLs
		if(!empty($returnURL)){
			switch($type){
				case 'facebook_url':
					if(substr($returnURL, 0, 25) != 'https://www.facebook.com/'){
						// Invalid Facebook URL
						$this->error = true;
						$this->validState['facebook_url'] = 'invalid';
						$this->validMsg['facebook_url'] = 'We were expecting the Facebook URL to start with "https://www.facebook.com/". Please double check the Facebook URL you submitted.';
						$this->responseCode = 400;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 144;
						$errorLog->errorMsg = 'Invalid Facebook URL';
						$errorLog->badData = $returnURL;
						$errorLog->filename = 'API / Brewer.class.php';
						$errorLog->write();
					}
					break;
				case 'twitter_url':
					if(substr($returnURL, 0, 20) != 'https://twitter.com/'){
						// Invalid Twitter URL
						$this->error = true;
						$this->validState['twitter_url'] = 'invalid';
						$this->validMsg['twitter_url'] = 'We were expecting the Twitter URL to start with "https://twitter.com/". Please double check the Twitter URL you submitted.';
						$this->responseCode = 400;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 145;
						$errorLog->errorMsg = 'Invalid Twitter URL';
						$errorLog->badData = $returnURL;
						$errorLog->filename = 'API / Brewer.class.php';
						$errorLog->write();
					}
					break;
				case 'instagram_url':
					if(substr($returnURL, 0, 26) != 'https://www.instagram.com/'){
						if(substr($returnURL, 0, 22) != 'https://instagram.com/'){
							// Invalid Instagram URL
							$this->error = true;
							$this->validState['instagram_url'] = 'invalid';
							$this->validMsg['instagram_url'] = 'We were expecting the Instagram URL to start with "https://www.instagram.com/". Please double check the Instagram URL you submitted.';
							$this->responseCode = 400;

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 146;
							$errorLog->errorMsg = 'Invalid Instagram URL';
							$errorLog->badData = $returnURL;
							$errorLog->filename = 'API / Brewer.class.php';
							$errorLog->write();
						}
					}
					break;
				case 'url':
					if($class == 'brewer'){
						// Get Domain name from Brewery URL
						$this->domainName = $this->urlDomainName($returnURL);
					}
					break;
			}
		}

		// Return
		return $returnURL;
	}

	private function urlDomainName($url){
		// Get Domain name from URL
		$urlDomainName = '';

		// trim
		$url = trim($url);

		if(!empty($url)){
			$host = parse_url($url, PHP_URL_HOST);
			preg_match('([a-zA-Z0-9.-]+)', $host, $hostMatches);
			if(!empty($hostMatches)){
				// Save Match
				$urlDomainName = $hostMatches[0];

				// Remove www prefix
				$stringPrefix = substr($urlDomainName, 0, 4);
				if($stringPrefix == "www."){
					$urlDomainName = substr($urlDomainName, 4);
				}
				
				// Check for Duplicate Domain Names
				$db = new Database();
				$dbDomainName = $db->escape($urlDomainName);
				$db->query("SELECT id FROM brewer WHERE domainName='$dbDomainName'");
				if($db->result->num_rows == 1){
					// Get brewerID
					$brewerID = $db->singleResult('id');
					
					if($brewerID == $this->brewerID){
						// They may be updating their brewery URL, no duplicate will be created
						// No need to throw an error
					}else{
						// Duplicate Domain Name - Not Acceptable
						$this->error = true;
						$this->validState['url'] = 'invalid';
						$this->validMsg['url'] = "Sorry, there is already a brewery in our database with the domain name: $urlDomainName. We require that breweries have unique URLs so can't add this entry to our database on your behalf. If you'd like help resolving this issue, please [contact us](/contact)";
						$this->responseCode = 400;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 182;
						$errorLog->errorMsg = 'Attempt to add duplicate URL';
						$errorLog->badData = "URL: $url / Domain Name: $urlDomainName";
						$errorLog->filename = 'API / Brewer.class.php';
						$errorLog->write();
					}
				}
				$db->close();
			}else{
				// Error with hostname
				$this->error = true;
				$this->errorMsg = 'Sorry, we had a problem parsing the domain name you gave us for the brewer. We have logged the issue for our support team.';
				$this->responseCode = 500;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 155;
				$errorLog->errorMsg = 'Brewer Domain Parsing Error';
				$errorLog->badData = "URL: $url / Host: $host";
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
			}
		}

		return $urlDomainName;
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

		if(curl_errno($curl)){
			// cURL Error
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 16;
			$errorLog->errorMsg = 'cURL Error';
			$errorLog->badData = "URL: $url / cURL Error: " . curl_error($curl);
			$errorLog->filename = 'API / Brewer.class.php';
			$errorLog->write();
		}

		// Process Output?
		if(gettype($output) == 'string'){
			if(preg_match('/[lL]ocation: (.+)/', $output, $matches)){
				$newLineChars = array("\n", "\r");
				$returnURL = str_replace($newLineChars, '', $matches[1]);
			}
			if(preg_match('/HTTP\/1.1 ([0-9]{3})/', $output, $matches)){
				$httpCode = intval($matches[1]);
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
			$db->query("SELECT name, description, shortDescription, url, domainName, cbVerified, brewerVerified, facebookURL, twitterURL, instagramURL, lastModified FROM brewer WHERE id='$dbBrewerID'");
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
						$this->domainName = $array['domainName'];
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
					$this->responseCode = 500;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 19;
					$errorLog->errorMsg = 'Unexpected number of results';
					$errorLog->badData = "brewerID: $brewerID";
					$errorLog->filename = 'API / Brewer.class.php';
					$errorLog->write();
				}else{
					// Brewer Does Not Exist
					$this->error = true;
					$this->errorMsg = "Sorry, we couldn't find a brewer with the brewer_id you provided.";
					$this->responseCode = 404;
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Missing BrewerID
			$this->error = true;
			$this->errorMsg = 'Whoops, we seem to be missing the brewer_id for the brewer. Please check your request and try again.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 169;
			$errorLog->errorMsg = 'Missing brewer ID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Brewer.class.php';
			$errorLog->write();
		}

		// Return
		return $valid;
	}

	// Validate Cursor and Count
	private function validateCursorCount($cursor, $count){
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
					$this->responseCode = 400;

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
					$this->responseCode = 400;

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
				$this->responseCode = 400;

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
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 94;
			$errorLog->errorMsg = 'Invalid cursor value';
			$errorLog->badData = $offset;
			$errorLog->filename = 'API / Brewer.class.php';
			$errorLog->write();
		}

		return(array($offset, $count));
	}

	// Get BrewerIDs
	public function getBrewers($cursor, $count){
		// Return Array
		$brewerArray = array();

		// Validate $cursor and $count
		$cursorCountArray = $this->validateCursorCount($cursor, $count);
		$offset = $cursorCountArray[0];
		$count = $cursorCountArray[1];

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
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}

		// Return
		return $brewerArray;
	}

	public function nextCursor($cursor, $count){
		// Validate $cursor and $count
		$cursorCountArray = $this->validateCursorCount($cursor, $count);
		$offset = $cursorCountArray[0];
		$count = $cursorCountArray[1];

		if(!$this->error){
			// Number of Brewers
			$numBrewers = $this->countBrewers();

			// Next Cursor
			$nextCursor = $offset + $count;

			if($nextCursor <= $numBrewers){
				// Return Next Page
				return base64_encode($nextCursor);
			}else{
				return '';
			}
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
			$this->responseCode = $db->responseCode;
		}
		$db->close();

		return $count;
	}
	
	public function delete($brewerID, $userID){
		if($this->validate($brewerID, false)){
			// Get User Information
			$users = new Users();
			$users->validate($userID, true);
			
			// Get Brewer Privileges
			$privileges = new Privileges();
			$brewerPrivilegesList = $privileges->brewerList($userID);
			
			if($users->admin || in_array($brewerID, $brewerPrivilegesList)){
				// Delete Brewer
				$db = new Database();
				$dbBrewerID = $db->escape($brewerID);
				$db->query("DELETE FROM brewer WHERE id='$dbBrewerID'");
				if($db->error){
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
				$db->close();
			}else{
				// Not Allowed to Delete
				$this->error = true;
				$this->errorMsg = 'Sorry, you do not have permission to delete this brewery.';
				$this->responseCode = 403;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 163;
				$errorLog->errorMsg = 'Forbidden: Non-Admin, DELETE, /brewer';
				$errorLog->badData = "User: $userID / Brewer: $this->brewerID";
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
			}
		}
	}
	
	public function generateBrewerObject($json){
		/*---
		Generates the Brewer Object
		Generally returned as part of the API output
		$json = true or false
			true = return data in $this->json[];
			false = return data in an array();
		---*/
		
		// Optional Values that may be stored as null, return as empty ("")
		if(empty($this->description)){$this->description = null;}
		if(empty($this->shortDescription)){$this->shortDescription = null;}
		if(empty($this->url)){$this->url = null;}
		if(empty($this->facebookURL)){$this->facebookURL = null;}
		if(empty($this->twitterURL)){$this->twitterURL = null;}
		if(empty($this->instagramURL)){$this->instagramURL = null;}
		
		// Known Values - Required
		$array = array();
		$array['id'] = $this->brewerID;
		$array['object'] = 'brewer';
		$array['name'] = $this->name;
		$array['description'] = $this->description;
		$array['short_description'] = $this->shortDescription;
		$array['url'] = $this->url;
		$array['cb_verified'] = $this->cbVerified;
		$array['brewer_verified'] = $this->brewerVerified;
		$array['facebook_url'] = $this->facebookURL;
		$array['twitter_url'] = $this->twitterURL;
		$array['instagram_url'] = $this->instagramURL;
		$array['last_modified'] = $this->lastModified;
		
		if($json){
			// Add to JSON Output
			$this->json = $array;
		}else{
			// Return as array
			return $array;
		}
	}

	public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
		/*---
		{METHOD} https://api.catalog.beer/brewer/{function}
		{METHOD} https://api.catalog.beer/brewer/{id}/{function}

		GET https://api.catalog.beer/brewer
		GET https://api.catalog.beer/brewer/count
		GET https://api.catalog.beer/brewer/{brewer_id}
		GET https://api.catalog.beer/brewer/{brewer_id}/beer
		GET https://api.catalog.beer/brewer/{brewer_id}/locations

		POST https://api.catalog.beer/brewer

		PUT https://api.catalog.beer/brewer/{brewer_id}
		
		PATCH https://api.catalog.beer/brewer/{brewer_id}
		
		DELETE https://api.catalog.beer/brewer/{brewer_id}
		---*/
		switch($method){
			case 'GET':
				if(!empty($id) && empty($function)){
					// Validate ID
					// GET https://api.catalog.beer/brewer/{brewer_id}
					if($this->validate($id, true)){
						// Generate Brewer Object JSON
						$this->generateBrewerObject(true);
					}else{
						// Brewer Validation Error
						$this->json['error'] = true;
						$this->json['error_msg'] = $this->errorMsg;
					}
				}else{
					if(!empty($function)){
						switch($function){
							case 'count':
								// GET https://api.catalog.beer/brewer/count
								$numBrewers = $this->countBrewers();
								if(!$this->error){
									$this->json['object'] = 'count';
									$this->json['url'] = '/brewer/count';
									$this->json['value'] = $numBrewers;
								}else{
									$this->json['error'] = true;
									$this->json['error_msg'] = $this->errorMsg;
								}
								break;
							case 'beer':
								// GET https://api.catalog.beer/brewer/{brewer_id}/beer
								$beer = new Beer();
								$this->json = $beer->brewerBeers($id);
								if($beer->error){
									$this->json['error'] = true;
									$this->json['error_msg'] = $beer->errorMsg;
								}
								$this->responseCode = $beer->responseCode;
								break;
							case 'locations':
								// GET https://api.catalog.beer/brewer/{brewer_id}/locations
								$location = new Location();
								$locationArray = $location->brewerLocations($id);
								if(!$location->error){
									$this->validate($id, true);
									$this->json['object'] = 'list';
									$this->json['url'] = '/brewer/' . $id . '/locations';
									$this->json['has_more'] = false;
									$this->json['brewer'] = $this->generateBrewerObject(false);
									$this->json['data'] = $locationArray;
								}else{
									$this->responseCode = $location->responseCode;
									$this->json['error'] = true;
									$this->json['error_msg'] = $location->errorMsg;
								}
								break;
							default:
								// Invalid Function
								$this->responseCode = 404;
								$this->json['error'] = true;
								$this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 69;
								$errorLog->errorMsg = 'Invalid function (/brewer)';
								$errorLog->badData = $function;
								$errorLog->filename = 'API / Brewer.class.php';
								$errorLog->write();
						}
					}else{
						// List Breweries
						// GET https://api.catalog.beer/brewer
						$brewerArray = $this->getBrewers($cursor, $count);
						if(!$this->error){
							// Start JSON
							$this->json['object'] = 'list';
							$this->json['url'] = '/brewer';

							// Next Cursor
							$nextCursor = $this->nextCursor($cursor, $count);
							if(!empty($nextCursor)){
								$this->json['has_more'] = true;
								$this->json['next_cursor'] = $nextCursor;
							}else{
								$this->json['has_more'] = false;
							}

							// Append Data
							$this->json['data'] = $brewerArray;
						}else{
							$this->json['error'] = true;
							$this->json['error_msg'] = $this->errorMsg;
						}
					}
				}
				break;
			case 'POST':
				// POST https://api.catalog.beer/brewer
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Handle Empty Fields
				if(empty($data->name)){$data->name = '';}
				if(empty($data->description)){$data->description = '';}
				if(empty($data->short_description)){$data->short_description = '';}
				if(empty($data->url)){$data->url = '';}
				if(empty($data->facebook_url)){$data->facebook_url = '';}
				if(empty($data->twitter_url)){$data->twitter_url = '';}
				if(empty($data->instagram_url)){$data->instagram_url = '';}

				// Add Brewer
				$this->add($data->name, $data->description, $data->short_description, $data->url, $data->facebook_url, $data->twitter_url, $data->instagram_url, $apiKeys->userID, 'POST', '', array());
				if(!$this->error){
					// Generate Brewer Object JSON
					$this->generateBrewerObject(true);
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'PUT':
				// PUT https://api.catalog.beer/brewer/{brewer_id}
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Handle Empty Fields
				if(empty($data->name)){$data->name = '';}
				if(empty($data->description)){$data->description = '';}
				if(empty($data->short_description)){$data->short_description = '';}
				if(empty($data->url)){$data->url = '';}
				if(empty($data->facebook_url)){$data->facebook_url = '';}
				if(empty($data->twitter_url)){$data->twitter_url = '';}
				if(empty($data->instagram_url)){$data->instagram_url = '';}

				// Update Brewer
				$this->add($data->name, $data->description, $data->short_description, $data->url, $data->facebook_url, $data->twitter_url, $data->instagram_url, $apiKeys->userID, 'PUT', $id, array());
				if(!$this->error){
					// Get Updated Brewer Info
					$this->validate($id, true);
					
					// Generate Brewer Object JSON
					$this->generateBrewerObject(true);
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'PATCH':
				// PATCH https://api.catalog.beer/brewer/{brewer_id}
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Which fields are we updating?
				$patchFields = array();
				
				if(isset($data->name)){$patchFields[] = 'name';}
				else{$data->name = '';}
				
				if(isset($data->description)){$patchFields[] = 'description';}
				else{$data->description = '';}
				
				if(isset($data->short_description)){$patchFields[] = 'short_description';}
				else{$data->short_description = '';}
				
				if(isset($data->url)){$patchFields[] = 'url';}
				else{$data->url = '';}
				
				if(isset($data->facebook_url)){$patchFields[] = 'facebook_url';}
				else{$data->facebook_url = '';}
				
				if(isset($data->twitter_url)){$patchFields[] = 'twitter_url';}
				else{$data->twitter_url = '';}
				
				if(isset($data->instagram_url)){$patchFields[] = 'instagram_url';}
				else{$data->instagram_url = '';}
				
				// Update Brewer
				$this->add($data->name, $data->description, $data->short_description, $data->url, $data->facebook_url, $data->twitter_url, $data->instagram_url, $apiKeys->userID, 'PATCH', $id, $patchFields);
				if(!$this->error){
					// Get Updated Brewer Info
					$this->validate($id, true);
					
					// Generate Brewer Object JSON
					$this->generateBrewerObject(true);
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'DELETE':
				// DELETE https://api.catalog.beer/brewer/{{brewer_id}}
				// Get userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Delete Location
				$this->delete($id, $apiKeys->userID);
				if(!$this->error){
					// Successful Delete
					$this->responseCode = 204;
				}else{
					// Error
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
				}
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->json['error'] = true;
				$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
				$this->responseCode = 405;
				$this->responseHeader = 'Allow: GET, POST, PUT, PATCH, DELETE';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 142;
				$errorLog->errorMsg = 'Invalid Method (/brewer)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Brewer.class.php';
				$errorLog->write();
		}
	}
}
?>
