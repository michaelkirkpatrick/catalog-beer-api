<?php

class Location {

    // Properties
    public $locationID = '';
    public $brewerID = '';
    public $name = '';
    public $url = '';           // Optional
    public $countryCode = '';
    public $latitude = 0;       // Optional
    public $longitude = 0;      // Optional
    public $cbVerified = false;
    public $brewerVerified = false;
    public $lastModified = 0;

    // Cached objects to avoid redundant queries
    private $brewerObj = null;

    // The address, as loaded by validate() (an Address holding the row's
    // address columns) or set by add(); null when the location has none.
    public $address = null;
    private $addressRow = null;

    // Total location count, cached by validateCursorCount() for nextCursor()
    private $totalCount = 0;

    // Error Handling
    public $error = false;
    public $errorMsg = null;
    // The nested `address` state mirrors the request's nested object.
    const ADDRESS_STATE = array('line1'=>null, 'line2'=>null, 'locality'=>null, 'dependent_locality'=>null, 'sub_code'=>null, 'postal_code'=>null, 'telephone'=>null);
    public $validState = array('brewer_id'=>null, 'name'=>null, 'url'=>null, 'country_code'=>null, 'address'=>self::ADDRESS_STATE);
    public $validMsg = array('brewer_id'=>null, 'name'=>null, 'url'=>null, 'country_code'=>null, 'address'=>self::ADDRESS_STATE);

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    // Add Functions
    /*--
    Create, replace or patch a location, address included.

    $address is the nested `address` object reduced by api(): null when the
    request did not carry one, false for an explicit null, or the seven
    request keys ('' when absent). $addressPatchFields names the keys the
    request actually sent inside it (PATCH only). The address is required:
    POST and PUT must carry it, and PATCH may change it but not remove it.
    It validates BEFORE the row is written, so a refused address writes
    nothing -- a location is never created or replaced without one.
    --*/
    public function add($brewerID, $name, $url, $countryCode, $userID, $method, $locationID, $patchFields, $address = null, $addressPatchFields = array()){

        // Required Classes
        $brewer = new Brewer();
        $db = new Database();
        $privileges = new Privileges();
        $users = new Users();
        $uuid = new uuid();

        // ----- locationID -----
        $newLocation = false;
        switch($method){
            case 'POST':
                // Generate a new location_id
                $newLocation = true;
                $this->locationID = $uuid->generate('location');
                if($uuid->error){
                    // UUID Generation Error
                    $this->error = true;
                    $this->errorMsg = $uuid->errorMsg;
                    $this->responseCode = $uuid->responseCode;
                }
                break;
            case 'PUT':
                if($this->validate($locationID, true)){
                    // Valid Location - Update Existing Entry
                    $this->locationID = $locationID;
                    // Save original values for permissions check
                    $originalLocationBrewerID = $this->brewerID;
                    $originalCBV = $this->cbVerified;
                    $originalBV = $this->brewerVerified;
                    $originalCountry = $this->countryCode;
                }else{
                    // Location doesn't exist, they'd like to add it
                    // Reset Errors from $this->validate()
                    $this->error = false;
                    $this->errorMsg = null;
                    $this->responseCode = 200;

                    // Validate UUID
                    if($uuid->validate($locationID)){
                        // Save submitted UUID as locationID
                        $newLocation = true;
                        $this->locationID = $locationID;
                    }else{
                        // Invalid UUID Submission
                        $this->error = true;
                        $this->errorMsg = $uuid->errorMsg;
                        $this->responseCode = $uuid->responseCode;
                    }
                }
                break;
            case 'PATCH':
                if($this->validate($locationID, true)){
                    // Valid Location - Update Existing Entry (Reference #1)
                    $this->locationID = $locationID;
                    // Save original values for permissions check
                    $originalLocationBrewerID = $this->brewerID;
                    $originalCBV = $this->cbVerified;
                    $originalBV = $this->brewerVerified;
                    $originalCountry = $this->countryCode;
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
                $errorLog->errorNumber = 184;
                $errorLog->errorMsg = 'Invalid Method';
                $errorLog->badData = $method;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
        }

        // ----- Validate brewerID -----

        if($brewer->validate($brewerID, true)){
            // Valid BrewerID
            $this->brewerID = $brewerID;
            $this->validState['brewer_id'] = 'valid';
            $this->brewerObj = $brewer;

            /*--
            A closed brewery has no open venues. The map and /location/nearby
            read the location table directly, so a location on a closed
            brewer is a wrong pin for everyone; the review loop deletes a
            closed brewery's locations on a human decision, and this is what
            keeps a stale page from putting one back. Gated on arrival only --
            a new location, or an existing one being moved onto the closed
            brewer -- so the rows already held can still be edited while the
            delete question is open.
            --*/
            $arriving = $newLocation || (isset($originalLocationBrewerID) && $originalLocationBrewerID != $brewerID);
            if($brewer->status === 'closed' && $arriving){
                $this->error = true;
                $this->validState['brewer_id'] = 'invalid';
                $this->validMsg['brewer_id'] = 'This brewery is closed, so it has no open locations to add. If it has reopened, update the brewer\'s status first.';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 336;
                $errorLog->errorMsg = 'Location refused: brewer is closed';
                $errorLog->badData = "Brewer: $brewerID / Method: $method";
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }

            // Which brewer is this location currently associated with?
            if(($method == 'PUT' || $method == 'PATCH') && isset($originalLocationBrewerID)){
                // Use saved brewerID from validate()
                $permissionsBrewerID = $originalLocationBrewerID;
            }else{
                // New location or POST, use the submitted brewerID
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
                    if(!$newLocation){
                        // Attempting to PUT or PATCH existing Location
                        /*--
                        cb_verified is an indicator, not a gate: any account may
                        edit a reviewed record, and the badge recompute below
                        clears cb_verified on any non-admin write, so the badge
                        always describes content an admin has seen. Only
                        brewer_verified still restricts editing, to the brewery's
                        own staff and admins.
                        --*/
                        $brewerVerified = $originalBV;

                        if($brewerVerified){
                            if($userEmailDomain == $brewer->domainName || in_array($permissionsBrewerID, $userBrewerPrivileges)){
                                // Allow PUT/PATCH. User is brewery staff.
                            }else{
                                if(!$users->admin){
                                    // Deny
                                    $this->error = true;
                                    $this->errorMsg = 'Sorry, because this location is brewer_verified, we limit editing capabilities to brewery staff. If you would like to see an update made to this location, please [contact us](https://catalog.beer/contact)';
                                    $this->responseCode = 403;

                                    // Log Error
                                    $errorLog = new LogError();
                                    $errorLog->errorNumber = 187;
                                    $errorLog->errorMsg = 'Forbidden: General User, PUT/PATCH, /location, brewer_verified';
                                    $errorLog->badData = "User: $userID / Location: $this->locationID";
                                    $errorLog->filename = 'API / Location.class.php';
                                    $errorLog->write();
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
                // Validate Name
                $this->name = $name;
                $this->validateName();

                // Validate URL
                $this->url = $brewer->validateURL($url, 'url', 'location');
                if(!$brewer->error){
                    // Valid URL
                    if(!empty($this->url)){
                        $this->validState['url'] = 'valid';
                    }
                }else{
                    // Invalid URL
                    $this->error = true;
                    $this->validState['url'] = $brewer->validState['url'];
                    $this->validMsg['url'] = $brewer->validMsg['url'];
                    $this->responseCode = $brewer->responseCode;
                }

                // Validate Country Code
                $this->countryCode = $countryCode;
                $this->validateCC();

                // Address: required, and validated against the (new) country
                // before anything is written.
                $addressResult = null;
                if(!$this->error){
                    $addressResult = $this->resolveAddress($method, $newLocation, $address, $addressPatchFields, $originalCountry ?? null);
                }

                if(!$this->error){
                    // Last Modified
                    $this->lastModified = time();

                    // SQL Query
                    if($newLocation){
                        // Add Location (POST/PUT)
                        // createdAt is written here only — the PUT and PATCH
                        // update paths below never touch it
                        $columns = ['id', 'brewerID', 'name', 'countryCode', 'cbVerified', 'brewerVerified', 'createdAt', 'lastModified'];
                        $params = [$this->locationID, $this->brewerID, $this->name, $this->countryCode, $dbCBV, $dbBV, $this->lastModified, $this->lastModified];
                        if(!empty($this->url)){
                            $columns[] = 'url';
                            $params[] = $this->url;
                        }
                        if($addressResult['action'] === 'set'){
                            foreach(Address::COLUMN_NAMES as $i => $col){
                                $columns[] = $col;
                                $params[] = $addressResult['columns'][$i];
                            }
                            if($addressResult['coords'] !== null){
                                $columns[] = 'latitude';   $params[] = $addressResult['coords'][0];
                                $columns[] = 'longitude';  $params[] = $addressResult['coords'][1];
                            }
                        }
                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $sql = "INSERT INTO location (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                    }else{
                        // Update Location (PUT)
                        // PUT is a full replacement — omitted fields are cleared
                        $setClauses = ['brewerID=?', 'name=?', 'countryCode=?', 'cbVerified=?', 'brewerVerified=?', 'lastModified=?'];
                        $params = [$this->brewerID, $this->name, $this->countryCode, $dbCBV, $dbBV, $this->lastModified];
                        if(!empty($this->url)){
                            $setClauses[] = 'url=?';
                            $params[] = $this->url;
                        }else{
                            $setClauses[] = 'url=NULL';
                        }
                        $this->addressClauses($addressResult, $setClauses, $params);
                        $sql = "UPDATE location SET " . implode(', ', $setClauses) . " WHERE id=?";
                        $params[] = $this->locationID;
                    }
                }
            }
            elseif($method == 'PATCH'){
                /*--
                Validate the field if it's different than what is currently stored.
                Check against the $this->{var} which we have from performing a $this->validate($locationID, true) in the locationID flow above for PATCH (Reference #1).
                --*/

                // SQL Update
                $setClauses = array();
                $setParams = array();

                // brewerID
                if(in_array('brewerID', $patchFields)){
                    // Validated brewerID above, and checked to ensure new, no need to re-validate
                    $setClauses[] = 'brewerID=?';
                    $setParams[] = $this->brewerID;
                }

                // Name
                if(in_array('name', $patchFields)){
                    if($this->name != $name){
                        // Validate Name
                        $this->name = $name;
                        $this->validateName();
                        if(!$this->error){
                            $this->validState['name'] = 'valid';
                            $setClauses[] = 'name=?';
                            $setParams[] = $this->name;
                        }
                    }
                }

                // URL
                if(in_array('url', $patchFields)){
                    if($this->url != $url){
                        // Validate URL
                        $this->url = $brewer->validateURL($url, 'url', 'location');
                        if(!$brewer->error){
                            // Valid URL
                            if(!empty($this->url)){
                                $this->validState['url'] = 'valid';
                                $setClauses[] = 'url=?';
                                $setParams[] = $this->url;
                            }else{
                                /*--
                                Cleared. Without this branch the clause was
                                simply never added, so the UPDATE ran without
                                the url column and the request returned 200
                                having changed nothing but lastModified.

                                NULL, not '': the column is nullable and PUT
                                clears it to NULL, so both paths have to agree
                                on what "no website" looks like.
                                --*/
                                $this->url = null;
                                $this->validState['url'] = 'valid';
                                $setClauses[] = 'url=NULL';
                            }
                        }else{
                            // Invalid URL
                            $this->error = true;
                            $this->validState['url'] = $brewer->validState['url'];
                            $this->validMsg['url'] = $brewer->validMsg['url'];
                            $this->responseCode = $brewer->responseCode;
                        }
                    }
                }

                // Country Code
                if(in_array('countryCode', $patchFields)){
                    if($this->countryCode != $countryCode){
                        // Validate Country Code
                        $this->countryCode = $countryCode;
                        $this->validateCC();
                        if(!$this->error && $this->countryCode !== $originalCountry){
                            $setClauses[] = 'countryCode=?';
                            $setParams[] = $this->countryCode;
                        }
                    }
                }

                // Address: merged with the stored one and re-validated;
                // untouched when not sent. An explicit null is refused.
                if(!$this->error){
                    $addressResult = $this->resolveAddress('PATCH', false, $address, $addressPatchFields, $originalCountry ?? null);
                    if(!$this->error){
                        $this->addressClauses($addressResult, $setClauses, $setParams);
                    }
                }

                if(!$this->error && !empty($setClauses)){
                    // Last Modified
                    $this->lastModified = time();

                    // Construct SQL Statement
                    $sql = "UPDATE location SET lastModified=?, cbVerified=?, brewerVerified=?";
                    $params = [$this->lastModified, $dbCBV, $dbBV];
                    if(!empty($setClauses)){
                        $sql .= ", " . implode(", ", $setClauses);
                        $params = array_merge($params, $setParams);
                    }
                    $sql .= " WHERE id=?";
                    $params[] = $this->locationID;
                }
            }

            if(!$this->error && !empty($sql)){
                // Update Database
                $db->query($sql, $params);
                if(!$db->error){
                    /*--
                    Answer with the stored row, not the in-memory one: the
                    coordinate columns are float(9,7)/float(10,7), so a value
                    read back differs from Google's in the eighth decimal, and
                    the validator does not carry a subdivision's name the way
                    the JOIN does. One SELECT keeps every response to this
                    location identical, written or read.
                    --*/
                    $this->validate($this->locationID, true);
                    if($newLocation){
                        // Successfully Added
                        $this->responseCode = 201;

                        // Response Header
                        $responseHeaderString = 'Location: https://';
                        if(ENVIRONMENT == 'staging'){
                            $responseHeaderString .= 'staging.';
                        }
                        $this->responseHeader = $responseHeaderString . 'catalog.beer/location/' . $this->locationID;

                        // Create Algolia ID and sync to Algolia
                        $algolia = new Algolia();
                        $algolia->add('location', $this->locationID);
                        $algolia->saveObject('catalog', $this->generateLocationSearchObject($this->brewerObj));

                        // Refresh the parent — brewer records denormalize their
                        // locations' geography, so a new location changes them.
                        Brewer::refreshSearchObject($this->brewerID, true);
                    }else{
                        // Success
                        $this->responseCode = 200;

                        // Sync updated location to Algolia
                        $algolia = new Algolia();
                        $algolia->saveObject('catalog', $this->generateLocationSearchObject($this->brewerObj));

                        // Refresh the parent — coordinates or country may have moved
                        Brewer::refreshSearchObject($this->brewerID, true);
                    }
                }else{
                    // Query Error
                    $this->error = true;
                    $this->errorMsg = $db->errorMsg;
                    $this->responseCode = $db->responseCode;
                }
                $db->close();
            }
        }
    }

    // Validation Functions
    private function validateName(){
        // Must set $this->name
        // Name is optional. Most breweries don't name a taproom separately from
        // the community it sits in, and while this was required the field just
        // collected the city over and over. An absent name is stored as NULL;
        // consumers compose a display name from the brewer and the address.
        $this->name = TextInput::trim($this->name);

        /*--
        Shape before length: a control character is a hard reject at any length,
        and naming the offending character is far more use to the submitter than
        "too long" would be. TextInput::check() returns '' for an empty value, so
        the optional-name handling below is unaffected.
        --*/
        $problem = TextInput::check($this->name);
        if($problem !== ''){
            $this->error = true;
            $this->validState['name'] = 'invalid';
            $this->validMsg['name'] = $problem;
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 305;
            $errorLog->errorMsg = 'Forbidden characters in location name';
            $errorLog->badData = $this->name;
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
            return;
        }

        if(!empty($this->name)){
            if(strlen($this->name) <= 255){
                // Valid
                $this->validState['name'] = 'valid';
            }else{
                // Name Too Long
                $this->error = true;
                $this->validState['name'] = 'invalid';
                $this->validMsg['name'] = 'We hate to say it but your location name is too long for our database. Location names are limited to 255 bytes. Any chance you can shorten it?';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 49;
                $errorLog->errorMsg = 'Location name too long (>255 Characters)';
                $errorLog->badData = $this->name;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }
        }else{
            // No Name Given — store NULL rather than an empty string so the
            // absence is distinguishable from a location genuinely named ''.
            $this->name = null;
            $this->validState['name'] = 'valid';
        }
    }

    private function validateCC(){
        /*--
        ISO 3166-1 alpha-2, limited to the countries whose addresses can be
        validated (Address::COUNTRIES, the 40 regions Google Address
        Validation covers). A location outside that list would hold an
        address nobody has checked, so the gate is here, at the location,
        and the address never has to degrade. Two messages on one error
        number, the way 182 does: a code that is not a country, and a
        country that is not yet supported.
        --*/
        $raw = trim($this->countryCode ?? '');

        if($raw !== ''){
            $code = CountryCode::normalize($raw);
            if($code === null){
                $this->error = true;
                $this->validState['country_code'] = 'invalid';
                $this->validMsg['country_code'] = 'country_code must be an ISO 3166-1 alpha-2 code (e.g. "US", "BE").';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 51;
                $errorLog->errorMsg = 'Invalid country code';
                $errorLog->badData = $raw;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }elseif(!in_array($code, Address::COUNTRIES, true)){
                $this->error = true;
                $this->validState['country_code'] = 'invalid';
                $this->validMsg['country_code'] = 'Sorry, we can\'t validate addresses in ' . CountryCode::shortName($code) . ' yet. Locations can currently be added in: ' . implode(', ', Address::COUNTRIES) . '.';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 51;
                $errorLog->errorMsg = 'Country not in Address::COUNTRIES';
                $errorLog->badData = $code;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }else{
                // Valid
                $this->countryCode = $code;
                $this->validState['country_code'] = 'valid';
            }
        }else{
            // Missing Country Code
            // Invalid
            $this->error = true;
            $this->validState['country_code'] = 'invalid';
            $this->validMsg['country_code'] = 'Which country is this location in? We are missing the country code.';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 153;
            $errorLog->errorMsg = 'Missing country code';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
        }
    }

    /*--
    Work out what the request does to the address. Returns
    array(action => 'keep'|'set', columns => the ten column values when
    'set', coords => [lat, lng] when the validator geocoded). Sets the error
    state (nested under valid_state.address) when the address is refused.

      $address  null   not sent: 'keep' on PATCH; on POST and PUT it is
                       validated as an empty address, so the client hears
                       which fields its country requires
                false  explicit null: the same as not sent on POST and PUT,
                       refused on PATCH (345) -- an address can be corrected
                       but not removed
                array  the seven request keys: validate, 'set'

    The address is required because it rides on the location's own write:
    it was optional only while it took a second request to /address.
    Locations that predate the rule and hold no address still read and
    PATCH; their next PUT, or a PATCH that sends an address, fills it in.

    The country rule: once a location holds an address, changing its country
    without sending the address again is refused (341) -- the stored address
    was validated for the country it is in, and the two cannot disagree on
    one row. Sending the address with the new country re-validates it there.
    --*/
    private function resolveAddress($method, $newLocation, $address, array $addressPatchFields, $originalCountry){
        $result = array('action' => 'keep', 'columns' => null, 'coords' => null);
        $hadAddress = is_array($this->addressRow) && !empty($this->addressRow['line1']);

        if($address === false && $method === 'PATCH'){
            $this->error = true;
            $this->errorMsg = 'A location needs its address, so it can be corrected but not removed. Send the corrected address instead of null.';
            $this->validState['address'] = array_fill_keys(array_keys(self::ADDRESS_STATE), 'invalid');
            $this->validMsg['address'] = array_fill_keys(array_keys(self::ADDRESS_STATE), $this->errorMsg);
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 345;
            $errorLog->errorMsg = 'PATCH tried to remove a location\'s address';
            $errorLog->badData = "locationID: $this->locationID";
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
            return $result;
        }
        $missing = (($address === null || $address === false) && $method !== 'PATCH');
        if($missing){
            $address = array_fill_keys(Address::FIELDS, '');
            $addressPatchFields = array();
        }

        if(is_array($address)){
            $validator = new Address();
            $stored = ($method === 'PATCH' && !$newLocation) ? $this->addressRow : null;
            if($validator->validateFor($this->countryCode, $address, $method, $addressPatchFields, $stored)){
                $result['action'] = 'set';
                $result['columns'] = $validator->columns();
                $result['coords'] = $validator->coordinates();
                $this->address = $validator;
            }else{
                $this->error = true;
                if($validator->errorMsg !== null){ $this->errorMsg = $validator->errorMsg; }
                if($missing){ $this->errorMsg = 'Every location needs its address. Send it as an address object in the same request.'; }
                $this->responseCode = $validator->responseCode;
                $this->validState['address'] = $validator->validState;
                $this->validMsg['address'] = $validator->validMsg;
            }
        }

        if(!$this->error && !$newLocation && $hadAddress && $originalCountry !== null
            && $this->countryCode !== $originalCountry && $result['action'] === 'keep'){
            $this->error = true;
            $this->validState['country_code'] = 'invalid';
            $this->validMsg['country_code'] = 'This location holds an address validated in ' . CountryCode::shortName($originalCountry) . '. To move it to ' . CountryCode::shortName($this->countryCode) . ', send the address again in the same request so it can be validated there.';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 341;
            $errorLog->errorMsg = 'Country change on a location holding an address, address not re-sent';
            $errorLog->badData = "locationID: $this->locationID / stored: $originalCountry / requested: $this->countryCode";
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
        }
        return $result;
    }

    /* The SET clauses for an address result on an UPDATE: every column when
       set, nothing when kept. Coordinates follow the address. */
    private function addressClauses($addressResult, array &$setClauses, array &$params){
        if($addressResult === null){ return; }
        if($addressResult['action'] === 'set'){
            foreach(Address::COLUMN_NAMES as $i => $col){
                $setClauses[] = "$col=?";
                $params[] = $addressResult['columns'][$i];
            }
            if($addressResult['coords'] !== null){
                $setClauses[] = 'latitude=?';   $params[] = $addressResult['coords'][0];
                $setClauses[] = 'longitude=?';  $params[] = $addressResult['coords'][1];
            }
        }
    }

    public function validate($locationID, $saveToClass){
        // Valid?
        $valid = false;

        // Trim
        $locationID = trim($locationID ?? '');

        if(!empty($locationID)){
            // Query
            $db = new Database();
            $result = $db->query("SELECT l.id, l.brewerID, l.name, l.url, l.countryCode, l.latitude, l.longitude, l.cbVerified, l.brewerVerified, l.lastModified, l.line1, l.line2, l.locality, l.dependentLocality, l.sub_code, l.postalCode, l.formatted, l.languageCode, l.telephone, l.validatedAt, s.sub_name, s.sub_type FROM location l LEFT JOIN subdivisions s ON s.sub_code = l.sub_code WHERE l.id=?", [$locationID]);
            if(!$db->error){
                if($result->num_rows == 1){
                    // Valid Location
                    $valid = true;

                    // Save to Class?
                    if($saveToClass){
                        $array = $result->fetch_assoc();
                        $this->locationID = $locationID;
                        $this->brewerID = $array['brewerID'];
                        $this->name = $array['name'];
                        $this->url = $array['url'];
                        $this->countryCode = $array['countryCode'];
                        $this->latitude = floatval($array['latitude']);
                        $this->longitude = floatval($array['longitude']);
                        if($array['cbVerified']){
                            $this->cbVerified = true;
                        }if($array['brewerVerified']){
                            $this->brewerVerified = true;
                        }
                        $this->lastModified = intval($array['lastModified']);
                        // The address columns, kept as the row (for a PATCH
                        // merge) and as an Address (for the response)
                        $this->addressRow = $array;
                        $this->address = null;
                        if($array['line1'] !== null){
                            $this->address = new Address();
                            $this->address->loadRow($locationID, $array);
                        }
                    }
                }elseif($result->num_rows > 1){
                    // Too Many Rows
                    $this->error = true;
                    $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
                    $this->responseCode = 500;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 54;
                    $errorLog->errorMsg = 'More than one location with the same ID';
                    $errorLog->badData = "locationID: $locationID";
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                }else{
                    // Not Found
                    // Location Does Not Exist
                    $this->error = true;
                    $this->errorMsg = "Sorry, we couldn't find a location with the location_id you provided.";
                    $this->responseCode = 404;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 138;
                    $errorLog->errorMsg = 'locationID Not Found';
                    $errorLog->badData = $locationID;
                    $errorLog->filename = 'API / Location.class.php';
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
            // Missing LocationID
            $this->error = true;
            $this->errorMsg = 'Whoops, we seem to be missing the location_id for the location. Please check your request and try again.';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 53;
            $errorLog->errorMsg = 'Missing locationID';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
        }

        // Return
        return $valid;
    }

    // Locations by Brewer
    public function brewerLocations($brewerID){
        // Return Array
        $locationArray = array();

        // Validate BrewerID
        $brewer = new Brewer();
        if($brewer->validate($brewerID, false)){
            // Query Database
            // Full location objects in a single query — one LEFT JOIN per
            // location instead of the 1 + N round-trips consumers otherwise
            // need against /location/{id}. Same item shape as that endpoint,
            // minus the nested brewer (the list wrapper already carries it).
            $db = new Database();
            $result = $db->query("SELECT l.id, l.name, l.url, l.countryCode, l.latitude, l.longitude, l.cbVerified, l.brewerVerified, l.lastModified, l.line1, l.line2, l.locality, l.dependentLocality, l.sub_code, l.postalCode, l.formatted, l.languageCode, l.telephone, l.validatedAt, s.sub_name, s.sub_type FROM location l LEFT JOIN subdivisions s ON s.sub_code = l.sub_code WHERE l.brewerID=? ORDER BY l.name", [$brewerID]);
            if(!$db->error){
                while($array = $result->fetch_assoc()){
                    $locationInfo = array(
                        'id'=>$array['id'],
                        'object'=>'location',
                        'name'=>$array['name'],
                        'url'=>!empty($array['url']) ? $array['url'] : null,
                        'country_code'=>$array['countryCode'],
                        'country_short_name'=>CountryCode::shortName($array['countryCode']),
                        'latitude'=>!empty($array['latitude']) ? floatval($array['latitude']) : null,
                        'longitude'=>!empty($array['longitude']) ? floatval($array['longitude']) : null,
                        'cb_verified'=>$array['cbVerified'] ? true : false,
                        'brewer_verified'=>$array['brewerVerified'] ? true : false,
                        'last_modified'=>intval($array['lastModified'])
                    );
                    if($array['line1'] !== null){
                        // One shape builder for every address on the wire
                        $locationInfo['address'] = Address::wireObject($array);
                    }
                    $locationArray[] = $locationInfo;
                }
            }else{
                // Query Error
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = $db->responseCode;
            }
            $db->close();
        }else{
            // Invalid Brewer
            $this->error = true;
            $this->errorMsg = $brewer->errorMsg;
            $this->responseCode = $brewer->responseCode;
        }

        // Return
        return $locationArray;
    }

    // ----- Locations Nearby -----
    // Locations near a specified latitude and longitude
    public function nearbyLatLng($latitude, $longitude, $searchRadius, $metric, $cursor, $count){
        // Return Variable
        $locationArray = array();
        $nextCursor = '';

        // Default Values
        if(empty($cursor)){
            $cursor = base64_encode('0');
        }
        if(!isset($count) || $count === ''){
            $count = 100;
        }
        if(!isset($searchRadius) || $searchRadius === ''){
            $searchRadius = 25;
        }
        if($metric === 'false' || empty($metric)){
            $metric = false;
        }elseif($metric === 'true'){
            $metric = true;
        }else{
            $metric = false;
        }

        // Prep Variables
        $offset = intval(base64_decode($cursor));
        $count = intval($count);

        if(is_int($offset) && $offset >= 0){
            if(is_int($count)){
                // Within Limits?
                $numLocations = $this->countLocations();
                if($offset > $numLocations){
                    // Outside Range
                    $this->error = true;
                    $this->errorMsg = 'Sorry, the cursor value you supplied is outside our data range.';
                    $this->responseCode = 400;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 116;
                    $errorLog->errorMsg = 'Offset value outside range';
                    $errorLog->badData = "Offset: $offset / numLocations: $numLocations";
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                }

                if($count > 500 || $count < 1){
                    // Outside Range
                    $this->error = true;
                    $this->errorMsg = 'Sorry, the count value you specified is outside our acceptable range. The range we will accept is [1, 500].';
                    $this->responseCode = 400;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 117;
                    $errorLog->errorMsg = 'Count value outside range';
                    $errorLog->badData = $count;
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                }
            }else{
                // Not an integer offset
                $this->error = true;
                $this->errorMsg = 'Sorry, the count value you supplied is invalid. Please ensure you are sending an integer value.';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 118;
                $errorLog->errorMsg = 'Non-integer count value';
                $errorLog->badData = $count;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }
        }else{
            // Not an integer offset
            $this->error = true;
            $this->errorMsg = 'Sorry, the cursor value you supplied is invalid.';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 119;
            $errorLog->errorMsg = 'Invalid cursor value';
            $errorLog->badData = $offset;
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
        }

        if(!$this->error){
            // Convert to Floats
            $latitude = floatval($latitude);
            $longitude = floatval($longitude);
            $searchRadius = intval($searchRadius);

            // Ensure Nonzero
            if($latitude == 0 && $longitude == 0){
                // Middle of the ocean
                $this->error = true;
                $this->errorMsg = "It looks like you're looking for a brewery in the middle of the ocean (latitude = 0, longitude = 0). Sad to say, we aren't able to track shipboard breweries yet. You might want to check the latitude and longitude you provided.";
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 120;
                $errorLog->errorMsg = 'Invalid latitude and longitude';
                $errorLog->badData = "Latitude: $latitude / Longitude: $longitude";
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }

            if($searchRadius == 0){
                // Invalid Search Radius
                $this->error = true;
                $this->errorMsg = "Whoops, the search radius you gave us appears to be zero. You'll want a search radius greater than zero. Please double check your value.";
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 121;
                $errorLog->errorMsg = 'Invalid search radius (zero)';
                $errorLog->badData = $searchRadius;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }elseif($searchRadius < 0){
                // Invalid Search Radius
                $this->error = true;
                $this->errorMsg = "Whoops, the search radius you gave is negative. Negative distances are weird to compute. Try a positive value for your search radius.";
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 122;
                $errorLog->errorMsg = 'Invalid search radius (negative)';
                $errorLog->badData = $searchRadius;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }

            // Validate Latitude
            if($latitude < -90 || $latitude > 90){
                // Outside bounds
                $this->error = true;
                $this->errorMsg = "The latitude value you gave is is out of bounds, please check the value you gave us. The accepted range for latitude is [-90, 90].";
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 123;
                $errorLog->errorMsg = 'Invalid latitude';
                $errorLog->badData = $latitude;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }

            // Validate Longitude
            if($longitude < -180 || $longitude > 180){
                // Outside bounds
                $this->error = true;
                $this->errorMsg = "The longitude value you gave is is out of bounds, please check the value you gave us. The accepted range for longitude is [-180, 180].";
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 124;
                $errorLog->errorMsg = 'Invalid longitude';
                $errorLog->badData = $longitude;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }

            // Max of 7 decimals
            $latitude = round($latitude, 7);
            $longitude = round($longitude, 7);

            if(!$this->error){
                // Required Classes
                $db = new Database();

                // Metric or Imperial?
                if($metric){
                    // 6371 km -- https://nssdc.gsfc.nasa.gov/planetary/factsheet/earthfact.html
                    $radius = 6371;
                    $units = 'kilometers';
                }else{
                    // 3959 miles
                    $radius = 3959;
                    $units = 'miles';
                }

                // Query Database with JOINs to avoid N+1 queries
                // Haversine Formula -- https://en.wikipedia.org/wiki/Haversine_formula
                // Request count+1 to determine if there are more results (eliminates second Haversine query)
                $fetchCount = $count + 1;
                $result = $db->query("SELECT l.id, l.brewerID, l.name, l.url, l.countryCode, l.latitude, l.longitude, (2 * ? * ASIN(SQRT(SIN((RADIANS(l.latitude-?))/2) * SIN((RADIANS(l.latitude-?))/2) + COS(RADIANS(?)) * COS(RADIANS(l.latitude)) * SIN((RADIANS(l.longitude-?))/2) * SIN((RADIANS(l.longitude-?))/2)))) AS distance, b.name AS b_name, b.description AS b_description, b.shortDescription AS b_shortDescription, b.url AS b_url, b.status AS b_status, b.foundedYear AS b_foundedYear, b.closedYear AS b_closedYear, b.countryCode AS b_countryCode, b.cbVerified AS b_cbVerified, b.brewerVerified AS b_brewerVerified, l.line1, l.line2, l.locality, l.dependentLocality, l.sub_code, l.postalCode, l.formatted, l.languageCode, l.telephone, l.validatedAt, s.sub_name, s.sub_type FROM location l LEFT JOIN brewer b ON l.brewerID = b.id LEFT JOIN subdivisions s ON s.sub_code = l.sub_code HAVING distance < ? ORDER BY distance LIMIT ?, ?", [$radius, $latitude, $latitude, $latitude, $longitude, $longitude, $searchRadius, $offset, $fetchCount]);
                if(!$db->error){
                    $rowCount = 0;
                    while($array = $result->fetch_assoc()){
                        $rowCount++;
                        if($rowCount > $count){
                            // Extra row indicates more results exist
                            break;
                        }

                        // Distance
                        $distance = round(floatval($array['distance']), 1);

                        // Address block: the shared wire shape, or the
                        // legacy all-null block for a location without one.
                        $addressBlock = $array['line1'] !== null ? Address::wireObject($array) : Address::wireObject(array('countryCode' => $array['countryCode']));

                        // Build Response Array
                        $locationInfo = array('location'=>array('id'=>$array['id'], 'object'=>'location', 'name'=>$array['name'], 'brewer_id'=>$array['brewerID'], 'url'=>$array['url'], 'country_code'=>$array['countryCode'], 'country_short_name'=>CountryCode::shortName($array['countryCode']), 'latitude'=>floatval($array['latitude']), 'longitude'=>floatval($array['longitude']), 'address'=>$addressBlock), 'distance'=>array('distance'=>$distance, 'units'=>$units), 'brewer'=>array('id'=>$array['brewerID'], 'object'=>'brewer', 'name'=>$array['b_name'] ?? '', 'description'=>$array['b_description'] ?? null, 'short_description'=>$array['b_shortDescription'] ?? null, 'url'=>$array['b_url'], 'status'=>$array['b_status'], 'founded_year'=>is_null($array['b_foundedYear']) ? null : intval($array['b_foundedYear']), 'closed_year'=>is_null($array['b_closedYear']) ? null : intval($array['b_closedYear']), 'country_code'=>$array['b_countryCode'], 'cb_verified'=>$array['b_cbVerified'] ? true : false, 'brewer_verified'=>$array['b_brewerVerified'] ? true : false));

                        // Add to Array
                        $locationArray[] = $locationInfo;
                    }

                    // Determine next cursor
                    if($rowCount > $count){
                        $nextCursor = base64_encode($offset + $count);
                    }
                    $db->close();
                }else{
                    // Query Error
                    $this->error = true;
                    $this->errorMsg = $db->errorMsg;
                    $this->responseCode = $db->responseCode;
                }
            }
        }

        // Return
        return array('locationArray'=>$locationArray, 'nextCursor'=>$nextCursor);
    }

    // All Locations for Map (Admin Only)
    public function mapLocations($apiKey){
        // Validate API Key
        $apiKeys = new apiKeys();
        if($apiKeys->validate($apiKey, true)){
            $users = new Users();
            $users->validate($apiKeys->userID, true);

            if(!$users->admin){
                // Not an admin
                $this->responseCode = 401;
                $this->error = true;
                $this->errorMsg = 'Unauthorized: You must be an admin to access this endpoint.';

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 259;
                $errorLog->errorMsg = 'Unauthorized: Not admin (GET /location/map)';
                $errorLog->badData = "apiKey: $apiKey";
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }
        }else{
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 401;
        }

        if(!$this->error){
            $db = new Database();
            $result = $db->query("SELECT l.id, l.name, l.latitude, l.longitude, b.id AS b_id, b.name AS b_name FROM location l LEFT JOIN brewer b ON l.brewerID = b.id WHERE l.latitude IS NOT NULL AND l.longitude IS NOT NULL AND l.latitude != 0 AND l.longitude != 0");
            if(!$db->error){
                $locationArray = array();
                while($row = $result->fetch_assoc()){
                    $locationArray[] = array(
                        'id' => $row['id'],
                        'object' => 'location',
                        'name' => $row['name'],
                        'latitude' => floatval($row['latitude']),
                        'longitude' => floatval($row['longitude']),
                        'brewer' => array(
                            'id' => $row['b_id'],
                            'name' => $row['b_name']
                        )
                    );
                }
                $db->close();

                // Build Response
                $this->json['object'] = 'list';
                $this->json['url'] = '/location/map';
                $this->json['data'] = $locationArray;
            }else{
                // Query Error
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = 500;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 260;
                $errorLog->errorMsg = 'Database error (GET /location/map)';
                $errorLog->badData = $db->errorMsg;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }
        }
    }

    // Validate Cursor and Count
    // Same contract (and shared error numbers 94-97) as the brewer/beer lists.
    private function validateCursorCount($cursor, $count){
        // Prep Variables
        $offset = intval(base64_decode($cursor));
        $count = intval($count);

        if(is_int($offset) && $offset >= 0){
            if(is_int($count)){
                // Within Limits?
                $numLocations = $this->countLocations();
                $this->totalCount = $numLocations;
                if($offset > $numLocations){
                    // Outside Range
                    $this->error = true;
                    $this->errorMsg = 'Sorry, the cursor value you supplied is outside our data range.';
                    $this->responseCode = 400;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 96;
                    $errorLog->errorMsg = 'Offset value outside range';
                    $errorLog->badData = "Offset: $offset / numLocations: $numLocations";
                    $errorLog->filename = 'API / Location.class.php';
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
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                }
            }else{
                // Not an integer count
                $this->error = true;
                $this->errorMsg = 'Sorry, the count value you supplied is invalid. Please ensure you are sending an integer value.';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 95;
                $errorLog->errorMsg = 'Non-integer count value';
                $errorLog->badData = $count;
                $errorLog->filename = 'API / Location.class.php';
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
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
        }

        return(array($offset, $count));
    }

    // Get Location IDs
    //
    // The flat paginated list, mirroring GET /brewer and GET /beer: id, name,
    // last_modified per row. Added for the sitemap generator — /location/{id}
    // pages exist on the website but were unreachable as a set (the other
    // location GETs are all geo lookups). name is null when the location has
    // no name of its own (nullable since Jul 2026); consumers that need a
    // display title compose one, as the location page does.
    public function getLocations($cursor, $count){
        // Return Array
        $locationArray = array();

        // Validate $cursor and $count
        $cursorCountArray = $this->validateCursorCount($cursor, $count);
        $offset = $cursorCountArray[0];
        $count = $cursorCountArray[1];

        if(!$this->error){
            // Prep for Database
            $db = new Database();
            $result = $db->query("SELECT id, name, lastModified FROM location ORDER BY name LIMIT ?, ?", [$offset, $count]);
            if(!$db->error){
                while($array = $result->fetch_assoc()){
                    $locationInfo = array('id'=>$array['id'], 'name'=>$array['name'], 'last_modified'=>intval($array['lastModified']));
                    $locationArray[] = $locationInfo;
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
        return $locationArray;
    }

    public function nextCursor($cursor, $count){
        // Use cached count from validateCursorCount() called by getLocations()
        $numLocations = ($this->totalCount > 0) ? $this->totalCount : $this->countLocations();

        // Next Cursor
        $offset = intval(base64_decode($cursor));
        $nextCursor = $offset + $count;

        if($nextCursor <= $numLocations){
            // Return Next Page
            return base64_encode($nextCursor);
        }else{
            return '';
        }
    }

    // Number of Locations
    public function countLocations(){
        // Return
        $count = 0;

        // Query Database
        $db = new Database();
        $result = $db->query("SELECT COUNT(*) AS numLocations FROM location");
        if(!$db->error){
            $array = $result->fetch_assoc();
            $count = intval($array['numLocations']);
        }else{
            // Query Error
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = 400;
        }
        $db->close();

        return $count;
    }

    public function delete($locationID, $userID){
        if($this->validate($locationID, true)){
            // Get User Information
            $users = new Users();
            $users->validate($userID, true);

            // Get Brewer Information
            $brewer = new Brewer();
            $brewer->validate($this->brewerID, true);

            // Check Permissions (grant-on-domain-match keeps verification sticky)
            $privileges = new Privileges();
            $isBreweryStaff = $privileges->isBreweryStaff($users, $brewer, true);

            if($users->admin || $isBreweryStaff){
                // Look up Algolia ID before deleting
                $algolia = new Algolia();
                $algoliaId = $algolia->getAlgoliaIdByRecord('location', $locationID);

                // Delete Location
                $db = new Database();
                $db->query("DELETE FROM location WHERE id=?", [$locationID]);
                if(!$db->error){
                    // Delete from Algolia
                    if($algoliaId !== null){
                        $algolia->deleteObject('catalog', $algoliaId);
                    }

                    // Refresh the parent — its denormalized geography still
                    // carries this location's coordinates, city, and state.
                    Brewer::refreshSearchObject($this->brewerID, true);
                }else{
                    // Database Error
                    $this->error = true;
                    $this->errorMsg = $db->errorMsg;
                    $this->responseCode = $db->responseCode;
                }
                $db->close();
            }else{
                // Not Allowed to Delete
                $this->error = true;
                $this->errorMsg = 'Sorry, you do not have permission to delete this location.';
                $this->responseCode = 403;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 200;
                $errorLog->errorMsg = 'Forbidden: DELETE, /location';
                $errorLog->badData = "User: $userID / brewerID: $this->brewerID / locationID: $locationID";
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }
        }
    }

    public function updateLastModified($locationID, $clearCBVerified = false){
        // Validate Location
        if($this->validate($locationID, true)){
            // Update Last Modified Timestamp
            $db = new Database();
            $this->lastModified = time();
            if($clearCBVerified){
                // A non-admin edit to the location's address: the badge only
                // describes content an admin has reviewed (see add()).
                $this->cbVerified = false;
                $db->query("UPDATE location SET lastModified=?, cbVerified=0 WHERE id=?", [$this->lastModified, $locationID]);
            }else{
                $db->query("UPDATE location SET lastModified=? WHERE id=?", [$this->lastModified, $locationID]);
            }
            if($db->error){
                // Database Error
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = $db->responseCode;
            }
        }
    }

    public function googleMapsAPI($locationID, $addressString, $googleAPI, $saveToDB = true, $geoOptions = array()){
        // Request Parameters
        $address = urlencode($addressString ?? '');
        // Geocoding only: restrict the match to a country and bias the
        // region, so "Paris" with country:FR is never Paris, Texas.
        $geoFilter = '';
        if(!empty($geoOptions['components'])){ $geoFilter .= '&components=' . urlencode($geoOptions['components']); }
        if(!empty($geoOptions['region'])){ $geoFilter .= '&region=' . urlencode($geoOptions['region']); }

        // Headers & Options
        $headerArray = array(
            "accept: application/json"
        );

        // URL
        switch($googleAPI){
            case 'geocode':
                $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . $address . $geoFilter . '&key=' . GOOGLE_ADDRESS_VALIDATION_KEY;
                $arrayName = 'results';
                break;
            case 'findplacefromtext':
                $url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?input=' . $address . '&key=' . GOOGLE_ADDRESS_VALIDATION_KEY . '&inputtype=textquery&language=en&fields=formatted_address,geometry';
                    $arrayName = 'candidates';
                break;
        }

        $optionsArray = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $headerArray
        );

        // Create cURL Request
        $curl = curl_init();
        curl_setopt_array($curl, $optionsArray);
        $response = curl_exec($curl);
        $err = curl_error($curl);

        if(!empty($err)){
            // cURL Error -- Log It
            $this->error = true;
            $this->errorMsg = 'Looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
            $this->responseCode = 500;

            $errorLog = new LogError();
            $errorLog->errorNumber = 194;
            $errorLog->errorMsg = 'Google Maps API cURL Error';
            $errorLog->badData = $err;
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
        }else{
            // Get Latitude and Longitude
            $jsonResponse = json_decode($response);
            if($jsonResponse->status == 'OK'){
                if(count($jsonResponse->{$arrayName}) == 0){
                    // No results
                    $this->error = true;
                    $this->errorMsg = "We were not able to find a location based on the address you provided.";
                    $this->responseCode = 400;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 196;
                    $errorLog->errorMsg = 'Unable to find location (Google Maps API)';
                    $errorLog->badData = "Address String: $addressString";
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                }elseif(!$saveToDB){
                    // Return coordinates without saving
                    return array(
                        'latitude' => $jsonResponse->{$arrayName}[0]->geometry->location->lat,
                        'longitude' => $jsonResponse->{$arrayName}[0]->geometry->location->lng
                    );
                }elseif(count($jsonResponse->{$arrayName}) == 1){
                    // Valid Request, store Latitude and Longitude
                    $this->latitude = $jsonResponse->{$arrayName}[0]->geometry->location->lat;
                    $this->longitude = $jsonResponse->{$arrayName}[0]->geometry->location->lng;

                    // Add to Database
                    if($this->validate($locationID, false)){
                        // Valid Location, Update Database
                        $db = new Database();
                        $db->query("UPDATE location SET latitude=?, longitude=? WHERE id=?", [$this->latitude, $this->longitude, $locationID]);
                        if($db->error){
                            // Database Error
                            $this->error = true;
                            $this->errorMsg = $db->errorMsg;
                            $this->responseCode = $db->responseCode;
                        }
                        $db->close();
                    }

                    // Find Place From Text?
                    if($googleAPI == 'findplacefromtext'){
                        // Log as Found in error_log for later troubleshooting
                        $errorLog = new LogError();
                        $errorLog->errorNumber = 202;
                        $errorLog->errorMsg = 'Address Found by Google';
                        $errorLog->badData = 'Address String: ' . $addressString . ' // Response: ' . $jsonResponse->{$arrayName}[0]->formatted_address;
                        $errorLog->filename = 'API / Location.class.php';
                        $errorLog->write();

                        return $jsonResponse->{$arrayName}[0]->formatted_address;
                    }
                }else{
                    // More than one result, ambiguous
                    $this->error = true;
                    $this->errorMsg = "We found multiple locations based on the address you provided. Can you be more specific in your street address?";
                    $this->responseCode = 400;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 197;
                    $errorLog->errorMsg = 'Multiple Google Maps API Results';
                    $errorLog->badData = $jsonResponse;
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                }
            }elseif($jsonResponse->status == 'ZERO_RESULTS'){
                // The search was successful but returned no results
                $this->error = true;
                $this->errorMsg = "We were not able to find a location based on the address you provided.";
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 196;
                $errorLog->errorMsg = 'Unable to find location (Google Maps API)';
                $errorLog->badData = "Address String: $addressString";
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }else{
                // Google Maps API Error
                $this->error = true;
                $this->errorMsg = 'Sorry, we were not able to find an address for you. We have logged the error and our support team will look into it.';
                $this->responseCode = 500;

                $errorLog = new LogError();
                $errorLog->errorNumber = 195;
                $errorLog->errorMsg = 'Google Maps Error';
                $errorLog->badData = 'Status: ' . $jsonResponse->status . ' / Error Message: ' . ($jsonResponse->error_message ?? '');
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
            }
        }
    }

    // Store latitude/longitude for a location (used when coordinates are captured during address validation)
    public function saveCoordinates($locationID, $latitude, $longitude){
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        if($this->validate($locationID, false)){
            // Valid Location, Update Database
            $db = new Database();
            $db->query("UPDATE location SET latitude=?, longitude=? WHERE id=?", [$latitude, $longitude, $locationID]);
            if($db->error){
                // Database Error
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = $db->responseCode;
            }
            $db->close();
        }
    }

    /* The country a nearby lookup is in: US when absent, else an ISO
       3166-1 alpha-2 code (error 343 when it is not one). */
    private function nearbyCountry($country){
        $raw = trim($country ?? '');
        if($raw === ''){
            return 'US';
        }
        $code = CountryCode::normalize($raw);
        if($code === null){
            $this->error = true;
            $this->errorMsg = 'Invalid country parameter. Please provide an ISO 3166-1 alpha-2 code (e.g. "BE").';
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 343;
            $errorLog->errorMsg = 'Invalid country (nearby)';
            $errorLog->badData = $raw;
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
        }
        return $code;
    }

    /* metric defaults to the country's convention when the caller did not
       say: miles in the US, Liberia and Myanmar, kilometers everywhere else. */
    private function defaultMetric($metric, $country){
        if($metric === '' || $metric === null){
            return in_array($country, array('US', 'LR', 'MM'), true) ? 'false' : 'true';
        }
        return $metric;
    }

    public function nearbyZip($postalCode, $country, $searchRadius, $metric, $cursor, $count){
        // Validate country (US when absent)
        $country = $this->nearbyCountry($country);
        if($this->error){
            return array('locationArray' => array(), 'nextCursor' => '');
        }

        // Validate postal_code
        $postalCode = trim($postalCode ?? '');
        if(empty($postalCode)){
            $this->error = true;
            $this->errorMsg = 'Missing postal_code parameter. Please provide a postal code (a 5-digit ZIP code in the US).';
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 245;
            $errorLog->errorMsg = 'Missing postal_code';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();

            return array('locationArray' => array(), 'nextCursor' => '');
        }

        $shape = $country === 'US' ? '^\d{5}$' : (Address::POSTAL_SHAPES[$country] ?? null);
        if($shape !== null && !preg_match('/' . $shape . '/i', $postalCode)){
            $this->error = true;
            $this->errorMsg = 'Invalid postal_code format for ' . CountryCode::shortName($country) . '.' . ($country === 'US' ? ' Please provide a 5-digit US ZIP code.' : '');
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 246;
            $errorLog->errorMsg = 'Invalid postal_code format';
            $errorLog->badData = $country . ' ' . $postalCode;
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();

            return array('locationArray' => array(), 'nextCursor' => '');
        }

        // Geocode the postal code within the country
        $coords = $this->googleMapsAPI(null, $postalCode, 'geocode', false, array('components' => 'country:' . $country . '|postal_code:' . $postalCode, 'region' => strtolower($country)));
        if($coords === null){
            return array('locationArray' => array(), 'nextCursor' => '');
        }

        // Delegate to nearbyLatLng
        return $this->nearbyLatLng($coords['latitude'], $coords['longitude'], $searchRadius, $this->defaultMetric($metric, $country), $cursor, $count);
    }

    public function nearbyCity($city, $region, $country, $searchRadius, $metric, $cursor, $count){
        // Validate country (US when absent)
        $country = $this->nearbyCountry($country);
        if($this->error){
            return array('locationArray' => array(), 'nextCursor' => '');
        }

        // Validate city
        $city = trim($city ?? '');
        if(empty($city)){
            $this->error = true;
            $this->errorMsg = 'Missing city parameter. Please provide a city name.';
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 247;
            $errorLog->errorMsg = 'Missing city';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();

            return array('locationArray' => array(), 'nextCursor' => '');
        }

        // Validate region: required in the US (a city name alone is
        // ambiguous across 50 states), optional elsewhere.
        $region = trim($region ?? '');
        if(empty($region) && $country === 'US'){
            $this->error = true;
            $this->errorMsg = 'Missing region parameter. For a city in the US, provide the state name or abbreviation as region.';
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 248;
            $errorLog->errorMsg = 'Missing state';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();

            return array('locationArray' => array(), 'nextCursor' => '');
        }

        // Geocode the city within the country
        $addressString = $region !== '' ? ($city . ', ' . $region) : $city;
        $coords = $this->googleMapsAPI(null, $addressString, 'geocode', false, array('components' => 'country:' . $country, 'region' => strtolower($country)));
        if($coords === null){
            return array('locationArray' => array(), 'nextCursor' => '');
        }

        // Delegate to nearbyLatLng
        return $this->nearbyLatLng($coords['latitude'], $coords['longitude'], $searchRadius, $this->defaultMetric($metric, $country), $cursor, $count);
    }

    public function generateLocationObject($brewerObj = null){
        // Generates the Location Object
        // Generally returned as part of the API output

        // Optional Values that may be stored as null, return as empty ("")
        if(empty($this->url)){$this->url = null;}
        if(empty($this->latitude)){$this->latitude = null;}
        if(empty($this->longitude)){$this->longitude = null;}

        // Get Brewery Data
        if($brewerObj !== null){
            $brewer = $brewerObj;
        }else{
            $brewer = new Brewer();
            $brewer->validate($this->brewerID, true);
        }
        $brewer->generateBrewerObject(true);

        // Generate JSON
        $this->json['id'] = $this->locationID;
        $this->json['object'] = 'location';
        $this->json['name'] = $this->name;
        $this->json['url'] = $this->url;
        $this->json['country_code'] = $this->countryCode;
        $this->json['country_short_name'] = CountryCode::shortName($this->countryCode);
        $this->json['latitude'] = $this->latitude;
        $this->json['longitude'] = $this->longitude;
        $this->json['cb_verified'] = $this->cbVerified;
        $this->json['brewer_verified'] = $this->brewerVerified;
        $this->json['last_modified'] = $this->lastModified;
        if($this->address !== null){
            $this->json['address'] = $this->address->toArray();
        }
        $this->json['brewer'] = $brewer->json;
    }

    public function displayName($brewerName = '', $city = ''){
        /*--
        A label for this location that is always safe to render.

        Location names are optional — most breweries don't name a taproom
        separately from the community it sits in. When there's no name, identify
        the location the way a person would: by its brewer and its city. That is
        what the old required-name field was collecting by hand.
        --*/
        if(!empty($this->name)){
            return $this->name;
        }

        $brewerName = trim($brewerName ?? '');
        $city = trim($city ?? '');

        if($brewerName !== '' && $city !== ''){
            return $brewerName . ' – ' . $city;
        }elseif($brewerName !== ''){
            return $brewerName;
        }elseif($city !== ''){
            return $city;
        }

        return 'Unnamed Location';
    }

    public function generateLocationSearchObject($brewerObj = null){
        // Generates the Location Object for Algolia
        $array = array();

        // Get Brewery Data
        if($brewerObj !== null){
            $brewer = $brewerObj;
        }else{
            $brewer = new Brewer();
            $brewer->validate($this->brewerID, true);
        }

        // Get Algolia ID
        $algolia = new Algolia();
        $array['objectID'] = $algolia->getAlgoliaIdByRecord('location', $this->locationID);

        // Generate JSON
        $array['locationID'] = $this->locationID;
        if(!empty($this->url)){$array['url'] = $this->url;}
        $array['country_short_name'] = CountryCode::shortName($this->countryCode);
        if(!empty($this->latitude)){$array['_geoloc']['lat'] = $this->latitude;}
        if(!empty($this->longitude)){$array['_geoloc']['lng'] = $this->longitude;}
        $hasAddress = ($this->address !== null);
        $address = $this->address;
        $isUS = Address::isUS($this->countryCode);
        if($hasAddress){
            // The searchable street and postal code (see algolia/settings.php);
            // the unit is deliberately not indexed.
            $array['address']['line1'] = $address->line1;
            if($address->line2 !== ''){$array['address']['line2'] = $address->line2;}
            $array['address']['locality'] = $address->locality;
            if($address->sub_code !== ''){$array['address']['sub_code'] = $address->sub_code;}
            if($address->postalCode !== ''){$array['address']['postal_code'] = $address->postalCode;}
            if(!empty($address->telephone)){$array['address']['telephone'] = $address->telephone;}
        }
        // Geography under the same facet names brewers and beers use, so one
        // states/regions/cities/countries refinement works across all record
        // types. Values must match Brewer::locationFacets() exactly or the
        // facet splits: countryCode ("US", not the long name), short state
        // codes in `states` (US only), ISO 3166-2 in `regions` (every
        // country). address.* stays as-is for display.
        if(!empty($this->countryCode)){$array['countries'] = array($this->countryCode);}
        if($hasAddress){
            if($isUS && !empty($address->stateShort)){$array['states'] = array($address->stateShort);}
            if($address->sub_code !== ''){$array['regions'] = array($address->sub_code);}
            if($address->locality !== ''){$array['cities'] = array($address->locality);}
        }

        $array['brewer']['brewerID'] = $brewer->brewerID;
        $array['brewer']['name'] = $brewer->name;

        // Name is optional on a location, but a search hit needs a literal
        // title, so compose one when it's absent. The address has to be loaded
        // before this point — hence the ordering above.
        $array['name'] = $this->displayName($brewer->name, $hasAddress ? $address->locality : '');

        // SiteSearch Fields
        $array['type'] = 'location';
        // Cross-type tie-break — see customRanking in algolia/settings.php.
        $array['type_rank'] = 20;
        $array['subtitle'] = $brewer->name;
        // Points at the location's own page. This previously pointed at the
        // parent brewer, which made location and brewer hits collide on one
        // destination — invisible in an 8-hit modal, obvious duplication in a
        // full results list. DEPLOY ORDER: the frontend /location/{id} pages
        // must exist before a re-index ships this, or these results 404.
        $array['page_url'] = '/location/' . $this->locationID;

        return $array;
    }

    /*--
    Reduce the request's nested `address` to what add() takes: [null, []]
    when absent, [false, []] for an explicit null, or [fields, sent]
    where fields holds the seven keys ('' when absent) and sent the ones the
    request carried (an explicit null inside the object counts as sent and
    clears that field on PATCH). Anything else -- a string, a number, an
    array -- is a 400 on `address`.
    --*/
    private function readAddressInput($data){
        if(!is_object($data) || !property_exists($data, 'address')){
            return array(null, array());
        }
        if(is_null($data->address)){
            return array(false, array());
        }
        if(!is_object($data->address)){
            $this->error = true;
            $this->responseCode = 400;
            $this->validState['address'] = array_fill_keys(array_keys(self::ADDRESS_STATE), 'invalid');
            $this->validMsg['address'] = array_fill_keys(array_keys(self::ADDRESS_STATE), 'address must be an object with line1, line2, locality, dependent_locality, sub_code, postal_code and telephone.');

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 344;
            $errorLog->errorMsg = 'address is not an object';
            $errorLog->badData = substr(json_encode($data->address), 0, 200);
            $errorLog->filename = 'API / Location.class.php';
            $errorLog->write();
            return array(null, array());
        }
        $fields = array();
        $sent = array();
        foreach(Address::FIELDS as $key){
            $fields[$key] = '';
            if(property_exists($data->address, $key)){
                $value = $data->address->{$key};
                $fields[$key] = is_null($value) ? '' : (is_scalar($value) ? strval($value) : '');
                $sent[] = $key;
            }
        }
        return array($fields, $sent);
    }

    public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
        /*---
        {METHOD} https://api.catalog.beer/location/{function}
        {METHOD} https://api.catalog.beer/location/{id}/{function}

        GET https://api.catalog.beer/location/{location_id}
        GET https://api.catalog.beer/location/nearby
        GET https://api.catalog.beer/location/zip
        GET https://api.catalog.beer/location/city

        POST https://api.catalog.beer/location

        PUT https://api.catalog.beer/location/{location_id}

        PATCH https://api.catalog.beer/location/{location_id}

        DELETE https://api.catalog.beer/location/{location_id}
        ---*/
        // Connect to Class


        // Location GET parameters (read directly from URL, not from $data)
        $latitude = $_GET['latitude'] ?? 0;
        $longitude = $_GET['longitude'] ?? 0;
        $searchRadius = $_GET['search_radius'] ?? null;
        $metric = $_GET['metric'] ?? '';
        $postalCode = $_GET['postal_code'] ?? null;
        $city = $_GET['city'] ?? null;
        $region = $_GET['region'] ?? null;
        $country = $_GET['country'] ?? null;     // US when absent

        switch($method){
            case 'GET':
                if(!empty($id) && empty($function)){
                    // GET https://api.catalog.beer/location/{location_id}
                    // Validate ID
                    if($this->validate($id, true)){
                        // Valid Location
                        $this->generateLocationObject();
                    }else{
                        // Invalid Location
                        $this->json['error'] = true;
                        $this->json['error_msg'] = $this->errorMsg;
                    }
                }elseif($function == 'countries' || $function == 'subdivisions'){
                    // GET https://api.catalog.beer/location/countries
                    // GET https://api.catalog.beer/location/subdivisions?country=BE
                    // The reference lists behind the address: the countries a
                    // location may be in, and a country's ISO 3166-2 regions.
                    $lists = new Address();
                    if($function === 'countries'){
                        $lists->countries();
                    }else{
                        $lists->subdivisions($_GET['country'] ?? '');
                    }
                    if(!$lists->error){
                        $this->json = $lists->json;
                    }else{
                        $this->error = true;
                        $this->errorMsg = $lists->errorMsg;
                        $this->responseCode = $lists->responseCode;
                        $this->json['error'] = true;
                        $this->json['error_msg'] = $this->errorMsg;
                    }
                }elseif($function == 'nearby'){
                    // GET https://api.catalog.beer/location/nearby
                    $nearbyLatLngReturn = $this->nearbyLatLng($latitude, $longitude, $searchRadius, $metric, $cursor, $count);
                    if(!$this->error){
                        // Start JSON
                        $this->json['object'] = 'list';
                        $this->json['url'] = '/location/nearby';

                        // Next Cursor
                        if(!empty($nearbyLatLngReturn['nextCursor'])){
                            $this->json['has_more'] = true;
                            $this->json['next_cursor'] = $nearbyLatLngReturn['nextCursor'];
                        }else{
                            $this->json['has_more'] = false;
                        }

                        // Append Data
                        $this->json['data'] = $nearbyLatLngReturn['locationArray'];
                    }else{
                        $this->json['error'] = true;
                        $this->json['error_msg'] = $this->errorMsg;
                    }

                }elseif($function == 'zip'){
                    // GET https://api.catalog.beer/location/zip
                    $nearbyReturn = $this->nearbyZip($postalCode, $country, $searchRadius, $metric, $cursor, $count);
                    if(!$this->error){
                        // Start JSON
                        $this->json['object'] = 'list';
                        $this->json['url'] = '/location/zip';

                        // Next Cursor
                        if(!empty($nearbyReturn['nextCursor'])){
                            $this->json['has_more'] = true;
                            $this->json['next_cursor'] = $nearbyReturn['nextCursor'];
                        }else{
                            $this->json['has_more'] = false;
                        }

                        // Append Data
                        $this->json['data'] = $nearbyReturn['locationArray'];
                    }else{
                        $this->json['error'] = true;
                        $this->json['error_msg'] = $this->errorMsg;
                    }

                }elseif($function == 'city'){
                    // GET https://api.catalog.beer/location/city
                    $nearbyReturn = $this->nearbyCity($city, $region, $country, $searchRadius, $metric, $cursor, $count);
                    if(!$this->error){
                        // Start JSON
                        $this->json['object'] = 'list';
                        $this->json['url'] = '/location/city';

                        // Next Cursor
                        if(!empty($nearbyReturn['nextCursor'])){
                            $this->json['has_more'] = true;
                            $this->json['next_cursor'] = $nearbyReturn['nextCursor'];
                        }else{
                            $this->json['has_more'] = false;
                        }

                        // Append Data
                        $this->json['data'] = $nearbyReturn['locationArray'];
                    }else{
                        $this->json['error'] = true;
                        $this->json['error_msg'] = $this->errorMsg;
                    }

                }elseif($function == 'map'){
                    // GET https://api.catalog.beer/location/map
                    // Admin-only: All locations with coordinates for map display
                    $this->mapLocations($apiKey);
                    if($this->error){
                        $this->json['error'] = true;
                        $this->json['error_msg'] = $this->errorMsg;
                    }

                }elseif(empty($id) && empty($function)){
                    // List Locations
                    // GET https://api.catalog.beer/location
                    // Same paginated shape as GET /brewer and GET /beer.
                    // index.php nulls $count for /location (the geo lookups
                    // have their own defaults) — restore the standard default.
                    if($count === null){
                        $count = 500;
                    }
                    $locationArray = $this->getLocations($cursor, $count);
                    if(!$this->error){
                        // Start JSON
                        $this->json['object'] = 'list';
                        $this->json['url'] = '/location';

                        // Next Cursor
                        $nextCursor = $this->nextCursor($cursor, $count);
                        if(!empty($nextCursor)){
                            $this->json['has_more'] = true;
                            $this->json['next_cursor'] = $nextCursor;
                        }else{
                            $this->json['has_more'] = false;
                        }

                        // Append Data
                        $this->json['data'] = $locationArray;
                    }else{
                        $this->json['error'] = true;
                        $this->json['error_msg'] = $this->errorMsg;
                    }

                }else{
                    // Invalid Function
                    $this->responseCode = 404;
                    $this->json['error'] = true;
                    $this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 152;
                    $errorLog->errorMsg = 'Invalid function (/location)';
                    $errorLog->badData = $function;
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                }
                break;
            case 'POST':
                if(!empty($function)){
                    // Method Not Allowed for /location/{function}
                    $this->responseCode = 405;
                    $this->responseHeader = 'Allow: GET';
                    $this->json['error'] = true;
                    $this->json['error_msg'] = 'Method Not Allowed. Use GET for this endpoint.';

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 241;
                    $errorLog->errorMsg = 'Invalid Method for /location/' . $function;
                    $errorLog->badData = $method;
                    $errorLog->filename = 'API / Location.class.php';
                    $errorLog->write();
                    break;
                }

                // POST https://api.catalog.beer/location
                // Add Location

                // Handle Empty Fields
                if(empty($data->brewer_id)){$data->brewer_id = '';}
                if(empty($data->name)){$data->name = '';}
                if(empty($data->url)){$data->url = '';}
                if(empty($data->country_code)){$data->country_code = '';}

                // Validate API Key for userID
                $apiKeys = new apiKeys();
                $apiKeys->validate($apiKey, true);

                list($address, $addressPatchFields) = $this->readAddressInput($data);
                if($this->error){ $this->json['error'] = true; $this->json['error_msg'] = $this->errorMsg; $this->json['valid_state'] = $this->validState; $this->json['valid_msg'] = $this->validMsg; break; }
                $this->add($data->brewer_id, $data->name, $data->url, $data->country_code, $apiKeys->userID, 'POST', '', array(), $address, $addressPatchFields);
                if(!$this->error){
                    // JSON Response
                    $this->generateLocationObject($this->brewerObj);
                }else{
                    // Error Adding Location
                    $this->json['error'] = true;
                    $this->json['error_msg'] = $this->errorMsg;
                    $this->json['valid_state'] = $this->validState;
                    $this->json['valid_msg'] = $this->validMsg;
                }
                break;
            case 'PUT':
                if(!empty($function)){
                    // Method Not Allowed for /location/{function}
                    $this->responseCode = 405;
                    $this->responseHeader = 'Allow: GET';
                    $this->json['error'] = true;
                    $this->json['error_msg'] = 'Method Not Allowed. Use GET for this endpoint.';
                    break;
                }

                // PUT https://api.catalog.beer/location/{location_id}

                // Handle Empty Fields
                if(empty($data->brewer_id)){$data->brewer_id = '';}
                if(empty($data->name)){$data->name = '';}
                if(empty($data->url)){$data->url = '';}
                if(empty($data->country_code)){$data->country_code = '';}

                // Validate API Key for userID
                $apiKeys = new apiKeys();
                $apiKeys->validate($apiKey, true);

                list($address, $addressPatchFields) = $this->readAddressInput($data);
                if($this->error){ $this->json['error'] = true; $this->json['error_msg'] = $this->errorMsg; $this->json['valid_state'] = $this->validState; $this->json['valid_msg'] = $this->validMsg; break; }
                $this->add($data->brewer_id, $data->name, $data->url, $data->country_code, $apiKeys->userID, 'PUT', $id, array(), $address, $addressPatchFields);
                if(!$this->error){
                    // JSON Response
                    $this->generateLocationObject($this->brewerObj);
                }else{
                    // Error Adding Location
                    $this->json['error'] = true;
                    $this->json['error_msg'] = $this->errorMsg;
                    $this->json['valid_state'] = $this->validState;
                    $this->json['valid_msg'] = $this->validMsg;
                }
                break;
            case 'PATCH':
                if(!empty($function)){
                    // Method Not Allowed for /location/{function}
                    $this->responseCode = 405;
                    $this->responseHeader = 'Allow: GET';
                    $this->json['error'] = true;
                    $this->json['error_msg'] = 'Method Not Allowed. Use GET for this endpoint.';
                    break;
                }

                // PATCH https://api.catalog.beer/location/{location_id}
                // Which fields are we updating?
                $patchFields = array();

                /*--
                Handle Empty Fields

                property_exists, not isset — isset() is false for an explicit
                null, which is exactly how a client clears a field. name and url
                are both nullable and both clear this way. brewer_id and
                country_code are NOT NULL; they are listed the same way so their
                validators can answer an explicit null with a 400 rather than
                letting it pass as "field not supplied".

                index.php guarantees $data is an object, so no is_object() guard
                is needed here.
                --*/
                if(property_exists($data, 'brewer_id')){$patchFields[] = 'brewerID';}
                else{$data->brewer_id = '';}

                if(property_exists($data, 'name')){$patchFields[] = 'name';}
                else{$data->name = '';}

                if(property_exists($data, 'url')){$patchFields[] = 'url';}
                else{$data->url = '';}

                if(property_exists($data, 'country_code')){$patchFields[] = 'countryCode';}
                else{$data->country_code = '';}

                // Validate API Key for userID
                $apiKeys = new apiKeys();
                $apiKeys->validate($apiKey, true);

                list($address, $addressPatchFields) = $this->readAddressInput($data);
                if($this->error){ $this->json['error'] = true; $this->json['error_msg'] = $this->errorMsg; $this->json['valid_state'] = $this->validState; $this->json['valid_msg'] = $this->validMsg; break; }
                $this->add($data->brewer_id, $data->name, $data->url, $data->country_code, $apiKeys->userID, 'PATCH', $id, $patchFields, $address, $addressPatchFields);
                if(!$this->error){
                    // JSON Response
                    $this->generateLocationObject($this->brewerObj);
                }else{
                    // Error Adding Location
                    $this->json['error'] = true;
                    $this->json['error_msg'] = $this->errorMsg;
                    $this->json['valid_state'] = $this->validState;
                    $this->json['valid_msg'] = $this->validMsg;
                }
                break;
            case 'DELETE':
                if(!empty($function)){
                    // Method Not Allowed for /location/{function}
                    $this->responseCode = 405;
                    $this->responseHeader = 'Allow: GET';
                    $this->json['error'] = true;
                    $this->json['error_msg'] = 'Method Not Allowed. Use GET for this endpoint.';
                    break;
                }

                // DELETE https://api.catalog.beer/location/{location_id}
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
                $this->responseHeader = 'Allow: GET, POST, DELETE';

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 74;
                $errorLog->errorMsg = 'Invalid Method (/location)';
                $errorLog->badData = $method;
                $errorLog->filename = 'API / Location.class.php';
                $errorLog->write();
        }
    }
}
