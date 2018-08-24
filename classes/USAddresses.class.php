<?php
class USAddresses {
	
	public $locationID = '';
	public $address1 = '';
	public $address2 = '';
	public $city = '';
	public $sub_code = '';
	public $stateShort = '';
	public $stateLong = '';
	public $zip5 = 0;
	public $zip4 = 0;
	public $telephone = 0;
	
	public $error = false;
	public $errorMsg = '';
	public $validState = array('location_id'=>'', 'address1'=>'', 'address2'=>'', 'city'=>'', 'sub_code'=>'', 'zip5'=>'', 'zip4'=>'', 'telephone'=>'');
	public $validMsg = array('location_id'=>'', 'address1'=>'', 'address2'=>'', 'city'=>'', 'sub_code'=>'', 'zip5'=>'', 'zip4'=>'', 'telephone'=>'');
	
	private $usps = '';
	
	// Add Address
	public function add($locationID, $address1, $address2, $city, $sub_code, $zip5, $zip4, $telephone){
		// Address Already Exists?
		if(!$this->validate($locationID, false)){
			// Save to Class
			$this->locationID = $locationID;
			$this->address1 = $address1;
			$this->address2 = $address2;
			$this->city = $city;
			$this->sub_code = $sub_code;
			$this->zip5 = $zip5;
			$this->zip4 = $zip4;
			$this->telephone = $telephone;

			// Validate Location
			$location = new Location();
			if(!$location->validate($this->locationID, true)){
				// Invalid Location
				$this->error = true;
				$this->errorMsg = 'Sorry, we don\'t have any locations with the location_id you provided';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 57;
				$errorLog->errorMsg = 'Invalid location_id';
				$errorLog->badData = "locationID: $locationID";
				$errorLog->filename = 'API / USAddresses.class.php';
				$errorLog->write();
			}

			// Validate Address
			$this->validateAddress();

			// Validate Telephone
			$this->validateTelephone();

			// Add to Database?
			if(!$this->error){
				// Prep for database
				$db = new Database();
				$dbLocationID = $db->escape($this->locationID);
				$dbAddress1 = $db->escape($this->address1);
				$dbAddress2 = $db->escape($this->address2);
				$dbCity = $db->escape($this->city);
				$dbSubCode = $db->escape($this->sub_code);
				$dbZip5 = $db->escape($this->zip5);
				$dbZip4 = $db->escape($this->zip4);
				$dbTelephone = $db->escape($this->telephone);

				$db->query("INSERT INTO US_addresses (locationID, address1, address2, city, sub_code, zip5, zip4, telephone) VALUES ('$dbLocationID', '$dbAddress1', '$dbAddress2', '$dbCity', '$dbSubCode', '$dbZip5', '$dbZip4', '$dbTelephone')");
				if(!$db->error){
					// Get Latitude and Longitude
					$location->addLatLong($this->locationID, $this->address2 . ' ' . $this->address1 . ', ' . $this->city . ', ' . $this->stateShort . ' ' . $this->zip5);
					
					// Update Brewer lastModified Timestamp
					$brewer = new Brewer();
					$brewer->updateModified($location->brewerID);
				}else{
					// Query Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
				}
			}
		}else{
			// Location Already Has Address
			$this->error = true;
			$this->errorMsg = 'Sorry, this location already has an address. Perhaps you meant to edit the address?';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 76;
			$errorLog->errorMsg = 'Location already has address';
			$errorLog->badData = "locationID: $locationID";
			$errorLog->filename = 'API / USAddresses.class.php';
			$errorLog->write();
		}
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
			$this->validState['address2'] = 'Sorry, we seem to be missing the street address for this location. Please double check your submission.';
			
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
				}else{
					// Invalid Subdivision
					$this->error = true;
					$this->validState['sub_code'] = 'invalid';
					$this->validMsg['sub_code'] = 'Sorry, this appears to be an invalid sub_code. Please double check the parameter.';
					
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
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 115;
					$errorLog->errorMsg = 'Invalid City - USPS Address Validation Error';
					$errorLog->badData = 'Body: ' . $xml . ' // Response: ' . $response;
					$errorLog->filename = 'API / USAddresses.class.php';
					$errorLog->write();
				}else{
					// Other Error
					$this->error = true;
					$this->errorMsg = htmlspecialchars(trim($responseObj->Address->Error->Description)) . ' Please check your entry and try again.';

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

			if(strlen($this->telephone) != 10){
				// Wrong Length of String
				$this->error = true;
				$this->validState['telephone'] = 'invalid';
				$this->validMsg['telephone'] = 'The telephone number you submitted appears to be invalid. We are looking for a ten-digit phone number similar to ###-###-####.';

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
	
	// Validate
	public function validate($locationID, $saveToClass){
		// Valid
		$valid = false;
		
		// Trim
		$locationID = trim($locationID);
		
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
				}elseif($db->result->num_rows > 1){
					// Too Many Results
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 67;
					$errorLog->errorMsg = 'Duplicate locationIDs';
					$errorLog->badData = '';
					$errorLog->filename = 'API / USAddresses.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing LocationID
			$this->error = true;
			$this->errorMsg = 'We seem to be missing the location_id. We\'ll need that to look up the location\'s address.';
			
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
}
?>