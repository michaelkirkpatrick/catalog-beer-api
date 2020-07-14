<?php
class USAddresses {

	// Properties
	public $locationID = '';	// Required
	public $address1 = '';		
	public $address2 = '';		// Required
	public $city = '';			// City + Sub Code OR zip5
	public $sub_code = '';		// City + Sub Code OR zip5
	public $stateShort = '';
	public $stateLong = '';
	public $zip5 = 0;			// City + Sub Code OR zip5
	public $zip4 = 0;			
	public $telephone = 0;

	// Error Handling
	public $error = false;
	public $errorMsg = null;
	public $validState = array('address1'=>null, 'address2'=>null, 'city'=>null, 'sub_code'=>null, 'zip5'=>null, 'zip4'=>null, 'telephone'=>null);
	public $validMsg = array('address1'=>null, 'address2'=>null, 'city'=>null, 'sub_code'=>null, 'zip5'=>null, 'zip4'=>null, 'telephone'=>null);
	public $responseCode = 200;
	public $json = array();
	private $latLongFound = false;

	// USPS API Key
	private $usps = '';

	// Add Address
	public function add($locationID, $address1, $address2, $city, $sub_code, $zip5, $zip4, $telephone, $userID, $method, $patchFields){
		// Required Classes
		$brewer = new Brewer();
		$db = new Database();
		$location = new Location();
		$privileges = new Privileges();
		$users = new Users();
		
		// Validate Location
		if($location->validate($locationID, true)){
			// location_id is valid, proceed
			// Does an address already exist for this location?
			if($this->validate($locationID, false)){
				$addressOnFile = true;
				$newAddress = false;
			}else{
				$addressOnFile = false;
				$newAddress = true;
			}
			
			// ----- Permissions Check -----
			if($users->validate($userID, true)){
				// Get User's Email Domain Name
				$userEmailDomain = $users->emailDomainName($users->email);

				// Get User Privileges
				$userBrewerPrivileges = $privileges->brewerList($userID);
				
				// Get Brewer Domain Name
				$brewer->validate($location->brewerID, true);

				if($location->cbVerified){
					if($userEmailDomain == $brewer->domainName || in_array($location->brewerID, $userBrewerPrivileges)){
						// Allow PUT/PATCH. User is brewery staff.
					}else{
						if(!$users->admin){
							// Deny
							$this->error = true;
							$this->errorMsg = 'Sorry, because this location is cb_verified, we limit editing capabilities to Catalog.beer Admins. If you would like to see an update made to this location, please [contact us](https://catalog.beer/contact)';
							$this->responseCode = 403;

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 191;
							$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /address, cb_verified';
							$errorLog->badData = "User: $userID / Location: $locationID";
							$errorLog->filename = 'API / USAddresses.class.php';
							$errorLog->write();
						}
					}
				}else{
					if($location->brewerVerified){
						if($userEmailDomain == $brewer->domainName || in_array($location->brewerID, $userBrewerPrivileges)){
							// Allow PUT/PATCH. User is brewery staff.
						}else{
							if(!$users->admin){
								// Deny
								$this->error = true;
								$this->errorMsg = 'Sorry, because this location is brewer_verified, we limit editing capabilities to brewery staff. If you would like to see an update made to this location, please [contact us](https://catalog.beer/contact)';
								$this->responseCode = 403;

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 192;
								$errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /location, brewer_verified';
								$errorLog->badData = "User: $userID / Location: $this->locationID";
								$errorLog->filename = 'API / USAddresses.class.php';
								$errorLog->write();
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
			
			// ----- Check Method -----
			switch($method){
				case 'POST':
					if($addressOnFile){
						// Address already exists, can't POST
						$this->error = true;
						$this->errorMsg = "This location already has an address associated with it, so we can't add one. Try a PUT or PATCH request instead.";
						$this->responseCode = 405;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 189;
						$errorLog->errorMsg = 'Unable to POST. Address already exists.';
						$errorLog->badData = "LocationID: $locationID";
						$errorLog->filename = 'API / USAddresses.class.php';
						$errorLog->write();
					}
					break;
				case 'PATCH':
					if(!$addressOnFile){
						// Address doesn't exists, can't PATCH
						$this->error = true;
						$this->errorMsg = "This location does not have has an address associated with it, so we can't update it. Try a PUT or POST request instead.";
						$this->responseCode = 405;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 190;
						$errorLog->errorMsg = 'Unable to PATCH. Address doesn\'t exist.';
						$errorLog->badData = "LocationID: $locationID";
						$errorLog->filename = 'API / USAddresses.class.php';
						$errorLog->write();
					}
					break;
			}
			
			
			if(!$this->error){
				// Save to Class
				$this->locationID = $locationID;
				$this->address1 = $address1;
				$this->address2 = $address2;
				$this->city = $city;
				$this->sub_code = $sub_code;
				$this->zip5 = $zip5;
				$this->zip4 = $zip4;
				$this->telephone = $telephone;
				
				if($method == 'POST' || $method == 'PUT'){
					// Validate Address
					$this->validateAddress();
					if($this->error && $this->responseCode == 404){
						// USPS API Not able to find address	
						// Clear Error Messages
						$this->error = false;
						$this->responseCode = 200;
						$this->errorMsg = null;
										
						// Try Google Places API
						$formattedAddress = $location->googleMapsAPI($this->locationID, $this->generateGoogleAddressString(), 'findplacefromtext');
						if(!$location->error){
							// Latitude and Longitude Found
							$this->latLongFound = true;
						
							// Parse the formatted address 
							$this->parseGoogleAddressString($formattedAddress);
							
							// Retry USPS Address Validation
							$this->validateAddress();
							
							if($this->error && $this->responseCode == 404){
								// USPS API Failed Again, Save Google API Results
								$this->parseGoogleAddressString($formattedAddress);
							}
							
							// Clear Error Messages
							$this->error = false;
							$this->responseCode = 200;
							$this->errorMsg = null;
						}else{
							// Location Error
							$this->error= true;
							$this->errorMsg = $location->errorMsg;
							$this->responseCode = $location->responseCode;
						}
					}	

					// Validate Telephone
					$this->validateTelephone();

					if(!$this->error){
						// Prep for database
						$dbLocationID = $db->escape($this->locationID);
						$dbAddress1 = $db->escape($this->address1);
						$dbAddress2 = $db->escape($this->address2);
						$dbCity = $db->escape($this->city);
						$dbSubCode = $db->escape($this->sub_code);
						$dbZip5 = $db->escape($this->zip5);
						$dbZip4 = $db->escape($this->zip4);
						$dbTelephone = $db->escape($this->telephone);
						
						if($newAddress){
							// Add New Address (POST/PUT)
							$sql1 = '';
							if(empty($dbAddress1)){
								$sql1 .= ", null";
							}else{
								$sql1 .= ", '$dbAddress1'";
							}
							if(empty($dbZip4)){
								$sql1 .= ", null";
							}else{
								$sql1 .= ", '$dbZip4'";
							}
							if(empty($dbTelephone)){
								$sql1 .= ", null";
							}else{
								$sql1 .= ", '$dbTelephone'";
							}
							$sql = "INSERT INTO US_addresses (locationID, address2, city, sub_code, zip5, address1, zip4, telephone) VALUES ('$dbLocationID', '$dbAddress2', '$dbCity', '$dbSubCode', $dbZip5" . $sql1 . ")";
						}else{
							// Update Address (PUT)
							$sql1 = '';
							if(empty($dbAddress1)){
								$sql1 .= ", address1=null";
							}else{
								$sql1 .= ", address1='$dbAddress1'";
							}
							if(empty($dbZip4)){
								$sql1 .= ", zip4=null";
							}else{
								$sql1 .= ", zip4=$dbZip4";
							}
							if(empty($dbTelephone)){
								$sql1 .= ", telephone=null";
							}else{
								$sql1 .= ", telephone=$dbTelephone";
							}
							$sql = "UPDATE US_addresses SET address2='$dbAddress2', city='$dbCity', sub_code='$dbSubCode', zip5=$dbZip5" . $sql1 . " WHERE locationID='$dbLocationID'";
						}

						$db->query($sql);
						if(!$db->error){
							// Get Latitude and Longitude
							if(!$this->latLongFound){
								$location->googleMapsAPI($this->locationID, $this->generateGoogleAddressString(), 'geocode');
							}
							
							// Update Last Modified
							$location->updateLastModified($this->locationID);
							if($location->error){
								$this->error = true;
								$this->errorMsg = $location->errorMsg;
								$this->responseCode = $location->responseCode;
							}
						}else{
							// Query Error
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
							$this->responseCode = $db->responseCode;
						}
					}
				}
				elseif($method == 'PATCH'){
					// What's getting updated?
					$patchAddress = false;
					$patchTelephone = false;
					
					if(in_array('telephone', $patchFields)){
						// Validate Telephone
						$this->validateTelephone();
						$patchTelephone = true;
					}
					if(count($patchFields) > 1){
						// They'd like to update something about the address, validate it
						$this->validateAddress();
						$patchAddress = true;
						if($this->error && $this->responseCode == 404){
							// USPS API Not able to find address	
							// Clear Error Messages
							$this->error = false;
							$this->responseCode = 200;
							$this->errorMsg = null;
										
							// Try Google Places API
							$formattedAddress = $location->googleMapsAPI($this->locationID, $this->generateGoogleAddressString(), 'findplacefromtext');
							if(!$location->error){
								// Latitude and Longitude Found
								$this->latLongFound = true;
						
								// Parse the formatted address 
								$this->parseGoogleAddressString($formattedAddress);
							
								// Retry USPS Address Validation
								$this->validateAddress();
							
								if($this->error && $this->responseCode == 404){
									// USPS API Failed Again, Save Google API Results
									$this->parseGoogleAddressString($formattedAddress);
								}
							
								// Clear Error Messages
								$this->error = false;
								$this->responseCode = 200;
								$this->errorMsg = null;
							}else{
								// Location Error
								$this->error= true;
								$this->errorMsg = $location->errorMsg;
								$this->responseCode = $location->responseCode;
							}
						}
					}
					if(!$this->error){						
						// Prep for database
						$dbLocationID = $db->escape($this->locationID);
						$sql = "UPDATE US_addresses SET ";
						
						if($patchTelephone){
							$dbTelephone = $db->escape($this->telephone);
							if(!empty($dbTelephone)){
								$sql .= " telephone=$dbTelephone";
							}
						}
						
						if($patchAddress){
							// Escape Fields
							$dbAddress1 = $db->escape($this->address1);
							$dbAddress2 = $db->escape($this->address2);
							$dbCity = $db->escape($this->city);
							$dbSubCode = $db->escape($this->sub_code);
							$dbZip5 = $db->escape($this->zip5);
							$dbZip4 = $db->escape($this->zip4);
							
							// Add Comma?
							if($patchTelephone){
								$sql .= ", ";
							}
							$sql .= "address2='$dbAddress2', city='$dbCity', sub_code='$dbSubCode', zip5=$dbZip5";
							
							// Optional Fields
							if(empty($dbAddress1)){
								$sql .= ", address1=null";
							}else{
								$sql .= ", address1='$dbAddress1'";
							}
							if(empty($dbZip4)){
								$sql .= ", zip4=null";
							}else{
								$sql .= ", zip4=$dbZip4";
							}
						}
						
						$sql .= " WHERE locationID='$dbLocationID'";

						// Run Query
						$db->query($sql);
						if(!$db->error){
							if($patchAddress){
								// Get Latitude and Longitude
								if(!$this->latLongFound){
									$location->googleMapsAPI($this->locationID, $this->generateGoogleAddressString(), 'geocode');
								}
							}
							
							// Update Last Modified
							$location->updateLastModified($this->locationID);
							if($location->error){
								$this->error = true;
								$this->errorMsg = $location->errorMsg;
								$this->responseCode = $location->responseCode;
							}
						}else{
							// Query Error
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
							$this->responseCode = $db->responseCode;
						}
					}
				}
			}
		}else{
			// Invalid Location
			$this->error = true;
			$this->errorMsg = $location->errorMsg;
			// Correct 404 (Not Found) to 400 (Bad Request) for Location Not Found
			if($location->responseCode === 404){
				$this->responseCode = 400;
			}else{
				$this->responseCode = $location->responseCode;
			}

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 57;
			$errorLog->errorMsg = 'Invalid location_id';
			$errorLog->badData = "locationID: $locationID";
			$errorLog->filename = 'API / USAddresses.class.php';
			$errorLog->write();
		}
		
		// Close Database Connection
		$db->close();
	}

	// Validate Address
	private function validateAddress(){
		// Required set parameters: address1, address2, city, sub_code, zip5, zip4

		// Trim Inputs
		$this->address1 = trim($this->address1);
		$this->address2 = trim($this->address2);
		$this->city = trim($this->city);
		$this->sub_code = trim($this->sub_code);
		$this->zip5 = trim($this->zip5);
		$this->zip4 = trim($this->zip4);

		// Substitute Accented Characters
		$accented_chars = array('Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
		$this->address1 = strtr($this->address1, $accented_chars);
		$this->address2 = strtr($this->address2, $accented_chars);
		$this->city = strtr($this->city, $accented_chars);

		// Address Line 1 - Apartment or suite number
		$xmlBody = '<Address1>' . $this->address1 . '</Address1>';

		// Address Line 2 - Street Address
		if(!empty($this->address2)){
			$xmlBody .= '<Address2>' . $this->address2 . '</Address2>';
		}else{
			// Missing Address Line
			$this->error = true;
			$this->validState['address2'] = 'invalid';
			$this->validMsg['address2'] = 'Sorry, we seem to be missing the street address for this location. Please double check your submission.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 58;
			$errorLog->errorMsg = 'Missing street address';
			$errorLog->badData = '';
			$errorLog->filename = 'API / USAddresses.class.php';
			$errorLog->write();
		}

		if(!empty($this->zip5)){
			// Submit using ZIP Code
			$xmlBody .= '<City></City><State></State>';

			// Validate ZIP Code
			if(preg_match('/[0-9]{5}/', $this->zip5)){
				// ZIP5
				$xmlBody .= '<Zip5>' . $this->zip5 . '</Zip5>';
			}else{
				// Invalid ZIP Code
				$this->error = true;
				$this->validState['zip5'] = 'invalid';
				$this->validMsg['zip5'] = 'Sorry, this appears to be an invalid ZIP Code (zip5). Ensure you have submitted a five digit ZIP code.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 59;
				$errorLog->errorMsg = 'Invalid Zip5';
				$errorLog->badData = "Zip5: " . $this->zip5;
				$errorLog->filename = 'API / USAddresses.class.php';
				$errorLog->write();
			}

			// Validate ZIP Code + 4
			if(!empty($this->zip4)){
				if(preg_match('/[0-9]{4}/', $this->zip4)){
					// ZIP4
					$xmlBody .= '<Zip4>' . $this->zip4 . '</Zip4>';
				}else{
					// Invalid ZIP Code
					$this->error = true;
					$this->validState['zip4'] = 'invalid';
					$this->validMsg['zip4'] = 'Sorry, this appears to be an invalid ZIP Code + 4 (zip4). Ensure you have submitted a four digit ZIP Code + 4.';
					$this->responseCode = 400;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 60;
					$errorLog->errorMsg = 'Invalid Zip4';
					$errorLog->badData = "Zip4: " . $this->zip4;
					$errorLog->filename = 'API / USAddresses.class.php';
					$errorLog->write();
				}
			}else{
				// Empty ZIP4
				$xmlBody .= '<Zip4></Zip4>';
			}

			if(!$this->error){
				// Submit to Postal Service API
				$this->uspsAPI($xmlBody);
			}
		}else{
			// No ZIP Code provided, City & State Required

			// Check City
			if(!empty($this->city)){
				$xmlBody .= '<City>' . $this->city . '</City>';
			}else{
				// Missing City
				$this->error = true;
				$this->validState['city'] = 'invalid';
				$this->validMsg['city'] = 'What city is this location in? If you don\'t know the city, you can alternatively provide the ZIP Code.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 61;
				$errorLog->errorMsg = 'Missing City';
				$errorLog->badData = '';
				$errorLog->filename = 'API / USAddresses.class.php';
				$errorLog->write();
			}

			// Check State
			if(!empty($this->sub_code)){
				$subdivisions = new Subdivisions();
				if($subdivisions->validate($this->sub_code, true)){
					// Get State Info
					$this->stateShort = substr($this->sub_code, 3, 2);
					$this->stateLong = $subdivisions->sub_name;

					// XML
					$xmlBody .= '<State>' . $this->stateShort . '</State>';
					$this->validState['sub_code'] = 'valid';
				}else{
					// Invalid Subdivision
					$this->error = true;
					$this->validState['sub_code'] = 'invalid';
					$this->validMsg['sub_code'] = 'Sorry, this appears to be an invalid sub_code. Please double check the parameter.';
					$this->responseCode = 400;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 62;
					$errorLog->errorMsg = 'Invalid sub_code';
					$errorLog->badData = "sub_code: " . $this->sub_code;
					$errorLog->filename = 'API / USAddresses.class.php';
					$errorLog->write();
				}
			}else{
				// Missing sub_code
				$this->error = true;
				$this->validState['sub_code'] = 'invalid';
				$this->validMsg['sub_code'] = 'Sorry, we seem to be missing the sub_code for this location. Please check your submission.';
				$this->responseCode = 400;
			}

			if(!$this->error){
				// Submit to Postal Service API
				$xmlBody .= '<Zip5></Zip5><Zip4></Zip4>';
				$this->uspsAPI($xmlBody);
			}
		}
	}

	private function uspsAPI($xmlBody){
	
		// Build XML
		$xml = '<AddressValidateRequest USERID="' . $this->usps . '"><Address ID=\'1\'>' . $xmlBody . '</Address></AddressValidateRequest>';

		// Start cURL
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => "https://secure.shippingapis.com/ShippingAPI.dll?API=Verify&XML=" . urlencode($xml),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
			CURLOPT_HTTPHEADER => array(
				"cache-control: no-cache"
			),
		));

		$response = curl_exec($curl);
		$err = curl_error($curl);

		curl_close($curl);

		if($err){
			// cURL Error
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			$this->responseCode = 500;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 63;
			$errorLog->errorMsg = 'cURL Error';
			$errorLog->badData = $err;
			$errorLog->filename = 'API / USAddresses.class.php';
			$errorLog->write();
		}else{
			// Response Received
			$responseObj = new SimpleXMLElement($response);
			if(isset($responseObj->Error)){
				// Error
				$this->error = true;
				$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
				$this->responseCode = 500;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 64;
				$errorLog->errorMsg = 'USPS API Error';
				$errorLog->badData = 'Body: ' . $xml . ' // Response: ' . $response;
				$errorLog->filename = 'API / USAddresses.class.php';
				$errorLog->write();
			}elseif(isset($responseObj->Address->Error)){
				if(trim($responseObj->Address->Error->Description) == 'Invalid City.'){
					// Invalid City
					$this->error = true;
					$this->validState['city'] = 'invalid';
					$this->validMsg['city'] = 'Invalid City. Please check what you\'ve typed and try again.';
					$this->responseCode = 404;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 115;
					$errorLog->errorMsg = 'Invalid City - USPS Address Validation Error';
					$errorLog->badData = 'Body: ' . $xml . ' // Response: ' . $response;
					$errorLog->filename = 'API / USAddresses.class.php';
					$errorLog->write();
				}elseif(trim($responseObj->Address->Error->Description) == 'Address Not Found.'){
					// Not Found
					$this->error = true;
					$this->responseCode = 404;
					$this->errorMsg = 'Address Not Found.';
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 201;
					$errorLog->errorMsg = 'Address Not Found - USPS Address Validation Error';
					$errorLog->badData = 'Body: ' . $xml . ' // Response: ' . $response;
					$errorLog->filename = 'API / USAddresses.class.php';
					$errorLog->write();
				}else{
					// Other Error
					$this->error = true;
					$this->errorMsg = htmlspecialchars(trim($responseObj->Address->Error->Description)) . ' Please check your entry and try again.';
					$this->responseCode = 400;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 106;
					$errorLog->errorMsg = 'USPS Address Validation Error';
					$errorLog->badData = 'Body: ' . $xml . ' // Response: ' . $response;
					$errorLog->filename = 'API / USAddresses.class.php';
					$errorLog->write();
				}
			}else{
				// Success	
				if(isset($responseObj->Address->Address1)){
					$this->address1 = ucwords(strtolower($responseObj->Address->Address1));
				}else{
					$this->address1 = '';
				}
				$this->address2 = ucwords(strtolower($responseObj->Address->Address2));
				$this->city = ucwords(strtolower($responseObj->Address->City));
				$this->stateShort = strval($responseObj->Address->State);
				$this->zip5 = intval($responseObj->Address->Zip5);
				$this->zip4 = intval($responseObj->Address->Zip4);
				if(empty($this->zip4)){
					$this->zip4 = 0;
				}

				// Need to derive sub_code and state_long?
				if(empty($this->sub_code && $this->stateLong)){
					$subdivisions = new Subdivisions();
					$sub_code = 'US-' . $this->stateShort;
					if($subdivisions->validate($sub_code, true)){
						$this->sub_code = $subdivisions->sub_code;
						$this->stateLong = $subdivisions->sub_name;
					}
				}
			}
		}
	}

	// Validate Telephone
	private function validateTelephone(){
		// Must set telephone

		// Trim
		$this->telephone = trim($this->telephone);

		if(!empty($this->telephone)){
			// Eliminate every char except 0-9
			$this->telephone = preg_replace("/[^0-9]/", '', $this->telephone);

			// Check String Length
			if(strlen($this->telephone) == 11){
				$this->telephone = preg_replace("/^1/", '', $this->telephone);
			}

			if(strlen($this->telephone) == 10){
				// Good to go!
				// Make sure it's an integer
				$this->telephone = intval($this->telephone);
			}else{
				// Wrong Length of String
				$this->error = true;
				$this->validState['telephone'] = 'invalid';
				$this->validMsg['telephone'] = 'The telephone number you submitted appears to be invalid. We are looking for a ten-digit phone number similar to ###-###-####.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 65;
				$errorLog->errorMsg = 'Invalid telephone number';
				$errorLog->badData = "telephone: $this->telephone";
				$errorLog->filename = 'API / USAddresses.class.php';
				$errorLog->write();
			}
		}else{
			$this->telephone = 0;
		}
	}
	
	// Generate Google API Address String
	private function generateGoogleAddressString(){
		// Address2
		$addressString = $this->address2;
		
		// Address1
		if(!empty($this->address1)){
			$addressString .= ' ' . $this->address1;
		}
		
		$addressString .= ', ';
		
		// City
		if(!empty($this->city)){
			$addressString .= $this->city . ', ';
		}
		
		// State
		if(!empty($this->stateShort)){
			$addressString .= $this->stateShort;
		}
		
		// ZIP Code
		if(!empty($this->zip5)){
			$addressString .= ' ' . $this->zip5;
			if(!empty($this->zip4)){
				$addressString .= '-' . $this->zip4;
			}
		}
		
		// Add United States of America
		$addressString .= ', USA';
		
		return $addressString;
	}
	
	// Parse Google Formatted Address String
	private function parseGoogleAddressString($addressString){	
		// Regular Expression
		$regex = '/([[:alnum:] ]+)([#0-9]+)?, ([[:alnum:] ]+), ([A-Z]{2}) ([0-9]{5})(-[0-9]{4})?/m';
		preg_match_all($regex, $addressString, $matches, PREG_SET_ORDER, 0);
				
		// Match to the class
		$this->address1 = $matches[0][2];
		$this->address2 = $matches[0][1];
		$this->city = $matches[0][3];
		$this->sub_code = 'US-' . $matches[0][4];
		$this->zip5 = $matches[0][5];
	}

	// Validate
	public function validate($locationID, $saveToClass){
		$valid = false;

		if(!empty($locationID)){
			// Prep for Database
			$db = new Database();
			$dbLocationID = $db->escape($locationID);

			$db->query("SELECT address1, address2, city, sub_code, zip5, zip4, telephone FROM US_addresses WHERE locationID='$dbLocationID'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid
					$valid = true;

					// Save to Class?
					if($saveToClass){
						$array = $db->resultArray();
						$this->locationID = $locationID;
						$this->address1 = $array['address1'];
						$this->address2 = $array['address2'];
						$this->city = $array['city'];
						$this->sub_code = $array['sub_code'];
						$this->zip5 = intval($array['zip5']);
						$this->zip4 = intval($array['zip4']);
						$this->telephone = intval($array['telephone']);

						$subdivisions = new Subdivisions();
						if($subdivisions->validate($this->sub_code, true)){
							$this->stateShort = substr($this->sub_code, 3, 2);
							$this->stateLong = $subdivisions->sub_name;
						}
					}
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Missing LocationID
			$this->error = true;
			$this->errorMsg = 'We seem to be missing the location_id. We\'ll need that to look up the location\'s address.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 66;
			$errorLog->errorMsg = 'Missing locationID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / USAddresses.class.php';
			$errorLog->write();
		}

		// Return
		return $valid;
	}
	
	public function api($method, $id, $apiKey, $data){
		/*---
		{METHOD} https://api.catalog.beer/address/{function}
		{METHOD} https://api.catalog.beer/address/{id}/{function}
		
		GET https://api.catalog.beer/address/{location_id}

		POST https://api.catalog.beer/address/{location_id}
				
		PATCH https://api.catalog.beer/address/{location_id}
		---*/
		
		// Required Classes
		$location = new Location();
		$apiKeys = new apiKeys();
		
		// Validate API Key for userID
		$apiKeys->validate($apiKey, true);
		
		// Handle Empty Fields
		$patchFields = array();
		
		if(isset($data->address1)){$patchFields[] = 'address1';}
		else{$data->address1 = '';}

		if(isset($data->address2)){$patchFields[] = 'address2';}
		else{$data->address2 = '';}

		if(isset($data->city)){$patchFields[] = 'city';}
		else{$data->city = '';}

		if(isset($data->sub_code)){$patchFields[] = 'sub_code';}
		else{$data->sub_code = '';}

		if(isset($data->zip5)){$patchFields[] = 'zip5';}
		else{$data->zip5 = '';}

		if(isset($data->zip4)){$patchFields[] = 'zip4';}
		else{$data->zip4 = '';}

		if(isset($data->telephone)){$patchFields[] = 'telephone';}
		else{$data->telephone = '';}
		
		switch($method){
			case 'PATCH':
				// PATCH https://api.catalog.beer/address/{location_id}
				$this->add($id, $data->address1, $data->address2, $data->city, $data->sub_code, $data->zip5, $data->zip4, $data->telephone, $apiKeys->userID, 'PATCH', $patchFields);
				break;
			case 'POST':
				// POST https://api.catalog.beer/address/{location_id}
				$this->add($id, $data->address1, $data->address2, $data->city, $data->sub_code, $data->zip5, $data->zip4, $data->telephone, $apiKeys->userID, 'POST', array());
				break;
			case 'PUT':
				// PUT https://api.catalog.beer/address/{location_id}
				$this->add($id, $data->address1, $data->address2, $data->city, $data->sub_code, $data->zip5, $data->zip4, $data->telephone, $apiKeys->userID, 'PUT', array());
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->error = true;
				$this->errorMsg = "Invalid HTTP method for this endpoint.";
				$this->responseCode = 405;
				$this->responseHeader = 'Allow: POST, PUT, PATCH';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 183;
				$errorLog->errorMsg = 'Invalid Method (/address)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / USAddresses.class.php';
				$errorLog->write();
		}
		
		if(!$this->error){
			// Return Location Object
			$location = new Location();
			$location->validate($this->locationID, true);
			$location->generateLocationObject();
			$this->json = $location->json;
		}else{
			// Error Adding Address
			$this->json['error'] = true;
			$this->json['error_msg'] = $this->errorMsg;
			$this->json['valid_state'] = $this->validState;
			$this->json['valid_msg'] = $this->validMsg;
		}
	}
}
?>
