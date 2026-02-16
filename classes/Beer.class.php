<?php
class Beer {

	// Properties
	public $beerID = '';
	public $brewerID = '';
	public $name = '';
	public $style = '';
	public $description = '';			// Optional
	public $abv = 0;
	public $ibu = 0;					// Optional
	public $cbVerified = false;
	public $brewerVerified = false;
	public $lastModified = 0;

	// Error Handling
	public $error = false;
	public $errorMsg = null;
	public $validState = array('brewer_id'=>null, 'name'=>null, 'style'=>null, 'description'=>null, 'abv'=>null, 'ibu'=>null);
	public $validMsg = array('brewer_id'=>null, 'name'=>null, 'style'=>null, 'description'=>null, 'abv'=>null, 'ibu'=>null);

	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();

	// Verification
	private $isBV = false;	// Is the brewery, brewerVerified?
	private $isCBV = false;	// Is the brewery, catalog.beer verified (cbVerified)?

	// Cached objects to avoid redundant queries
	private $brewerObj = null;
	private $totalCount = 0;


	public function add($brewerID, $name, $style, $description, $abv, $ibu, $userID, $method, $beerID, $patchFields){

		// Required Classes
		$brewer = new Brewer();
		$db = new Database();
		$privileges = new Privileges();
		$users = new Users();
		$uuid = new uuid();

		// ----- beerID -----
		$newBeer = false;
		switch($method){
			case 'POST':
				// Generate a new beer_id
				$newBeer = true;
				$this->beerID = $uuid->generate('beer');
				if($uuid->error){
					// UUID Generation Error
					$this->error = true;
					$this->errorMsg = $uuid->errorMsg;
					$this->responseCode = $uuid->responseCode;
				}
				break;
			case 'PUT':
				if($this->validate($beerID, true)){
					// Valid Beer - Update Existing Entry
					$this->beerID = $beerID;
					// Save original values for permissions check
					$originalBeerBrewerID = $this->brewerID;
					$originalCBV = $this->cbVerified;
					$originalBV = $this->brewerVerified;
				}else{
					// Beer doesn't exist, they'd like to add it
					// Reset Errors from $this->validate()
					$this->error = false;
					$this->errorMsg = null;
					$this->responseCode = 200;

					// Validate UUID
					if($uuid->validate($beerID)){
						// Save submitted UUID as beerID
						$newBeer = true;
						$this->beerID = $beerID;
					}else{
						// Invalid UUID Submission
						$this->error = true;
						$this->errorMsg = $uuid->errorMsg;
						$this->responseCode = $uuid->responseCode;
					}
				}
				break;
			case 'PATCH':
				if($this->validate($beerID, true)){
					// Valid Beer - Update Existing Entry (Reference #1)
					$this->beerID = $beerID;
					// Save original values for permissions check
					$originalBeerBrewerID = $this->brewerID;
					$originalCBV = $this->cbVerified;
					$originalBV = $this->brewerVerified;
					if(!in_array('brewerID', $patchFields)){
						// Not updating brewer. Retain current brewerID
						$brewerID = $this->brewerID;
					}else{
						// Check to ensure it's a new brewer_id
						if($this->brewerID == $brewerID){
							// Same brewer_id, not changing. Remove from $patchFields
							$key = array_search('brewerID', $patchFields);
							if($key !== false){ unset($patchFields[$key]); }
						}
					}
				}
				break;
			default:
				// Invalid Method
				$this->error = true;
				$this->errorMsg = 'Invalid Method.';
				$this->responseCode = 405;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 165;
				$errorLog->errorMsg = 'Invalid Method';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
		}

		// ----- Validate Brewery -----
		if($brewer->validate($brewerID, true)){
			// Valid Brewer
			$this->brewerID = $brewerID;
			$this->validState['brewer_id'] = 'valid';
			$this->brewerObj = $brewer;

			// Which brewer is this beer currently associated with?
			if(($method == 'PUT' || $method == 'PATCH') && isset($originalBeerBrewerID)){
				// Use saved brewerID from validate()
				$permissionsBrewerID = $originalBeerBrewerID;
			}else{
				// New beer or POST, use the submitted brewerID
				$permissionsBrewerID = $this->brewerID;
			}
		}else{
			// Invalid Brewer
			$this->error = true;
			$this->validState['brewer_id'] = 'invalid';
			$this->validMsg['brewer_id'] = $brewer->errorMsg;

			// Correct 404 (Not Found) to 400 (Bad Request) for Brewer Not Found
			if($brewer->responseCode === 404){
				$this->responseCode = 400;
			}else{
				$this->responseCode = $brewer->responseCode;
			}
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
					if(!$newBeer){
						// Attempting to PUT or PATCH existing Beer
						// Use saved cb_verified and brewer_verified flags from validate()
						$cbVerified = $originalCBV;
						$brewerVerified = $originalBV;

						if($cbVerified){
							if($userEmailDomain == $brewer->domainName || in_array($permissionsBrewerID, $userBrewerPrivileges)){
								// Allow PUT/PATCH. User is brewery staff.
							}else{
								if(!$users->admin){
									// Deny
									$this->error = true;
									$this->errorMsg = 'Sorry, because this beer is cb_verified, we limit editing capabilities to Catalog.beer Admins. If you would like to see an update made to this brewer, please [contact us](https://catalog.beer/contact)';
									$this->responseCode = 403;

									// Log Error
									$errorLog = new LogError();
									$errorLog->errorNumber = 166;
									$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /beer, cb_verified';
									$errorLog->badData = "User: $userID / Beer: $this->beerID";
									$errorLog->filename = 'API / Beer.class.php';
									$errorLog->write();
								}
							}
						}else{
							if($brewerVerified){
								if($userEmailDomain == $brewer->domainName || in_array($permissionsBrewerID, $userBrewerPrivileges)){
									// Allow PUT/PATCH. User is brewery staff.
								}else{
									if(!$users->admin){
										// Deny
										$this->error = true;
										$this->errorMsg = 'Sorry, because this beer is brewer_verified, we limit editing capabilities to brewery staff. If you would like to see an update made to this brewer, please [contact us](https://catalog.beer/contact)';
										$this->responseCode = 403;

										// Log Error
										$errorLog = new LogError();
										$errorLog->errorNumber = 186;
										$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /brewer, brewer_verified';
										$errorLog->badData = "User: $userID / Brewer: $this->brewerID";
										$errorLog->filename = 'API / Beer.class.php';
										$errorLog->write();
									}
								}
							}
						}
					}
				}

				// ----- Verification Badges -----
				$this->cbVerified = false;
				$dbCBV = 0;
				$this->brewerVerified = false;
				$dbBV = 0;

				// Get User Info
				if($users->admin){
					// Catalog.beer Verified
					$this->cbVerified = true;
					$dbCBV = 1;
				}else{
					// Not Catalog.beer Verified
					if(!empty($brewer->domainName)){
						if($userEmailDomain == $brewer->domainName || in_array($this->brewerID, $userBrewerPrivileges)){
							// User has email associated with the brewery, give breweryValidated flag.
							$this->brewerVerified = true;
							$dbBV = 1;

							if(!in_array($this->brewerID, $userBrewerPrivileges)){
								// Give user privileges for this brewer
								$privileges->add($userID, $this->brewerID, true);
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
				// Save to Class
				$this->name = $name;
				$this->style = $style;
				$this->description = $description;
				$this->abv = $abv;
				$this->ibu = $ibu;

				// Validate Fields
				$this->validateName();
				$this->validateStyle();
				$this->validateDescription();
				$this->validateABV();
				$this->validateIBU();

				if(!$this->error){
					$this->lastModified = time();

					// Construct SQL Statement
					if($newBeer){
						// Add Beer (POST/PUT)
						$columns = ['id', 'brewerID', 'name', 'style', 'abv', 'cbVerified', 'brewerVerified', 'lastModified'];
						$params = [$this->beerID, $this->brewerID, $this->name, $this->style, $this->abv, $dbCBV, $dbBV, $this->lastModified];
						if(!empty($this->description)){
							$columns[] = 'description';
							$params[] = $this->description;
						}
						if(!empty($this->ibu)){
							$columns[] = 'ibu';
							$params[] = $this->ibu;
						}
						$placeholders = implode(', ', array_fill(0, count($columns), '?'));
						$sql = "INSERT INTO beer (" . implode(', ', $columns) . ") VALUES ($placeholders)";
					}else{
						// Update Beer (PUT)
						$setClauses = ['brewerID=?', 'name=?', 'style=?', 'abv=?', 'cbVerified=?', 'brewerVerified=?', 'lastModified=?'];
						$setParams = [$this->brewerID, $this->name, $this->style, $this->abv, $dbCBV, $dbBV, $this->lastModified];
						if(!empty($this->description)){
							$setClauses[] = 'description=?';
							$setParams[] = $this->description;
						}
						if(!empty($this->ibu)){
							$setClauses[] = 'ibu=?';
							$setParams[] = $this->ibu;
						}
						$sql = "UPDATE beer SET " . implode(', ', $setClauses) . " WHERE id=?";
						$setParams[] = $this->beerID;
						$params = $setParams;
					}
				}
			}elseif($method == 'PATCH'){
				/*--
				Validate the field if it's different than what is currently stored.
				Check against the $this->{var} which we have from performing a $this->validate($beerID, true) in the beerID flow above for PATCH (Reference #1).
				--*/

				// SQL Update
				$setClauses = array();
				$setParams = array();

				// brewerID
				if(in_array('brewerID', $patchFields)){
					$setClauses[] = "brewerID=?";
					$setParams[] = $this->brewerID;
				}

				// Validate Name
				if(in_array('name', $patchFields)){
					if($name != $this->name){
						// Validate Name
						$this->name = $name;
						$this->validateName();
						if(!$this->error){
							$setClauses[] = "name=?";
							$setParams[] = $this->name;
						}
					}
				}

				// Validate Style
				if(in_array('style', $patchFields)){
					if($style != $this->style){
						// Validate Style
						$this->style = $style;
						$this->validateStyle();
						if(!$this->error){
							$setClauses[] = "style=?";
							$setParams[] = $this->style;
						}
					}
				}

				// Validate Description
				if(in_array('description', $patchFields)){
					if($description != $this->description){
						// Validate Description
						$this->description = $description;
						$this->validateDescription();
						if(!$this->error){
							$setClauses[] = "description=?";
							$setParams[] = $this->description;
						}
					}
				}

				// Validate ABV
				if(in_array('abv', $patchFields)){
					if($abv != $this->abv){
						// Validate ABV
						$this->abv = $abv;
						$this->validateABV();
						if(!$this->error){
							$setClauses[] = "abv=?";
							$setParams[] = $this->abv;
						}
					}
				}

				// Validate IBU
				if(in_array('ibu', $patchFields)){
					if($ibu != $this->ibu){
						// Validate IBU
						$this->ibu = $ibu;
						$this->validateIBU();
						if(!$this->error){
							$setClauses[] = "ibu=?";
							$setParams[] = $this->ibu;
						}
					}
				}

				if(!$this->error && !empty($setClauses)){
					// Prep for Database
					$this->lastModified = time();

					// Construct SQL Statement
					$sql = "UPDATE beer SET lastModified=?, cbVerified=?, brewerVerified=?";
					$params = [$this->lastModified, $dbCBV, $dbBV];
					if(!empty($setClauses)){
						$sql .= ", " . implode(", ", $setClauses);
						$params = array_merge($params, $setParams);
					}
					$sql .= " WHERE id=?";
					$params[] = $this->beerID;
				}
			}

			if(!$this->error && !empty($sql)){
				// Query
				$db->query($sql, $params);
				if(!$db->error){
					// Successful database operation
					if($newBeer){
						// Created New Beer
						$this->responseCode = 201;
						$responseHeaderString = 'Location: https://';
						if(ENVIRONMENT == 'staging'){
							$responseHeaderString .= 'staging.';
						}
						$this->responseHeader = $responseHeaderString . 'catalog.beer/beer/' . $this->beerID;

						// Create Algolia ID
						$algolia = new Algolia();
						$algolia->add('beer', $this->beerID);
					}else{
						$this->responseCode = 200;
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
		// Trim
		$this->name = trim($this->name ?? '');

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
		$this->style = trim($this->style ?? '');

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
		$this->description = trim($this->description ?? '');

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
			// Empty, IBU not provided, input null
			$this->ibu = null;
		}
	}

	// Validate Beer
	public function validate($beerID, $saveToClass){
		// Valid
		$valid = false;

		// Trim
		$beerID = trim($beerID ?? '');

		if(!empty($beerID)){
			// Prep for Database
			$db = new Database();
			$result = $db->query("SELECT brewerID, name, style, description, abv, ibu, cbVerified, brewerVerified, lastModified FROM beer WHERE id=?", [$beerID]);
			if(!$db->error){
				if($result->num_rows == 1){
					// Valid Result
					$valid = true;

					if($saveToClass){
						$array = $result->fetch_assoc();

						$this->beerID = $beerID;
						$this->brewerID = $array['brewerID'];
						$this->name = $array['name'];
						$this->style = $array['style'];
						if(is_null($array['description'])){
							$this->description = null;
						}else{
							$this->description = $array['description'];
						}
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
				}elseif($result->num_rows > 1){
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
				$this->totalCount = $numBeers;
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
			$result = $db->query("SELECT id, name FROM beer ORDER BY name LIMIT ?, ?", [$offset, $count]);
			if(!$db->error){
				while($array = $result->fetch_assoc()){
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
		$numBeers = ($this->totalCount > 0) ? $this->totalCount : $this->countBeers();

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
		$result = $db->query("SELECT COUNT(*) AS numBeers FROM beer");
		if(!$db->error){
			$array = $result->fetch_assoc();
			$count = intval($array['numBeers']);
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

				// Generate Brewer Object JSON
				$brewer->generateBrewerObject(true);
				$beerInfo['brewer'] = $brewer->json;
				$beerInfo['data'] = array();

				// Prep for Query
				$db = new Database();
				$result = $db->query("SELECT id, name, style FROM beer WHERE brewerID=? ORDER BY name", [$brewerID]);
				if(!$db->error){
					if($result->num_rows >= 1){
						// Has Beers associated with it
						$i=0;
						while($array = $result->fetch_assoc()){
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

	public function delete($beerID, $userID){
		if($this->validate($beerID, true)){
			// Get User Information
			$users = new Users();
			$users->validate($userID, true);

			// Get Brewer Privileges
			$privileges = new Privileges();
			$brewerPrivilegesList = $privileges->brewerList($userID);

			if($users->admin || in_array($this->brewerID, $brewerPrivilegesList)){
				// Delete Beer
				$db = new Database();
				$db->query("DELETE FROM beer WHERE id=?", [$beerID]);
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
				$this->errorMsg = 'Sorry, you do not have permission to delete this beer.';
				$this->responseCode = 403;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 199;
				$errorLog->errorMsg = 'Forbidden: DELETE, /beer';
				$errorLog->badData = "User: $userID / brewerID: $this->brewerID / beerID: $beerID";
				$errorLog->filename = 'API / Beer.class.php';
				$errorLog->write();
			}
		}
	}

	public function generateBeerObject(){
		// Generates the Beer Object
		// Generally returned as part of the API output

		// Optional Values that may be stored as null, return as empty ("")
		if(empty($this->description)){$this->description = null;}
		if(empty($this->ibu)){$this->ibu = null;}
		else{$this->ibu = intval($this->ibu);}

		// Get Brewery Data
		if($this->brewerObj !== null){
			$brewer = $this->brewerObj;
		}else{
			$brewer = new Brewer();
			$brewer->validate($this->brewerID, true);
		}
		$brewer->generateBrewerObject(true);

		// Known Values - Required
		$this->json['id'] = $this->beerID;
		$this->json['object'] = 'beer';
		$this->json['name'] = $this->name;
		$this->json['style'] = $this->style;
		$this->json['description'] = $this->description;
		$this->json['abv'] = floatval($this->abv);
		$this->json['ibu'] = $this->ibu;
		$this->json['cb_verified'] = $this->cbVerified;
		$this->json['brewer_verified'] = $this->brewerVerified;
		$this->json['last_modified'] = $this->lastModified;
		$this->json['brewer'] = $brewer->json;
	}

	public function generateBeerSearchObject(){
		// Generates the Beer Object for Algolia
		$array = array();

		// Get Brewery Data
		if($this->brewerObj !== null){
			$brewer = $this->brewerObj;
		}else{
			$brewer = new Brewer();
			$brewer->validate($this->brewerID, true);
		}

		// Get Algolia ID
		$algolia = new Algolia();
		$array['objectID'] = $algolia->getAlgoliaIdByRecord('beer', $this->beerID);

		// Create Output Array
		$array['beerID'] = $this->beerID;
		$array['name'] = $this->name;
		$array['style'] = $this->style;
		if(!empty($this->description)){$array['description'] = $this->description;}
		$array['abv'] = floatval($this->abv);
		if(!empty($this->ibu)){
			$array['ibu'] = intval($this->ibu);
		}
		$array['brewer']['brewerID'] = $brewer->brewerID;
		$array['brewer']['name'] = $brewer->name;

		// Return
		return $array;
	}

	public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
		/*---
		{METHOD} https://api.catalog.beer/beer/{function}
		{METHOD} https://api.catalog.beer/beer/{id}/{function}

		GET https://api.catalog.beer/beer
		GET https://api.catalog.beer/beer/count
		GET https://api.catalog.beer/beer/{beer_id}

		POST https://api.catalog.beer/beer

		PUT https://api.catalog.beer/beer/{beer_id}

		PATCH https://api.catalog.beer/beer/{beer_id}

		DELETE https://api.catalog.beer/beer/{beer_id}
		---*/

		$brewer = new Brewer();

		switch($method){
			case 'GET':
				if(!empty($id) && empty($function)){
					// GET https://api.catalog.beer/beer/{beer_id}
					// Validate ID
					if($this->validate($id, true)){
						// Beer Object JSON
						$this->generateBeerObject();
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
				if(!isset($data->abv)){$data->abv = '';}
				if(!isset($data->ibu)){$data->ibu = '';}

				// Validate API Key for userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Add Beer
				$this->add($data->brewer_id, $data->name, $data->style, $data->description, $data->abv, $data->ibu, $apiKeys->userID, 'POST', '', array());
				if(!$this->error){
					// Beer Object JSON
					$this->generateBeerObject();
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
			case 'PUT':
				// PUT https://api.catalog.beer/beer/{beer_id}
				// Handle Empty Fields
				if(empty($data->brewer_id)){$data->brewer_id = '';}
				if(empty($data->name)){$data->name = '';}
				if(empty($data->style)){$data->style = '';}
				if(empty($data->description)){$data->description = '';}
				if(!isset($data->abv)){$data->abv = '';}
				if(!isset($data->ibu)){$data->ibu = '';}

				// Validate API Key for userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Add/Update/Replace Beer
				$this->add($data->brewer_id, $data->name, $data->style, $data->description, $data->abv, $data->ibu, $apiKeys->userID, 'PUT', $id, array());
				if(!$this->error){
					// Beer Object JSON
					$this->generateBeerObject();
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			case 'PATCH':
				// PATCH https://api.catalog.beer/beer/{beer_id}
				// Which fields are we updating?
				$patchFields = array();

				// Handle Empty Fields
				if(isset($data->brewer_id)){$patchFields[] = 'brewerID';}
				else{$data->brewer_id = '';}

				if(isset($data->name)){$patchFields[] = 'name';}
				else{$data->name = '';}

				if(isset($data->style)){$patchFields[] = 'style';}
				else{$data->style = '';}

				if(isset($data->description)){$patchFields[] = 'description';}
				else{$data->description = '';}

				if(isset($data->abv)){$patchFields[] = 'abv';}
				else{$data->abv = '';}

				if(isset($data->ibu)){$patchFields[] = 'ibu';}
				else{$data->ibu = '';}

				// Validate API Key for userID
				$apiKeys = new apiKeys();
				$apiKeys->validate($apiKey, true);

				// Add/Update/Replace Beer
				$this->add($data->brewer_id, $data->name, $data->style, $data->description, $data->abv, $data->ibu, $apiKeys->userID, 'PATCH', $id, $patchFields);
				if(!$this->error){
					// Beer Object JSON
					$this->generateBeerObject();
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->responseCode = 405;
				$this->json['error'] = true;
				$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
				$this->responseHeader = 'Allow: GET, POST, PUT, PATCH, DELETE';

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