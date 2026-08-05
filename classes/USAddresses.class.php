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
                            // Store Latitude and Longitude (captured during
                            // validation — Address Validation geocodes every
                            // accepted address, so no fallback lookup)
                            if($this->latLongFound){
                                $location->saveCoordinates($this->locationID, $this->latitude, $this->longitude);
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
                            // saveCoordinates(), so the copy
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
                                // Store Latitude and Longitude (captured during
                                // validation — Address Validation geocodes every
                                // accepted address, so no fallback lookup)
                                if($this->latLongFound){
                                    $location->saveCoordinates($this->locationID, $this->latitude, $this->longitude);
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
                            // saveCoordinates(), so the copy
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

        // Street + unit come from the typed addressComponents, not from
        // re-parsing rendered lines — see parseValidatedAddress() for the
        // full division of labour (decided 2026-08-05, audit of all 587
        // stored addresses in scratch/address-audit, replayed by
        // tests/address-parse.php).
        $parsed = self::parseValidatedAddress($result);
        $this->address2 = $parsed['address2'];
        $this->address1 = $parsed['address1'];

        if(!empty($std)){
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
            // No CASS block: city/state/ZIP fall back to Google's
            // post-processed postal address (street and unit already came
            // from the components above).
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

    // USPS secondary-unit designators per Publication 28, Appendix C2. Used
    // to cut CASS's unit rendering out of its own folded line — and ONLY
    // there, anchored to an identifier Google already confirmed. This
    // dictionary never decides whether a unit exists; Google's subpremise
    // component does. (Its predecessor, splitSecondaryUnit(), guessed
    // structure from rendered text and produced the I10 bug family.)
    private const SECONDARY_DESIGNATORS = 'APT|BSMT|BLDG|DEPT|FL|FRNT|HNGR|KEY|LBBY|LOT|LOWR|OFC|PH|PIER|REAR|RM|SIDE|SLIP|SPC|STOP|STE|TRLR|UNIT|UPPR';

    /*--
    Parse street (address2) and secondary unit (address1) from a Google
    Address Validation result. Pure — no DB, no logging — so
    tests/address-parse.php can replay it against captured fixtures.

    Division of labour (decided 2026-08-05; every rule below exists because a
    real stored address demanded it — see the fixture set):

      Google addressComponents    structure AND street text: street_number +
                                  route, or post_box. The components type the
                                  address correctly no matter how the client
                                  wrote it ("Suite 12" inline, bare "B1",
                                  "# 1", unit-before-street).
      apAbbreviate()              display form: AP-style abbreviations over
                                  Google's spelled-out route.
      CASS standardizedAddress    unit rendering ("Suite 12" in, "BLDG 12"
                                  out), and street rendering for road
                                  DESIGNATIONS — a bare number token in the
                                  route ("West Arizona 92", "Farm to Market
                                  Road 423") means Google is reporting its
                                  internal Maps road name, and CASS holds the
                                  USPS display form ("W HIGHWAY 92", "FM 423").
      Google subpremise           whether a unit exists + its identifier.
                                  CASS's second line is still trusted when
                                  Google saw no unit — unless it carries the
                                  street number, in which case it IS the
                                  street (business-name-on-line-1 shape).

    An unconfirmed subpremise on an unconfirmed route is a parse artifact,
    not a unit ("State Route 5 And 20" -> subpremise "And 20"); one on a
    CONFIRMED route stays ("Building B", "Taproom").
    --*/
    public static function parseValidatedAddress($result){
        $std = $result['uspsData']['standardizedAddress'] ?? array();

        $comp = array();
        $conf = array();
        foreach(($result['address']['addressComponents'] ?? array()) as $c){
            $type = $c['componentType'] ?? '';
            $text = trim($c['componentName']['text'] ?? '');
            if($type !== '' && $text !== '' && !isset($comp[$type])){
                $comp[$type] = $text;
                $conf[$type] = $c['confirmationLevel'] ?? '';
            }
        }
        $number  = $comp['street_number'] ?? '';
        $route   = $comp['route'] ?? '';
        $unitG   = $comp['subpremise'] ?? '';
        $postBox = $comp['post_box'] ?? '';

        // Unconfirmed unit on an unconfirmed street: parse artifact.
        if($unitG !== '' && ($conf['subpremise'] ?? '') === 'UNCONFIRMED_BUT_PLAUSIBLE'
            && ($conf['route'] ?? '') !== 'CONFIRMED'){
            $unitG = '';
        }

        // Grid street numbers (Wisconsin's "N71W13040") reach Google in the
        // client's casing; CASS canonicalizes them.
        if($number !== '' && preg_match('/[A-Za-z]/', $number) && !empty($std['firstAddressLine'])
            && stripos($std['firstAddressLine'], strtoupper($number)) === 0){
            $number = strtoupper($number);
        }

        // The unit identifier survives every rendering: "Suite 12", "BLDG 12"
        // and "#12" all end in "12". It anchors the cuts below.
        $unitId = '';
        if($unitG !== '' && preg_match('/([A-Za-z0-9][A-Za-z0-9\-\/]*)\s*$/', $unitG, $m)){ $unitId = $m[1]; }

        // ----- address1 (resolved first: the street cut needs it) -----
        // $cassStreetRemainder is what's left of CASS's first line once its
        // folded unit is removed — the designation street path reuses it.
        $unit = '';
        $cassStreetRemainder = '';
        if(!empty($std['secondAddressLine'])){
            $cassSecond = trim($std['secondAddressLine']);
            if($number === '' || !preg_match('/(?:^|\s)' . preg_quote($number, '/') . '(?:\s|$)/i', $cassSecond)){
                $unit = $cassSecond;
            }
        }
        if($unit === '' && !empty($std['firstAddressLine'])){
            /*--
            CASS folded the unit into line one. Two ways to cut it, both
            applied ONLY to CASS output — a closed vocabulary (Pub 28 C2)
            that USPS itself generated. The same dictionary against client
            free text is what produced the I10 bug family, which is why it
            never runs there.

            1. Anchored at Google's identifier, when Google saw the unit.
               Allows one designator abbreviation and/or # ahead of it
               ("BLDG 12", "# 12", "STE 800"). The word must come from the
               USPS list: a bare [A-Za-z]+ here ate "RD B1" whole.
            2. Dictionary split, when Google reported NO subpremise at all.
               Google drops units it cannot confirm — "#406" at a large
               complex comes back with no subpremise component while CASS
               still standardizes it to "STE 406" (caught by A-42). Trusting
               CASS here is the deliberate call: it is the mail authority,
               and dropping the unit silently loses real data.

            Both refuse a split that would leave only a house number, which
            is what tells a genuine unit from a street named for a
            designator (Pier Ave, Key Biscayne Blvd).
            --*/
            $cassFirst = $std['firstAddressLine'];
            $cut = null;
            if($unitId !== ''){
                $re = '/\s+(?:(?:' . self::SECONDARY_DESIGNATORS . ')\s+)?#?\s*' . preg_quote($unitId, '/') . '$/i';
                if(preg_match($re, $cassFirst, $m2, PREG_OFFSET_CAPTURE)){ $cut = $m2[0][1]; }
            }elseif($unitG === ''){
                $re = '/\s+(?:(?:' . self::SECONDARY_DESIGNATORS . ')\s+\S|#\s*\S)/i';
                if(preg_match($re, $cassFirst, $m2, PREG_OFFSET_CAPTURE)){ $cut = $m2[0][1]; }
            }
            if($cut !== null){
                $rest = trim(substr($cassFirst, 0, $cut));
                if($rest !== '' && !preg_match('/^\d+(?:\s+\d+\/\d+)?$/', $rest)){
                    $unit = trim(substr($cassFirst, $cut));
                    $cassStreetRemainder = $rest;
                }
            }
        }
        if($unit === '' && $unitG !== ''){ $unit = $unitG; }   // Google-only unit

        // ----- address2 -----
        if($postBox !== '' && $number === '' && $route === ''){
            $street = $postBox;
        }else{
            $googleStreet = trim($number . ' ' . $route);

            // CASS's line must actually BE the street: when a business name
            // occupies it, it carries no street number — then it is not a
            // candidate at all, and Google's route stands.
            $cassStreet = '';
            if(!empty($std['firstAddressLine']) && ($number === ''
                || preg_match('/(?:^|\s)' . preg_quote(strtoupper($number), '/') . '(?:\s|$)/i', $std['firstAddressLine']))){
                $cassStreet = $cassStreetRemainder !== '' ? $cassStreetRemainder : trim($std['firstAddressLine']);
                if($cassStreetRemainder === '' && $unit !== ''){
                    // The unit came from CASS's second line, but CASS often
                    // ALSO folds it into the first ("2200 S I-35 FRONTAGE RD
                    // B1" / second "B1"). Strip the duplicate, or address2
                    // ships with the unit still attached — the I10 bug.
                    $stripped = trim(preg_replace('/\s+' . preg_quote($unit, '/') . '$/i', '', $cassStreet, 1));
                    if($stripped !== '' && !preg_match('/^\d+(?:\s+\d+\/\d+)?$/', $stripped)){
                        $cassStreet = $stripped;
                    }
                }
            }

            /*--
            Three cases hand the street back to CASS:

            1. Road DESIGNATIONS — a bare number token in the route means
               Google is reporting internal Maps naming ("West Arizona 92",
               "Farm to Market Road 423", "State Road 656" for a road locals
               call Pine View Rd). CASS holds the USPS display form.
            2. TRUNCATED routes. Google will echo an incomplete street
               straight back as the route — "India" for India St — and mark
               it CONFIRMED, with nothing in the response saying a suffix is
               missing (caught by A-80, where the request carried a state and
               ZIP but no city). The tell is that Google's street is a strict
               token-prefix of CASS's: same tokens, then CASS adds more.
            3. A different street TYPE. Google's road data and USPS's can
               genuinely disagree about whether an address sits on a Street
               or an Avenue, a Boulevard or a Way — and where they do, USPS
               is the one that routes the mail (608 Topeka St is dpv=Y while
               Google calls it Topeka Avenue). Google's answer can also be
               unstable there: 10520 Quil Ceda comes back "Way" when asked
               about "Blvd" and "Boulevard" when asked about "Way", so
               taking Google's would rewrite the row on every future save.

            Everything else is Google being MORE complete, and it keeps the
            street: a spelled-out name ("COMRCL CTR BLVD" -> "Commercial
            Center Boulevard"), a restored hyphen ("SUB ZERO" -> "Sub-Zero"),
            an added directional ("APPLETON AVE" -> "West Appleton Avenue"),
            or an expanded suffix ("ALY" -> "Alley"), which is the SAME type
            spelled differently and so not a conflict.
            --*/
            $isDesignation = $route !== '' && preg_match('/(?:^|\s)\d+(?:\s|$)/', $route);
            if($cassStreet !== '' && ($isDesignation || $googleStreet === ''
                || self::isTokenPrefix($googleStreet, $cassStreet)
                || self::streetTypeConflict($googleStreet, $cassStreet))){
                $street = self::apAbbreviate(self::smartCase($cassStreet));
            }else{
                $street = self::apAbbreviate($googleStreet);
            }
        }

        // Street is NOT smartCased on the components path: Google's text is
        // already properly cased ("La Orilla", "McKinley") and re-casing
        // would mangle it. Units go through casing because CASS renders them
        // ALL CAPS, and Google echoes client lowercase ("b1").
        return array(
            'address2' => $street,
            'address1' => $unit !== '' ? self::unitCase($unit) : '',
        );
    }

    /*--
    AP-style display abbreviation (decided 2026-08-05). Google's route is
    fully spelled out ("4th Street Northwest"); the catalog's display
    convention is the traditional abbreviated form ("4th St NW").
    Abbreviating is the SAFE direction — a finite lookup at fixed positions —
    unlike expanding ("St" -> Saint or Street?), which is why rendering
    starts from Google's verbose text rather than CASS's over-abbreviated
    one ("COMRCL CTR BLVD").

    Position rules (interior name words are never touched):
    - "Interstate N" -> "I-N" anywhere (AP second-reference style)
    - directional abbreviated right after the house number or at line end
      — EXCEPT directional + type + at most a trailing directional, where
      the directional IS the name ("680 North Avenue NE" is Atlanta's North
      Avenue; but "East Court Street" and "West Avenue O" still abbreviate)
    - street type abbreviated only in final position (or just before a
      trailing directional) — "US Highway 281" and "Route 59" keep their
      spelled forms because the number, not the type, ends the name
    Deliberately NOT abbreviated: Way, Loop, Route, Center, Plaza, Park —
    AP spells them, famous addresses spell them, and several end real names.
    --*/
    private static function apAbbreviate($street){
        $DIR = array('North'=>'N', 'South'=>'S', 'East'=>'E', 'West'=>'W',
            'Northeast'=>'NE', 'Northwest'=>'NW', 'Southeast'=>'SE', 'Southwest'=>'SW');
        $TYPE = array('Street'=>'St', 'Avenue'=>'Ave', 'Boulevard'=>'Blvd', 'Road'=>'Rd',
            'Drive'=>'Dr', 'Lane'=>'Ln', 'Court'=>'Ct', 'Circle'=>'Cir', 'Place'=>'Pl',
            'Square'=>'Sq', 'Terrace'=>'Ter', 'Trail'=>'Trl', 'Parkway'=>'Pkwy',
            'Highway'=>'Hwy', 'Freeway'=>'Fwy', 'Expressway'=>'Expy', 'Turnpike'=>'Tpke');

        $street = preg_replace('/\bInterstate\s+(\d+)\b/i', 'I-$1', trim($street ?? ''));
        $tokens = preg_split('/\s+/', $street, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($tokens);
        if($n < 3){ return implode(' ', $tokens); }   // too short to hold suffix + name

        $directionalIsName = isset($TYPE[$tokens[2] ?? '']) && ($n === 3 || ($n === 4 && isset($DIR[$tokens[3]])));
        if($n >= 4 && isset($DIR[$tokens[1]]) && !$directionalIsName){ $tokens[1] = $DIR[$tokens[1]]; }

        $last = $n - 1;
        if(isset($DIR[$tokens[$last]])){
            $tokens[$last] = $DIR[$tokens[$last]];
            $last--;    // street type may sit just before it
        }
        if($last >= 2 && isset($TYPE[$tokens[$last]])){ $tokens[$last] = $TYPE[$tokens[$last]]; }

        return implode(' ', $tokens);
    }

    /*--
    Street-type vocabulary for CONFLICT DETECTION — every spelling mapped to
    one canonical form, so "Alley"/"ALY" and "Center"/"CTR" read as the same
    type while "Avenue" and "ST" read as different ones.

    Wider than apAbbreviate()'s table on purpose: that one only lists types we
    ABBREVIATE for display, while this one has to recognise types we
    deliberately leave spelled out (Way, Loop, Route, Plaza, Green) — Quil
    Ceda's Boulevard/Way disagreement is invisible without them.
    --*/
    private const STREET_TYPE_CANON = array(
        'STREET'=>'ST', 'ST'=>'ST', 'AVENUE'=>'AVE', 'AVE'=>'AVE', 'AV'=>'AVE',
        'BOULEVARD'=>'BLVD', 'BLVD'=>'BLVD', 'ROAD'=>'RD', 'RD'=>'RD',
        'DRIVE'=>'DR', 'DR'=>'DR', 'LANE'=>'LN', 'LN'=>'LN', 'COURT'=>'CT', 'CT'=>'CT',
        'CIRCLE'=>'CIR', 'CIR'=>'CIR', 'PLACE'=>'PL', 'PL'=>'PL', 'SQUARE'=>'SQ', 'SQ'=>'SQ',
        'TERRACE'=>'TER', 'TER'=>'TER', 'TRAIL'=>'TRL', 'TRL'=>'TRL',
        'PARKWAY'=>'PKWY', 'PKWY'=>'PKWY', 'HIGHWAY'=>'HWY', 'HWY'=>'HWY',
        'FREEWAY'=>'FWY', 'FWY'=>'FWY', 'EXPRESSWAY'=>'EXPY', 'EXPY'=>'EXPY',
        'TURNPIKE'=>'TPKE', 'TPKE'=>'TPKE', 'WAY'=>'WAY', 'WY'=>'WAY',
        'LOOP'=>'LOOP', 'ALLEY'=>'ALY', 'ALY'=>'ALY', 'PLAZA'=>'PLZ', 'PLZ'=>'PLZ',
        'ROUTE'=>'RTE', 'RTE'=>'RTE', 'EXTENSION'=>'EXT', 'EXT'=>'EXT',
        'GREEN'=>'GRN', 'GRN'=>'GRN', 'POINT'=>'PT', 'PT'=>'PT',
        'CROSSING'=>'XING', 'XING'=>'XING', 'CENTER'=>'CTR', 'CTR'=>'CTR',
        'RUN'=>'RUN', 'PASS'=>'PASS', 'PIKE'=>'PIKE', 'BEND'=>'BEND',
    );

    // The street's own type: the LAST recognised type token, so "Commercial
    // Center Boulevard" reads as BLVD rather than CTR. Returns '' when the
    // name carries no type at all ("South Broadway", "1000 N Broadway").
    private static function streetType($street){
        $tokens = preg_split('/\s+/', strtoupper(trim($street ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        for($i = count($tokens) - 1; $i >= 1; $i--){
            if(isset(self::STREET_TYPE_CANON[$tokens[$i]])){ return self::STREET_TYPE_CANON[$tokens[$i]]; }
        }
        return '';
    }

    // Do the two renderings disagree about the street TYPE? Only when both
    // name one: a type present on one side and absent on the other is a
    // completeness question, which the token-prefix rule already answers.
    private static function streetTypeConflict($googleStreet, $cassStreet){
        $g = self::streetType($googleStreet);
        $c = self::streetType($cassStreet);
        return $g !== '' && $c !== '' && $g !== $c;
    }

    // Is $a's token sequence a strict prefix of $b's? Case-insensitive, so
    // it answers "same street, but $b carries more of it" — not "$b is
    // spelled differently", which is the common and desirable case.
    private static function isTokenPrefix($a, $b){
        $at = preg_split('/\s+/', strtoupper(trim($a)), -1, PREG_SPLIT_NO_EMPTY);
        $bt = preg_split('/\s+/', strtoupper(trim($b)), -1, PREG_SPLIT_NO_EMPTY);
        if(empty($at) || count($at) >= count($bt)){ return false; }
        foreach($at as $i => $token){
            if($token !== $bt[$i]){ return false; }
        }
        return true;
    }

    // smartCase for units, plus: unit identifiers like b1 / k100 / 12a
    // uppercase (Google echoes the client's lowercase; CASS never would).
    private static function unitCase($value){
        $value = self::smartCase($value);
        $tokens = preg_split('/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach($tokens as &$token){
            if(preg_match('/^[A-Za-z]\d+[A-Za-z]?$/', $token) || preg_match('/^#[A-Za-z0-9]+$/', $token)){
                $token = strtoupper($token);
            }
        }
        unset($token);
        return implode('', $tokens);
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
    private static function smartCase($value){
        $value = ucwords(strtolower(trim($value ?? '')), " -/");
        $tokens = preg_split('/(\s+|-|\/)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach($tokens as &$token){
            if(preg_match('/^\d+(St|Nd|Rd|Th)$/i', $token)){
                $token = strtolower($token);
            }elseif(preg_match('/^\d+[A-Za-z]{1,2}$/', $token)){
                $token = strtoupper($token);
            }elseif(preg_match('/^(Ne|Nw|Se|Sw|Po|Us|Fm)$/i', $token)){
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
