<?php
class USAddresses {

    // Properties
    public $locationID = '';    // Required
    public $address1 = '';
    public $address2 = '';      // Required
    public $city = '';          // City + Sub Code OR zip5
    public $sub_code = '';      // City + Sub Code OR zip5
    public $stateShort = '';
    public $stateLong = '';
    public $zip5 = '';          // City + Sub Code OR zip5. String, not int: ZIP
    public $zip4 = '';          // codes have significant leading zeros (01085).
    public $telephone = 0;      // int is fine: NANP area codes never start with 0

    // Error Handling
    public $error = false;
    public $errorMsg = null;
    public $validState = array('address1'=>null, 'address2'=>null, 'city'=>null, 'sub_code'=>null, 'zip5'=>null, 'zip4'=>null, 'telephone'=>null);
    public $validMsg = array('address1'=>null, 'address2'=>null, 'city'=>null, 'sub_code'=>null, 'zip5'=>null, 'zip4'=>null, 'telephone'=>null);
    public $responseCode = 200;
    public $responseHeader = '';
    public $json = array();
    private $latLongFound = false;
    private $latitude = null;
    private $longitude = null;

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
            // saveToClass on PATCH: a partial patch has to merge with the
            // stored address before re-validating, so load it. Previously this
            // passed false and the un-patched fields went to Google as empty
            // strings — a two-field patch validated a mostly-empty address.
            if($this->validate($locationID, $method == 'PATCH')){
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
                                $errorLog->badData = "User: $userID / Location: $locationID";
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
                /*--
                Save to Class. On PATCH, validate() above loaded the stored
                address, so only the fields the request actually sent may
                overwrite it — the rest keep their stored values and the merged
                whole goes to Google as one complete address. POST and PUT
                replace everything, as before.
                --*/
                $patching = ($method == 'PATCH');
                $this->locationID = $locationID;
                if(!$patching || in_array('address1', $patchFields)){$this->address1 = $address1;}
                if(!$patching || in_array('address2', $patchFields)){$this->address2 = $address2;}
                if(!$patching || in_array('city', $patchFields)){$this->city = $city;}
                if(!$patching || in_array('sub_code', $patchFields)){$this->sub_code = $sub_code;}
                if(!$patching || in_array('zip5', $patchFields)){$this->zip5 = $zip5;}
                if(!$patching || in_array('zip4', $patchFields)){$this->zip4 = $zip4;}
                if(!$patching || in_array('telephone', $patchFields)){$this->telephone = $telephone;}

                /*--
                city+sub_code and zip5 are two spellings of the same fact — the
                locality. When a patch supplies one group and not the other,
                the stored other group describes the PREVIOUS locality, and
                merging it in hands Google a contradiction it resolves
                unpredictably: a stale city next to a fresh ZIP degraded CASS
                enough to store an unnormalized "# 800" unit, and a stale
                CA sub_code beat a patched Massachusetts ZIP outright. Drop
                the un-patched group instead — Google re-derives it from the
                one the client vouched for. A patch touching neither group
                (address1, address2, telephone) keeps the stored pair, which
                was validated together and cannot disagree with itself.
                --*/
                if($patching){
                    $zipPatched = in_array('zip5', $patchFields);
                    $cityPatched = in_array('city', $patchFields) || in_array('sub_code', $patchFields);
                    if($zipPatched && !$cityPatched){
                        $this->city = '';
                        $this->sub_code = '';
                        $this->stateShort = '';
                        $this->stateLong = '';
                    }elseif($cityPatched && !$zipPatched){
                        $this->zip5 = '';
                        $this->zip4 = '';
                    }
                }

                if($method == 'POST' || $method == 'PUT'){
                    // Validate & standardize the address (Google Address Validation)
                    $this->validateAddress();

                    // Validate Telephone
                    $this->validateTelephone();

                    if(!$this->error){
                        if($newAddress){
                            // Add New Address (POST/PUT)
                            $columns = ['locationID', 'address2', 'city', 'sub_code', 'zip5', 'address1', 'zip4', 'telephone'];
                            $params = [
                                $this->locationID,
                                $this->address2,
                                $this->city,
                                $this->sub_code,
                                $this->zip5,
                                !empty($this->address1) ? $this->address1 : null,
                                !empty($this->zip4) ? $this->zip4 : null,
                                !empty($this->telephone) ? $this->telephone : null
                            ];
                            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                            $sql = "INSERT INTO US_addresses (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                            $db->query($sql, $params);
                        }else{
                            // Update Address (PUT)
                            $params = [
                                $this->address2,
                                $this->city,
                                $this->sub_code,
                                $this->zip5,
                                !empty($this->address1) ? $this->address1 : null,
                                !empty($this->zip4) ? $this->zip4 : null,
                                !empty($this->telephone) ? $this->telephone : null,
                                $this->locationID
                            ];
                            $sql = "UPDATE US_addresses SET address2=?, city=?, sub_code=?, zip5=?, address1=?, zip4=?, telephone=? WHERE locationID=?";
                            $db->query($sql, $params);
                        }

                        if(!$db->error){
                            // Store Latitude and Longitude (captured during validation; fall back to a geocode lookup)
                            if($this->latLongFound){
                                $location->saveCoordinates($this->locationID, $this->latitude, $this->longitude);
                            }else{
                                $location->googleMapsAPI($this->locationID, $this->generateGoogleAddressString(), 'geocode');
                            }

                            // Update Last Modified
                            $location->updateLastModified($this->locationID);
                            if($location->error){
                                $this->error = true;
                                $this->errorMsg = $location->errorMsg;
                                $this->responseCode = $location->responseCode;
                            }

                            // Sync to Algolia. An address write was previously
                            // invisible to the index even though the location
                            // record carries the address block — and the parent
                            // brewer now denormalizes city/state/coordinates
                            // from it, so both records need refreshing.
                            //
                            // Re-validate rather than reusing $location: the
                            // coordinates were rewritten moments ago by
                            // saveCoordinates()/googleMapsAPI(), so the copy
                            // loaded at the top of add() is already stale.
                            if(!$this->error){
                                $syncLocation = new Location();
                                if($syncLocation->validate($this->locationID, true)){
                                    $algolia = new Algolia();
                                    $algolia->saveObject('catalog', $syncLocation->generateLocationSearchObject());
                                    Brewer::refreshSearchObject($syncLocation->brewerID, true);
                                }
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
                    /*--
                    Any address field re-validates the (merged) address. The old
                    gate was count($patchFields) > 1, which read "more than just
                    the telephone" but actually meant a single address field
                    patched alone — {"city": "..."} — validated nothing, built
                    no clauses, wrote nothing, and still bumped lastModified.
                    --*/
                    if(count(array_diff($patchFields, array('telephone'))) > 0){
                        $this->validateAddress();
                        $patchAddress = true;
                    }
                    if(!$this->error){
                        // Build parameterized query
                        $setClauses = array();
                        $setParams = array();

                        if($patchTelephone){
                            // NULL when cleared — the old !empty() guard could
                            // write a number but never remove one.
                            $setClauses[] = "telephone=?";
                            $setParams[] = !empty($this->telephone) ? $this->telephone : null;
                        }

                        if($patchAddress){
                            $setClauses[] = "address2=?";
                            $setParams[] = $this->address2;
                            $setClauses[] = "city=?";
                            $setParams[] = $this->city;
                            $setClauses[] = "sub_code=?";
                            $setParams[] = $this->sub_code;
                            $setClauses[] = "zip5=?";
                            $setParams[] = $this->zip5;

                            // Optional fields
                            $setClauses[] = "address1=?";
                            $setParams[] = !empty($this->address1) ? $this->address1 : null;
                            $setClauses[] = "zip4=?";
                            $setParams[] = !empty($this->zip4) ? $this->zip4 : null;
                        }

                        if(!empty($setClauses)){
                            $sql = "UPDATE US_addresses SET " . implode(", ", $setClauses) . " WHERE locationID=?";
                            $setParams[] = $this->locationID;
                            $db->query($sql, $setParams);
                        }

                        if($db->error){
                            // Query Error
                            $this->error = true;
                            $this->errorMsg = $db->errorMsg;
                            $this->responseCode = $db->responseCode;
                        }
                        // Post-write work only when something was written. An
                        // empty PATCH body used to fall through to
                        // updateLastModified() and bump the timestamp with
                        // nothing stored — the same phantom-write shape as the
                        // rest of this cluster. It returns the unchanged
                        // location object, not an error.
                        if(!$this->error && !empty($setClauses)){
                            if($patchAddress){
                                // Store Latitude and Longitude (captured during validation; fall back to a geocode lookup)
                                if($this->latLongFound){
                                    $location->saveCoordinates($this->locationID, $this->latitude, $this->longitude);
                                }else{
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

                            // Sync to Algolia. An address write was previously
                            // invisible to the index even though the location
                            // record carries the address block — and the parent
                            // brewer now denormalizes city/state/coordinates
                            // from it, so both records need refreshing.
                            //
                            // Re-validate rather than reusing $location: the
                            // coordinates were rewritten moments ago by
                            // saveCoordinates()/googleMapsAPI(), so the copy
                            // loaded at the top of add() is already stale.
                            if(!$this->error){
                                $syncLocation = new Location();
                                if($syncLocation->validate($this->locationID, true)){
                                    $algolia = new Algolia();
                                    $algolia->saveObject('catalog', $syncLocation->generateLocationSearchObject());
                                    Brewer::refreshSearchObject($syncLocation->brewerID, true);
                                }
                            }
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
        $this->address1 = trim($this->address1 ?? '');
        $this->address2 = trim($this->address2 ?? '');
        $this->city = trim($this->city ?? '');
        $this->sub_code = trim($this->sub_code ?? '');
        $this->zip5 = trim($this->zip5 ?? '');
        $this->zip4 = trim($this->zip4 ?? '');

        // Substitute Accented Characters
        $accented_chars = array('Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
        $this->address1 = strtr($this->address1 ?? '', $accented_chars);
        $this->address2 = strtr($this->address2 ?? '', $accented_chars);
        $this->city = strtr($this->city ?? '', $accented_chars);

        // Street Address (required)
        if(empty($this->address2)){
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
            // Validate ZIP Code
            if(!preg_match('/^[0-9]{5}$/', $this->zip5)){
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

            // Validate ZIP Code + 4 (optional)
            if(!empty($this->zip4) && !preg_match('/^[0-9]{4}$/', $this->zip4)){
                // Invalid ZIP Code + 4
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

            // Derive state from sub_code if provided (optional; Google infers it from the ZIP otherwise)
            if(empty($this->stateShort) && !empty($this->sub_code)){
                $this->stateShort = substr($this->sub_code, 3, 2);
            }
        }else{
            // No ZIP Code provided, City & State Required

            // Check City
            if(empty($this->city)){
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
                    $this->stateShort = substr($this->sub_code ?? '', 3, 2);
                    $this->stateLong = $subdivisions->sub_name;
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
        }

        if(!$this->error){
            // Submit to Google Address Validation API
            $this->googleAddressValidationAPI();
        }
    }

    private function googleAddressValidationAPI(){
        // Build the address payload from class properties
        $addressLines = array();
        if(!empty($this->address2)){
            $addressLines[] = $this->address2;
        }
        if(!empty($this->address1)){
            $addressLines[] = $this->address1;
        }

        $address = array(
            'regionCode' => 'US',
            'addressLines' => $addressLines
        );
        if(!empty($this->city)){
            $address['locality'] = $this->city;
        }
        if(!empty($this->stateShort)){
            $address['administrativeArea'] = $this->stateShort;
        }
        if(!empty($this->zip5)){
            $address['postalCode'] = !empty($this->zip4) ? ($this->zip5 . '-' . $this->zip4) : strval($this->zip5);
        }

        $requestBody = json_encode(array(
            'address' => $address,
            'enableUspsCass' => true
        ));

        // Build URL
        $url = 'https://addressvalidation.googleapis.com/v1:validateAddress?key=' . GOOGLE_ADDRESS_VALIDATION_KEY;

        // Start cURL
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Accept: application/json'
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // Log data for error reporting
        $requestData = 'Request: ' . $requestBody;

        if($err){
            // cURL Error
            $this->error = true;
            $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
            $this->responseCode = 500;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 265;
            $errorLog->errorMsg = 'Google Address Validation cURL Error';
            $errorLog->badData = $err;
            $errorLog->filename = 'API / USAddresses.class.php';
            $errorLog->write();
            return;
        }

        if($httpCode !== 200){
            // API Error (bad request, auth/key, rate limit, etc.)
            $this->error = true;
            $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
            $this->responseCode = 500;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 266;
            $errorLog->errorMsg = 'Google Address Validation API Error';
            $errorLog->badData = 'HTTP ' . $httpCode . ' // ' . $requestData . ' // Response: ' . $response;
            $errorLog->filename = 'API / USAddresses.class.php';
            $errorLog->write();
            return;
        }

        // Parse JSON Response
        $responseData = json_decode($response, true);
        $result = $responseData['result'] ?? array();
        $verdict = $result['verdict'] ?? array();
        $granularity = $verdict['validationGranularity'] ?? '';
        $uspsData = $result['uspsData'] ?? array();
        $std = $uspsData['standardizedAddress'] ?? array();
        $postal = $result['address']['postalAddress'] ?? array();
        $geo = $result['geocode']['location'] ?? array();

        if(empty($result) || empty($verdict)){
            // Empty / unparseable result
            $this->error = true;
            $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
            $this->responseCode = 500;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 268;
            $errorLog->errorMsg = 'Google Address Validation Empty Result';
            $errorLog->badData = $requestData . ' // Response: ' . $response;
            $errorLog->filename = 'API / USAddresses.class.php';
            $errorLog->write();
            return;
        }

        // Accept only building-level matches — a human can physically find it.
        // DPV (mail deliverability) is intentionally informational, not a gate.
        $buildingLevel = array('SUB_PREMISE', 'PREMISE', 'PREMISE_PROXIMITY');
        if(!in_array($granularity, $buildingLevel)){
            // Not a findable place
            $this->error = true;
            $this->errorMsg = 'We were not able to find a location based on the address you provided. Please double check the street, city, and ZIP code.';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 267;
            $errorLog->errorMsg = 'Address Not Found - Google Address Validation';
            $errorLog->badData = 'Granularity: ' . $granularity . ' // ' . $requestData . ' // Response: ' . $response;
            $errorLog->filename = 'API / USAddresses.class.php';
            $errorLog->write();
            return;
        }

        // ----- Accepted: populate standardized fields -----
        // Split Google's combined ZIP ("53508" or "98271-9157") once — it fills
        // whatever the CASS block leaves out below.
        $postalCode = $postal['postalCode'] ?? '';
        if(strpos($postalCode, '-') !== false){
            list($postalZip5, $postalZip4) = explode('-', $postalCode, 2);
        }else{
            $postalZip5 = $postalCode;
            $postalZip4 = '';
        }

        if(!empty($std)){
            // CASS street and unit. Google folds any secondary unit
            // (STE/APT/etc.) into firstAddressLine rather than
            // secondAddressLine, so split it back out to keep address1 (unit)
            // and address2 (street) separate.
            if(!empty($std['secondAddressLine'])){
                $street = $std['firstAddressLine'] ?? '';
                $secondary = $std['secondAddressLine'];
            }else{
                list($street, $secondary) = $this->splitSecondaryUnit($std['firstAddressLine'] ?? '');
            }
            /*--
            Street display prefers Google's postal lines, which spell the
            street out in full where CASS abbreviates it — "WOOD RED RD NE"
            vs "Woodinville Redmond Rd NE" for the same premise (I4). But
            postalAddress.addressLines is substantially an echo of the input
            with no guaranteed structure: a bare unit number can arrive as
            line one ahead of the street (["800", "1270 Lincoln Ave"]), and a
            spelling correction can arrive split across lines
            (["2215 India", "India St"]). So the postal street is accepted
            only when it is recognizably an expansion of the CASS street —
            same house number, at least as many words. Everything else keeps
            CASS, which is the authority on structure.
            --*/
            $lines = $postal['addressLines'] ?? array();
            if(count($lines) > 1){
                $postalStreet = $lines[0];
                $postalSecondary = $lines[1];
            }else{
                list($postalStreet, $postalSecondary) = $this->splitSecondaryUnit($lines[0] ?? '');
            }
            if(!empty($postalStreet) && !empty($street)){
                $cassWords = preg_split('/\s+/', trim($street), -1, PREG_SPLIT_NO_EMPTY);
                $postalWords = preg_split('/\s+/', trim($postalStreet), -1, PREG_SPLIT_NO_EMPTY);
                if($postalWords[0] === $cassWords[0] && count($postalWords) >= count($cassWords)){
                    $street = $postalStreet;
                }
            }
            // The unit stays CASS-sourced — CASS is the authority on unit
            // designators ("800" in, "STE 800" out). A postal second line
            // fills in only when CASS found none AND it actually reads as a
            // unit, since for Google it is just as often the street.
            if(empty($secondary) && !empty($postalSecondary) && preg_match('/^(?:' . self::SECONDARY_DESIGNATORS . ')\b|^#/i', trim($postalSecondary))){
                $secondary = $postalSecondary;
            }
            $this->address2 = $this->smartCase($street);
            $this->address1 = !empty($secondary) ? $this->smartCase($secondary) : '';
            /*--
            City is USPS's own mailing city ("BONNER", not the census place
            "Bonner-West Riverside" Google reports) — with two fallbacks to
            Google's locality:

            - CASS's 13-char field cap truncated it ("SNOQUALMIE PS"). A
              legitimate exactly-13-char city falls back harmlessly: Google
              renders it identically. ("QUIL CEDA VLG" is capped AND Google
              mirrors the abbreviation, so that one is unfixable from either
              source.)
            - CASS didn't actually confirm the address (no zipCode in the
              block). Then std.city is only an echo of what the client sent —
              "Paoli" — not a USPS determination, and Google's locality holds
              the real mailing city ("Belleville").
            --*/
            $stdCity = strval($std['city'] ?? '');
            $cassConfirmed = !empty($std['zipCode']);
            if($cassConfirmed && $stdCity !== '' && strlen($stdCity) < 13){
                $this->city = $this->smartCase($stdCity);
            }else{
                $this->city = $this->smartCase($postal['locality'] ?? $stdCity);
            }
            /*--
            A premise-level verdict does not guarantee a complete CASS block.
            When USPS does not recognise the submitted community as a mailing
            city ("Paoli WI", an unincorporated place whose post office is
            Belleville), standardizedAddress comes back partial — city and
            state but no ZIP, or ZIP but no state — while Google's own
            postalAddress in the same response has the full resolved picture.
            Backfill from it. Trusting the partial block wholesale is what
            turned these addresses into constraint-violation 500s: '' in zip5
            hit chk_zip5_format, '' in sub_code hit fk_sub_code.
            --*/
            $this->stateShort = strval($std['state'] ?? '');
            if(empty($this->stateShort)){
                $this->stateShort = strval($postal['administrativeArea'] ?? '');
            }
            // Keep ZIPs as strings and pad — intval() here silently dropped the
            // leading zero on every MA/NH/RI/CT/NJ address (01085 -> 1085).
            $this->zip5 = $this->padZip($std['zipCode'] ?? '', 5);
            if(empty($this->zip5)){
                $this->zip5 = $this->padZip($postalZip5, 5);
                $this->zip4 = $this->padZip($postalZip4, 4);
            }else{
                $this->zip4 = $this->padZip($std['zipCodeExtension'] ?? '', 4);
            }
        }else{
            // Fall back to Google's post-processed postal address
            $lines = $postal['addressLines'] ?? array();
            if(count($lines) > 1){
                $street = $lines[0];
                $secondary = $lines[1];
            }else{
                list($street, $secondary) = $this->splitSecondaryUnit($lines[0] ?? '');
            }
            $this->address2 = $this->smartCase($street);
            $this->address1 = !empty($secondary) ? $this->smartCase($secondary) : '';
            $this->city = $this->smartCase($postal['locality'] ?? '');
            $this->stateShort = strval($postal['administrativeArea'] ?? '');
            $this->zip5 = $this->padZip($postalZip5, 5);
            $this->zip4 = $this->padZip($postalZip4, 4);
        }

        // Derive sub_code and state_long
        if(!empty($this->stateShort) && (empty($this->sub_code) || empty($this->stateLong))){
            $subdivisions = new Subdivisions();
            $sub_code = 'US-' . $this->stateShort;
            if($subdivisions->validate($sub_code, true)){
                $this->sub_code = $subdivisions->sub_code;
                $this->stateLong = $subdivisions->sub_name;
            }
        }

        /*--
        Completeness gate. Everything below feeds columns with hard schema
        constraints — zip5 has a CHECK for five digits, sub_code a foreign key
        into subdivisions — so an address still incomplete after the backfill
        above has to stop here as a 400 the client can act on, not surface as
        the database's own 500 with every valid_state key null.
        --*/
        if(!preg_match('/^[0-9]{5}$/', $this->zip5) || empty($this->sub_code) || empty($this->city) || empty($this->address2)){
            $this->error = true;
            $this->errorMsg = 'We found this location but could not fully standardize its mailing address. If the community is not a USPS mailing city (common for unincorporated places), try the city its post office uses, or provide the ZIP code.';
            $this->responseCode = 400;
            // Blame the field the client can most usefully change.
            $blame = !preg_match('/^[0-9]{5}$/', $this->zip5) ? 'zip5' : (empty($this->sub_code) ? 'sub_code' : (empty($this->city) ? 'city' : 'address2'));
            $this->validState[$blame] = 'invalid';
            $this->validMsg[$blame] = $this->errorMsg;

            // Log Error — badData carries the raw response, same as 267, so
            // these edge-case addresses are diagnosable without a repro.
            $errorLog = new LogError();
            $errorLog->errorNumber = 303;
            $errorLog->errorMsg = 'Address standardization incomplete after postalAddress backfill';
            $errorLog->badData = $requestData . ' // Response: ' . $response;
            $errorLog->filename = 'API / USAddresses.class.php';
            $errorLog->write();
            return;
        }

        // Capture coordinates from the same response (no separate geocode call needed)
        if(isset($geo['latitude']) && isset($geo['longitude'])){
            $this->latitude = $geo['latitude'];
            $this->longitude = $geo['longitude'];
            $this->latLongFound = true;
        }
    }

    // Split a USPS-standardized street line into [street, secondary unit].
    // USPS secondary-unit designators per Publication 28, Appendix C2.
    // The pound sign gets its own alternative: USPS formats it with a trailing
    // space ("# 3"), so a \b after it would never match.
    //
    // Several designators are also real street names — Pier Ave, Key Biscayne
    // Blvd, Stop 30 Rd, Side Rd. Those match immediately after the house
    // number, which is what tells them apart: a genuine secondary unit always
    // leaves a whole street behind it ("3717 Las Vegas Blvd S"), while a street
    // name mistaken for one leaves a bare number ("1"). So refuse the split
    // when that is all that remains. "Pier 39 Ste 200" still splits correctly.
    // USPS secondary-unit designators per Publication 28, Appendix C2. Shared
    // by splitSecondaryUnit() and the postal-line unit guard above so the two
    // can't drift apart.
    private const SECONDARY_DESIGNATORS = 'APT|BSMT|BLDG|DEPT|FL|FRNT|HNGR|KEY|LBBY|LOT|LOWR|OFC|PH|PIER|REAR|RM|SIDE|SLIP|SPC|STOP|STE|TRLR|UNIT|UPPR';

    private function splitSecondaryUnit($line){
        $line = trim($line ?? '');
        if(preg_match('/^(.*?)\s+((?:' . self::SECONDARY_DESIGNATORS . ')\b.*|#\s*\S.*)$/i', $line, $m)){
            if(preg_match('/^\d+[A-Za-z]?$/', trim($m[1]))){
                return array($line, '');
            }
            return array(trim($m[1]), trim($m[2]));
        }
        return array($line, '');
    }

    /*--
    Title-case a CASS/postal address component without mangling the tokens
    USPS writes in caps. A bare ucwords(strtolower()) turned "RD NE" into
    "Rd Ne", "STE 105B" into "Ste 105b" and "BONNER-WEST RIVERSIDE" into
    "Bonner-west Riverside" — every one observed in production, filed as I9.

    Token rules, applied after the base title-case (which also capitalizes
    after hyphens and slashes for hyphenated localities):
    - ordinals stay lowercase:        232ND ST   -> 232nd St
    - short digit+letter units upper: STE 105B   -> Ste 105B
    - directionals upper:             RD NE      -> Rd NE  (single letters
      N/S/E/W already survive the base case unchanged)
    - PO and US upper:                PO BOX 12  -> PO Box 12, US HWY 41
    --*/
    private function smartCase($value){
        $value = ucwords(strtolower(trim($value ?? '')), " -/");
        $tokens = preg_split('/(\s+|-|\/)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach($tokens as &$token){
            if(preg_match('/^\d+(St|Nd|Rd|Th)$/i', $token)){
                $token = strtolower($token);
            }elseif(preg_match('/^\d+[A-Za-z]{1,2}$/', $token)){
                $token = strtoupper($token);
            }elseif(preg_match('/^(Ne|Nw|Se|Sw|Po|Us)$/i', $token)){
                $token = strtoupper($token);
            }
        }
        unset($token);
        return implode('', $tokens);
    }

    // Normalize a ZIP part to a fixed-width digit string.
    //
    // ZIP codes are identifiers, not numbers: 00501-09999 (New England, NJ, PR,
    // VI) carry a significant leading zero, and nothing does arithmetic on one.
    // Anything that round-trips a ZIP through int loses that zero — which is
    // exactly what happened before August 2026, when zip5/zip4 were int columns
    // and this class intval()'d them on every read. Westfield MA 01085 came back
    // as 1085, and re-submitting that 4-digit value then failed validateAddress()
    // with valid_state.zip5 = "invalid".
    //
    // Returns '' for an absent value so the existing !empty() checks still work.
    //
    // Callers pass an already-split part (zip5 OR zip4). Anything longer than
    // $width after stripping punctuation is therefore a combined ZIP+4 that
    // slipped through — "92878-3289" or the unhyphenated "928783289" USPS also
    // emits — so keep the leading $width digits, which is the zip5 in both
    // cases. Truncating here beats writing 9 digits at a char(5) column.
    private function padZip($value, $width){
        $digits = preg_replace('/\D/', '', strval($value ?? ''));
        if($digits === '' || intval($digits) === 0){return '';}
        if(strlen($digits) > $width){$digits = substr($digits, 0, $width);}
        return str_pad($digits, $width, '0', STR_PAD_LEFT);
    }

    // Validate Telephone
    private function validateTelephone(){
        // Must set telephone

        // Trim
        $this->telephone = trim($this->telephone ?? '');

        if(!empty($this->telephone)){
            // Eliminate every char except 0-9
            $this->telephone = preg_replace("/[^0-9]/", '', $this->telephone ?? '');

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

    // Validate
    public function validate($locationID, $saveToClass){
        $valid = false;

        if(!empty($locationID)){
            // Prep for Database
            $db = new Database();
            $result = $db->query("SELECT a.address1, a.address2, a.city, a.sub_code, a.zip5, a.zip4, a.telephone, s.sub_name FROM US_addresses a LEFT JOIN subdivisions s ON a.sub_code = s.sub_code WHERE a.locationID=?", [$locationID]);
            if(!$db->error){
                if($result->num_rows == 1){
                    // Valid
                    $valid = true;

                    // Save to Class?
                    if($saveToClass){
                        $array = $result->fetch_assoc();
                        $this->locationID = $locationID;
                        $this->address1 = $array['address1'];
                        $this->address2 = $array['address2'];
                        $this->city = $array['city'];
                        $this->sub_code = $array['sub_code'];
                        // Strings, not intval() — see the $zip5 property comment
                        $this->zip5 = $this->padZip($array['zip5'] ?? '', 5);
                        $this->zip4 = $this->padZip($array['zip4'] ?? '', 4);
                        $this->telephone = intval($array['telephone']);

                        if(!empty($array['sub_code'])){
                            $this->stateShort = substr($array['sub_code'] ?? '', 3, 2);
                            $this->stateLong = $array['sub_name'] ?? '';
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

        /*--
        property_exists(), not isset(): isset() is false for an explicit null,
        so "clear this field" arrived identical to "leave this field alone" —
        the same defect fixed on Brewer/Beer/Location/Users. It only becomes
        safe here now that PATCH merges with the stored address: telephone
        clears to NULL, address1 and zip4 re-derive from the validated address,
        and a null on a required component simply fails validation with a 400.
        --*/
        if(property_exists($data, 'address1')){$patchFields[] = 'address1';}
        else{$data->address1 = '';}

        if(property_exists($data, 'address2')){$patchFields[] = 'address2';}
        else{$data->address2 = '';}

        if(property_exists($data, 'city')){$patchFields[] = 'city';}
        else{$data->city = '';}

        if(property_exists($data, 'sub_code')){$patchFields[] = 'sub_code';}
        else{$data->sub_code = '';}

        if(property_exists($data, 'zip5')){$patchFields[] = 'zip5';}
        else{$data->zip5 = '';}

        if(property_exists($data, 'zip4')){$patchFields[] = 'zip4';}
        else{$data->zip4 = '';}

        if(property_exists($data, 'telephone')){$patchFields[] = 'telephone';}
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
