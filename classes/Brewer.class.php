<?php
class Brewer {

    // Properties
    public $brewerID = '';
    public $name = '';
    public $description = '';           // Optional
    public $shortDescription = '';      // Optional
    public $url = '';                   // Optional
    public $domainName = '';            // Optional
    public $cbVerified = false;
    public $brewerVerified = false;
    public $urlStatus = '';             // Internal — never in the brewer object
    private $urlNote = '';              // Write-only curation note — never in the brewer object
    public $lastModified = 0;

    // Error Handling
    public $error = false;
    public $errorMsg = null;
    public $validState = array('name'=>null, 'url'=>null, 'description'=>null, 'short_description'=>null);
    public $validMsg = array('name'=>null, 'url'=>null, 'description'=>null, 'short_description'=>null);
    private $filename = 'API / Brewer.class.php';
    private $totalCount = 0;

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    // Add Brewer
    public function add($name, $description, $shortDescription, $url, $userID, $method, $brewerID, $patchFields, $urlNote = ''){

        // Required Classes
        $db = new Database();
        $users = new Users();
        $privileges = new Privileges();

        // ----- brewerID -----
        $uuid = new uuid();
        $newBrewer = false;
        $urlVerified = false;
        $originalName = null;   // Set on PUT/PATCH of an existing brewer; null means "no rename to cascade"
        $originalURL = null;    // Set on PUT/PATCH of an existing brewer — a changed URL resets the url-monitoring columns
        $originalURLStatus = null;  // The monitoring verdict the URL change is reacting to; recorded in brewer_url_history
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
                if($this->validate($brewerID, true)){
                    // Valid Brewer - Update Existing Entry
                    // $this->domainName saved via $this->validate() function above
                    $this->brewerID = $brewerID;
                    // Save original values for permissions check
                    $originalCBV = $this->cbVerified;
                    $originalBV = $this->brewerVerified;
                    // Save original name to detect a rename — drives the Algolia cascade below
                    $originalName = $this->name;
                    // Save original URL to detect a change — drives the url-monitoring reset below
                    $originalURL = $this->url;
                    $originalURLStatus = $this->urlStatus;
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
                    // Save original values for permissions check
                    $originalCBV = $this->cbVerified;
                    $originalBV = $this->brewerVerified;
                    // Save original name to detect a rename — drives the Algolia cascade below
                    $originalName = $this->name;
                    // Save original URL to detect a change — drives the url-monitoring reset below
                    $originalURL = $this->url;
                    $originalURLStatus = $this->urlStatus;
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
                $errorLog->filename = $this->filename;
                $errorLog->write();
        }

        // ----- URL-only Edit? -----
        /*--
        A PATCH that changes nothing but the URL is data cleanup — repairing link
        rot, clearing a domain that lapsed and was re-registered by someone else.
        It says nothing about whether the rest of the record is accurate, so the
        verification badges below are left exactly as they were. Compared against
        the stored values, not just $patchFields, so resending an unchanged name
        alongside a new URL still counts as URL-only.
        --*/
        $urlOnlyEdit = false;
        if($method == 'PATCH' && !$this->error && !$newBrewer){
            $changesURL = in_array('url', $patchFields) && $url != $originalURL;
            $changesOther = (in_array('name', $patchFields) && $name != $this->name)
                || (in_array('description', $patchFields) && $description != $this->description)
                || (in_array('short_description', $patchFields) && $shortDescription != $this->shortDescription);
            $urlOnlyEdit = $changesURL && !$changesOther;
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
                        // Use saved cb_verified and brewer_verified flags from validate()
                        $cbVerified = $originalCBV;
                        $brewerVerified = $originalBV;

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
                                    $errorLog->filename = $this->filename;
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
                                        $errorLog->filename = $this->filename;
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
                $addPrivileges = false;
                $removePrivileges = false;

                // Get User Info
                if($users->admin){
                    if($urlOnlyEdit){
                        /*--
                        An admin fixing a broken or hijacked link is cleaning up
                        bad data, not vouching for the entry. Carry both badges
                        through untouched: setting cbVerified here would lock the
                        brewer to admin-only editing, and the shared UPDATE below
                        writes brewerVerified from $dbBV, which stays 0 on the
                        admin path — so a brewery's own verification would be
                        cleared as a side effect of a URL correction.
                        --*/
                        $this->cbVerified = $originalCBV;
                        $dbCBV = $originalCBV ? 1 : 0;
                        $this->brewerVerified = $originalBV;
                        $dbBV = $originalBV ? 1 : 0;
                    }else{
                        // Catalog.beer Verified
                        $this->cbVerified = true;
                        $dbCBV = 1;
                    }
                }else{
                    // Not Catalog.beer Verified
                    if(!empty($this->domainName)){
                        // URL/Domain Name Present
                        if($newBrewer){
                            if($userEmailDomain == $this->domainName){
                                // User has email associated with the brewery, give breweryValidated flag.
                                $this->brewerVerified = true;
                                $dbBV = 1;
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
                                        $dbBV = 1;
                                    }elseif($userEmailDomain == $this->domainName){
                                        // User has email associated with the brewery, give breweryValidated flag.
                                        $this->brewerVerified = true;
                                        $dbBV = 1;
                                        $addPrivileges = true;
                                    }
                                }else{
                                    // New Domain Name
                                    if($userEmailDomain == $newDomainName){
                                        // Retain Brewer Privileges
                                        $this->brewerVerified = true;
                                        $dbBV = 1;
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
                                    $dbBV = 1;
                                }elseif($userEmailDomain == $this->domainName){
                                    // User has email associated with the brewery, give breweryValidated flag.
                                    $this->brewerVerified = true;
                                    $dbBV = 1;
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
            // Track whether we should run the query
            $runQuery = false;

            /*--
            url_note reaches every write method, so validate it once here rather
            than in each branch. Gated on admin because that is the only case
            where the note is kept — a non-admin's note is dropped at the
            logURLChange() call below and never stored, so validating it would
            reject a request over a value we were going to discard anyway.
            --*/
            if($users->admin){
                $this->urlNote = $urlNote;
                $this->validateURLNote();
            }

            if($method == 'POST' || $method == 'PUT'){
                // Validate Name
                $this->name = $name;
                $this->validateName();

                // Validate URLs
                if(!$urlVerified){
                    // Validate Submitted URL
                    $this->url = $this->validateURL($url, 'url', 'brewer');
                }

                // Validate Description
                $this->description = $description;
                $this->validateDescription();

                // Validate Short Description
                $this->shortDescription = $shortDescription;
                $this->validateShortDescription();

                if(!$this->error){
                    $this->lastModified = time();

                    // Construct SQL Statement
                    if($newBrewer){
                        // Add Brewer (POST/PUT)
                        // createdAt is written here only — the PUT and PATCH
                        // update paths below never touch it
                        $columns = ['id', 'name', 'cbVerified', 'brewerVerified', 'createdAt', 'lastModified'];
                        $params = [$this->brewerID, $this->name, $dbCBV, $dbBV, $this->lastModified, $this->lastModified];
                        if(!empty($this->description)){
                            $columns[] = 'description';
                            $params[] = $this->description;
                        }
                        if(!empty($this->shortDescription)){
                            $columns[] = 'shortDescription';
                            $params[] = $this->shortDescription;
                        }
                        if(!empty($this->url)){
                            $columns[] = 'url';
                            $params[] = $this->url;
                            $columns[] = 'domainName';
                            $params[] = $this->domainName;
                        }
                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $sql = "INSERT INTO brewer (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                        $db->query($sql, $params);
                    }else{
                        // Update Brewer (PUT)
                        // PUT is a full replacement — omitted fields are cleared
                        $setClauses = ['name=?', 'cbVerified=?', 'brewerVerified=?', 'lastModified=?'];
                        $setParams = [$this->name, $dbCBV, $dbBV, $this->lastModified];
                        if(!empty($this->description)){
                            $setClauses[] = 'description=?';
                            $setParams[] = $this->description;
                        }else{
                            $setClauses[] = 'description=NULL';
                        }
                        if(!empty($this->shortDescription)){
                            $setClauses[] = 'shortDescription=?';
                            $setParams[] = $this->shortDescription;
                        }else{
                            $setClauses[] = 'shortDescription=NULL';
                        }
                        $this->urlSetClauses($originalURL, $setClauses, $setParams);
                        $sql = "UPDATE brewer SET " . implode(', ', $setClauses) . " WHERE id=?";
                        $setParams[] = $this->brewerID;
                        $db->query($sql, $setParams);
                    }
                    $runQuery = true;
                }
            }elseif($method == 'PATCH'){
                /*--
                Validate the field if it's different than what is currently stored.
                Check against the $this->{var} which we have from performing a $this->validate($brewerID, true) in the brewerID flow above for PATCH.
                --*/

                // SQL Update
                $setClauses = array();
                $setParams = array();

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

                // Validate URLs
                if(in_array('url', $patchFields)){
                    if($url != $this->url){
                        $this->url = $this->validateURL($url, 'url', 'brewer');
                        if(!$this->error){
                            $this->urlSetClauses($originalURL, $setClauses, $setParams);
                        }
                    }
                }

                /*--
                Validate first, compare after, and normalise '' to null before
                storing — the same shape beer's abv/ibu use, for three reasons:

                - The column is nullable and PUT clears it to NULL. PATCH wrote
                  '' here, so the two paths disagreed on what "no description"
                  looks like and a cleared record read back as '' from one and
                  null from the other.
                - Loose != treats null and '' as equal, so a row cleared to ''
                  by the old path could never be moved to NULL. Strict !==
                  against the stored value repairs those rows on next write.
                - Comparing before validating skipped validation entirely on a
                  loose match.
                --*/
                if(in_array('description', $patchFields)){
                    $currentDescription = $this->description;
                    $this->description = $description;
                    $this->validateDescription();
                    if($this->description === ''){$this->description = null;}
                    if(!$this->error && $this->description !== $currentDescription){
                        $setClauses[] = "description=?";
                        $setParams[] = $this->description;
                    }
                }

                // Validate Short Description
                if(in_array('short_description', $patchFields)){
                    $currentShortDescription = $this->shortDescription;
                    $this->shortDescription = $shortDescription;
                    $this->validateShortDescription();
                    if($this->shortDescription === ''){$this->shortDescription = null;}
                    if(!$this->error && $this->shortDescription !== $currentShortDescription){
                        $setClauses[] = "shortDescription=?";
                        $setParams[] = $this->shortDescription;
                    }
                }

                if(!$this->error && !empty($setClauses)){
                    $this->lastModified = time();
                    $sql = "UPDATE brewer SET lastModified=?, cbVerified=?, brewerVerified=?";
                    $params = [$this->lastModified, $dbCBV, $dbBV];
                    if(!empty($setClauses)){
                        $sql .= ", " . implode(", ", $setClauses);
                        $params = array_merge($params, $setParams);
                    }
                    $sql .= " WHERE id=?";
                    $params[] = $this->brewerID;
                    $db->query($sql, $params);
                    $runQuery = true;
                }elseif(!$this->error){
                    /*--
                    Nothing to write: the PATCH carried no brewer field, or every
                    value it carried already matched the stored one. The UPDATE
                    above is the only statement that writes the badges, so they
                    are still whatever the row already held — report those, not
                    the values the admin branch put in memory further up.

                    Without this, an admin PATCH of url_note alone (or of {})
                    came back cb_verified: true over a row that still said false,
                    because the response is rendered from class state. The stored
                    value was the correct one: a note about a URL change is not a
                    review of the record, so it should not verify it. Only the
                    response was wrong.
                    --*/
                    if(isset($originalCBV)){
                        $this->cbVerified = $originalCBV;
                    }
                    if(isset($originalBV)){
                        $this->brewerVerified = $originalBV;
                    }
                }
            }

            if($runQuery){
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

                        // Create Algolia ID and sync to Algolia
                        $algolia = new Algolia();
                        $algolia->add('brewer', $this->brewerID);
                        $algolia->saveObject('catalog', $this->generateBrewerSearchObject());
                    }else{
                        $this->responseCode = 200;

                        // Sync updated brewer to Algolia
                        $algolia = new Algolia();
                        $algolia->saveObject('catalog', $this->generateBrewerSearchObject());

                        // Cascade a rename down to this brewer's beers and
                        // locations, which carry the name denormalized. Guarded
                        // on an actual change — most updates never touch the
                        // name, and this is the expensive path.
                        if($originalName !== null && $originalName !== $this->name){
                            $this->cascadeNameToChildren();
                        }

                        /*--
                        Record the URL change, after the row is updated so a
                        failed write leaves no phantom entry. Loose != so an
                        unchanged URL is not logged and a NULL original equals an
                        empty submission — a brewer whose URL we cleared earlier
                        has a NULL original, and gaining a URL back is a real
                        change worth recording.
                        --*/
                        if($this->url != $originalURL){
                            // url_note is a curation field: admins only. It is
                            // never echoed back, so there is nothing for a
                            // general key to gain by writing to it.
                            self::logURLChange($this->brewerID, $originalURL, $this->url, $originalURLStatus, 'api', ($users->admin ? $this->urlNote : null), $userID);
                        }
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
        $this->name = TextInput::trim($this->name);

        /*--
        Shape before length: a control character is a hard reject at any length,
        and naming the offending character is far more use to the submitter than
        "too long" would be. TextInput::check() returns '' for an empty value, so
        the required/optional handling below is unaffected.
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
            $errorLog->errorMsg = 'Forbidden characters in brewer name';
            $errorLog->badData = $this->name;
            $errorLog->filename = $this->filename;
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
                $this->validMsg['name'] = 'We hate to say it but your brewery name is too long for our database. Brewery names are limited to 255 bytes. Any chance you can shorten it?';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 21;
                $errorLog->errorMsg = 'Brewery name too long (>255 Characters)';
                $errorLog->badData = $this->name;
                $errorLog->filename = $this->filename;
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
            $errorLog->filename = $this->filename;
            $errorLog->write();
        }
    }

    private function validateDescription(){
        // Must set $this->description
        $this->description = TextInput::trim($this->description);

        // Newlines allowed here — 77% of brewer descriptions in production use
        // them, and the frontend renders them with white-space: pre-line.
        $problem = TextInput::check($this->description, true);
        if($problem !== ''){
            $this->error = true;
            $this->validState['description'] = 'invalid';
            $this->validMsg['description'] = $problem;
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 305;
            $errorLog->errorMsg = 'Forbidden characters in brewer description';
            $errorLog->badData = $this->description;
            $errorLog->filename = $this->filename;
            $errorLog->write();
            return;
        }

        if(!empty($this->description)){
            // 65,535 is the TEXT column's capacity, not 65,536. Allowing the
            // extra byte let a description pass validation and then fail on
            // INSERT with MySQL 1406 — a 500 where the user should have been
            // told 400. The limit counts bytes, not characters.
            if(strlen($this->description) <= 65535){
                // Valid
                $this->validState['description'] = 'valid';
            }else{
                // Description Too Long
                $this->error = true;
                $this->validState['description'] = 'invalid';
                $this->validMsg['description'] = 'We hate to say it but this brewery description is too long for our database. Descriptions are limited to 65,535 bytes. Any chance you can shorten it?';
                $this->responseCode = 400;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 20;
                $errorLog->errorMsg = 'Brewery description too long (>65535 bytes)';
                $errorLog->badData = $this->description;
                $errorLog->filename = $this->filename;
                $errorLog->write();
            }
        }
    }

    private function validateShortDescription(){
        // Must set $this->shortDescription
        $this->shortDescription = TextInput::trim($this->shortDescription);

        // Single line: this is the <meta name="description"> source, and no
        // production row has ever contained a newline.
        $problem = TextInput::check($this->shortDescription);
        if($problem !== ''){
            $this->error = true;
            $this->validState['short_description'] = 'invalid';
            $this->validMsg['short_description'] = $problem;
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 305;
            $errorLog->errorMsg = 'Forbidden characters in brewer short description';
            $errorLog->badData = $this->shortDescription;
            $errorLog->filename = $this->filename;
            $errorLog->write();
            return;
        }

        if(!empty($this->shortDescription)){
            if(strlen($this->shortDescription) <= 160){
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
                $errorLog->filename = $this->filename;
                $errorLog->write();
            }
        }
    }

    /*--
    url_note is write-only curation metadata: admins only, never echoed back,
    and stored on the brewer_url_history row rather than on the brewer. It was
    the one writable free-text field with no trim and no length check — an
    overlong note was silently byte-truncated on its way into the history row.

    Reported through errorMsg rather than validMsg because validState/validMsg
    mirror the fields of the brewer object, and url_note is not one of them.
    Adding a key there would also change the shape of every brewer 400.
    --*/
    private function validateURLNote(){
        $this->urlNote = TextInput::trim($this->urlNote);

        if($this->urlNote === ''){
            return;
        }

        // Single line. Reported through errorMsg for the same reason as the
        // length failure below — url_note is not a field of the brewer object.
        $problem = TextInput::check($this->urlNote);
        if($problem !== ''){
            $this->error = true;
            $this->errorMsg = $problem;
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 305;
            $errorLog->errorMsg = 'Forbidden characters in brewer url_note';
            $errorLog->badData = $this->urlNote;
            $errorLog->filename = $this->filename;
            $errorLog->write();
            return;
        }

        /*--
        Count characters, not bytes: the note column is varchar(255) and MySQL
        measures varchar in characters. preg_match_all() returns false only on
        malformed UTF-8, which index.php has already rejected before we get
        here — so treating that as invalid is the safe reading.
        --*/
        $length = preg_match_all('/./us', $this->urlNote);

        if($length !== false && $length <= 255){
            return;
        }

        // Note Too Long
        $this->error = true;
        $this->errorMsg = 'Sorry, the note explaining this URL change is too long. Notes are limited to 255 characters. Any chance you can shorten it?';
        $this->responseCode = 400;

        // Log Error
        $errorLog = new LogError();
        $errorLog->errorNumber = 304;
        $errorLog->errorMsg = 'Brewer url_note too long (>255 characters)';
        $errorLog->badData = $this->urlNote;
        $errorLog->filename = $this->filename;
        $errorLog->write();
    }

    /*--
    Trim a note to the note column's 255 characters without splitting a
    multibyte character. substr() counts bytes, so it could hand bind_param a
    dangling lead byte — MySQL then rejects the row with error 1366 and the
    history entry is lost silently, since logURLChange() only logs that failure
    rather than surfacing it. mb_substr() is not an option: production has no
    mbstring extension.

    validateURLNote() already 400s an overlong note from the API, so this is the
    backstop for the other callers of the public static logURLChange().
    --*/
    private static function truncateNote($note){
        if(preg_match('/^.{0,255}/us', $note, $match)){
            return $match[0];
        }

        // Malformed UTF-8 would fail in MySQL the same way, so drop the note
        // rather than lose the whole history row with it.
        return null;
    }

    /*--
    Builds the url/domainName/url-monitoring half of an UPDATE for PUT and PATCH.
    $this->url and $this->domainName must already be validated. Appends to
    $setClauses/$setParams by reference so both callers stay in step — the two
    paths drifting apart is what let PATCH write '' where PUT wrote NULL.
    --*/
    private function urlSetClauses($originalURL, &$setClauses, &$setParams){
        if($this->url !== ''){
            $setClauses[] = 'url=?';
            $setParams[] = $this->url;
            $setClauses[] = 'domainName=?';
            $setParams[] = $this->domainName;
        }else{
            // Clearing. Both go to NULL, never '' — each column carries a UNIQUE
            // index, where '' is an ordinary value, so the second brewer cleared
            // this way would collide with the first.
            $setClauses[] = 'url=NULL';
            $setClauses[] = 'domainName=NULL';
        }

        // Compare post-validation: "foo.com" normalizes to the stored
        // "https://foo.com/", and an unchanged URL keeps its monitoring history.
        // (Loose != so a NULL original equals an empty string.)
        if($this->url == $originalURL){
            return;
        }

        if($this->url === '' && !empty($originalURL)){
            /*--
            The URL was cleared, not replaced — almost always because the
            monitoring cron found it dead, parked or hijacked. Keep the verdict
            and the timestamps: urlStatus says what went wrong, urlLastOkAt says
            when the domain last served this brewery's own site, and
            urlLastKnown holds the address itself. Wiping them would leave the
            record reading "no website, never checked", which is the opposite of
            what we just learned, and would send the next person searching for a
            brewery that has probably closed.
            --*/
            $setClauses[] = 'urlLastKnown=?';
            $setParams[] = $originalURL;
        }else{
            // Replaced with a different URL. The monitoring columns describe the
            // old address, so reset to baseline; urlCheckedAt=NULL puts the
            // brewer at the front of the check-urls queue.
            $setClauses[] = "urlStatus='unverified'";
            $setClauses[] = 'urlCheckedAt=NULL';
            $setClauses[] = 'urlLastOkAt=NULL';
            $setClauses[] = 'urlFailCount=0';
            $setClauses[] = 'urlFinal=NULL';
            $setClauses[] = 'urlLastKnown=NULL';
        }
    }

    /*--
    Append-only record of every change to a brewer's URL, so a future reader can
    tell "this brewery has no website" from "this brewery's domain lapsed in 2019
    and now redirects to a casino, don't go looking". Never blocks the write it
    describes: a failure here is logged and swallowed.

    $verdict is the urlStatus the change reacted to; $source is 'api', 'cron' or
    'cleanup'; $changedBy is the acting userID, or null for the cron.
    --*/
    public static function logURLChange($brewerID, $oldURL, $newURL, $verdict, $source, $note = null, $changedBy = null){
        if(empty($brewerID)){
            return;
        }

        $db = new Database();
        if($db->error){
            return;
        }

        $db->query("INSERT INTO brewer_url_history (brewerID, oldURL, newURL, verdict, source, note, changedBy, changedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            $brewerID,
            !empty($oldURL) ? $oldURL : null,
            !empty($newURL) ? $newURL : null,
            !empty($verdict) ? $verdict : null,
            $source,
            !empty($note) ? self::truncateNote($note) : null,
            !empty($changedBy) ? $changedBy : null,
            time()
        ]);

        if($db->error){
            // Database::query() already logged the SQL detail. Note the context
            // here so a missing history row is traceable to the brewer.
            $errorLog = new LogError();
            $errorLog->errorNumber = 288;
            $errorLog->errorMsg = 'Failed to record brewer URL history';
            $errorLog->badData = "Brewer: $brewerID / Old: $oldURL / New: $newURL";
            $errorLog->filename = 'API / Brewer.class.php';
            $errorLog->write();
        }

        $db->close();
    }

    public function validateURL($url, $type, $class){
        $returnURL = '';

        $url = trim($url ?? '');
        if(!empty($url)){
            // Add HTTP if no scheme
            if(!preg_match('/^https?:\/\//', $url)){
                $url = 'http://' . $url;
            }

            // Check URL syntax
            if(!filter_var($url, FILTER_VALIDATE_URL)){
                $this->error = true;
                $this->validState[$type] = 'invalid';
                $this->validMsg[$type] = 'Sorry, something seems to be wrong with your URL. Please check it and try again.';
                $this->responseCode = 400;

                $errorLog = new LogError();
                $errorLog->errorNumber = 13;
                $errorLog->errorMsg = 'Invalid URL';
                $errorLog->badData = $url;
                $errorLog->filename = $this->filename;
                $errorLog->write();

                return $returnURL;
            }

            // Reachability is UrlCheck's job — same probe the check-urls cron
            // uses, so a URL accepted here isn't flagged dead by the cron the
            // next morning, or vice versa.
            $urlCheck = new UrlCheck();
            $probe = $urlCheck->reachable($url);

            $httpCode = $probe['http_code'];

            if(!$urlCheck->answered($probe)){
                // Unreachable URL
                $this->error = true;
                $this->validState[$type] = 'invalid';
                $this->validMsg[$type] = 'Sorry, something seems to be wrong with your URL. Please check it and try again.';
                $returnURL = '';
                $this->responseCode = 400;

                $errorLog = new LogError();
                if($probe['errno']){
                    $errorLog->errorNumber = 16;
                    $errorLog->errorMsg = 'cURL Error';
                    $errorLog->badData = "URL: $url / cURL Error: " . $probe['error'];
                }else{
                    $errorLog->errorNumber = 107;
                    $errorLog->errorMsg = 'Invalid URL / Failed cURL';
                    $errorLog->badData = 'URL: ' . $url . ' / HTTP Response Code: ' . $httpCode;
                }
                $errorLog->filename = $this->filename;
                $errorLog->write();

                return $returnURL;
            }

            if($urlCheck->serving($probe) && !empty($probe['final_url'])){
                // Where the redirects landed, filtered: adopt only provable
                // canonicalisation (https, www, trailing slash). An age gate
                // or cookie wall answers 200 from its own path — storing
                // that path would bake our cookie jar's state into the
                // record, unfixably, since re-validating a corrective PATCH
                // follows the same redirect.
                $returnURL = $urlCheck->adoptFinalUrl($url, $probe['final_url']);
            }else{
                // The server answered but didn't serve us the site — a WAF
                // block, or an outage. Store what was submitted: a challenge
                // page or error page's redirect target is not the brewery.
                // check-urls will re-test it on its own schedule.
                $returnURL = $url;
            }

            // If still HTTP, try HTTPS upgrade
            if(preg_match('/^http:\/\//', $returnURL)){
                $secureURL = preg_replace('/^http:\/\//', 'https://', $returnURL);
                if($urlCheck->serving($urlCheck->reachable($secureURL))){
                    $returnURL = $secureURL;
                }
            }

            $this->validState[$type] = 'valid';

            // Check Length
            if(strlen($returnURL) > 255){
                $this->error = true;
                $this->validState[$type] = 'invalid';
                $this->validMsg[$type] = 'Sorry, but URL strings are limited to 255 bytes in length. Any chance there is a shorter URL you can use?';
                $this->responseCode = 400;
                $returnURL = '';

                $errorLog = new LogError();
                $errorLog->errorNumber = 147;
                $errorLog->errorMsg = 'URL Too Long';
                $errorLog->badData = $url;
                $errorLog->filename = $this->filename;
                $errorLog->write();
            }
        }

        // Domain name check for brewers
        if($type == 'url' && $class == 'brewer'){
            // An empty URL clears the domain with it. domainName is what grants
            // brewery staff editing rights by email domain, so leaving the old
            // host behind on a cleared URL would hand those rights to whoever
            // registers the domain next.
            $this->domainName = !empty($returnURL) ? $this->urlDomainName($returnURL) : '';
        }

        return $returnURL;
    }

    private function urlDomainName($url){
        // Get Domain name from URL
        $urlDomainName = '';

        // trim
        $url = trim($url ?? '');

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
                $result = $db->query("SELECT id FROM brewer WHERE domainName=?", [$urlDomainName]);
                if(!$db->error && $result->num_rows == 1){
                    // Get brewerID
                    $row = $result->fetch_assoc();
                    $brewerID = $row['id'];

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
                        $errorLog->filename = $this->filename;
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
                $errorLog->filename = $this->filename;
                $errorLog->write();
            }
        }

        return $urlDomainName;
    }


    // Validate Brewer
    public function validate($brewerID, $saveToClass){
        // Valid?
        $valid = false;

        // Trim
        $brewerID = trim($brewerID ?? '');

        if(!empty($brewerID)){
            // Prep for Database
            $db = new Database();
            $result = $db->query("SELECT name, description, shortDescription, url, domainName, cbVerified, brewerVerified, urlStatus, lastModified FROM brewer WHERE id=?", [$brewerID]);
            if(!$db->error){
                if($result->num_rows == 1){
                    // Valid
                    $valid = true;

                    if($saveToClass){
                        // Get Result Array
                        $array = $result->fetch_assoc();

                        // Save to Class
                        $this->brewerID = $brewerID;
                        $this->name = $array['name'];
                        if(is_null($array['description'])){
                            $this->description = null;
                        }else{
                            $this->description = $array['description'];
                        }
                        if(is_null($array['shortDescription'])){
                            $this->shortDescription = null;
                        }else{
                            $this->shortDescription = $array['shortDescription'];
                        }
                        $this->url = $array['url'];
                        $this->domainName = $array['domainName'];
                        $this->urlStatus = $array['urlStatus'];
                        $this->lastModified = intval($array['lastModified']);

                        if($array['cbVerified']){
                            $this->cbVerified = true;
                        }if($array['brewerVerified']){
                            $this->brewerVerified = true;
                        }
                    }
                }elseif($result->num_rows > 1){
                    // Unexpected number of results
                    $this->error = true;
                    $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
                    $this->responseCode = 500;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 19;
                    $errorLog->errorMsg = 'Unexpected number of results';
                    $errorLog->badData = "brewerID: $brewerID";
                    $errorLog->filename = $this->filename;
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
            $errorLog->filename = $this->filename;
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
                $this->totalCount = $numBrewers;
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
                    $errorLog->filename = $this->filename;
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
                    $errorLog->filename = $this->filename;
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
                $errorLog->filename = $this->filename;
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
            $errorLog->filename = $this->filename;
            $errorLog->write();
        }

        return(array($offset, $count));
    }

    // Get BrewerIDs
    //
    // $enriched adds a beer_count to each row. Only ever passed true for a master
    // API key (see api()), so the public GET /brewer contract stays
    // id/name/last_modified; the website's A-Z brewer index uses the enriched
    // shape to show each brewer's beer count without a per-row fetch.
    public function getBrewers($cursor, $count, $enriched = false){
        // Return Array
        $brewerArray = array();

        // Validate $cursor and $count
        $cursorCountArray = $this->validateCursorCount($cursor, $count);
        $offset = $cursorCountArray[0];
        $count = $cursorCountArray[1];

        if(!$this->error){
            // Prep for Database
            $db = new Database();
            if($enriched){
                // Master-key only: per-brewer beer count for the website's brewer
                // index. Correlated subquery so the COUNT runs only for the page's
                // rows (LIMIT bounds the outer scan), not every brewer.
                $result = $db->query("SELECT b.id, b.name, (SELECT COUNT(*) FROM beer WHERE beer.brewerID = b.id) AS beer_count FROM brewer b ORDER BY b.name LIMIT ?, ?", [$offset, $count]);
                if(!$db->error){
                    while($array = $result->fetch_assoc()){
                        $brewerInfo = array('id'=>$array['id'], 'name'=>$array['name'], 'beer_count'=>intval($array['beer_count']));
                        $brewerArray[] = $brewerInfo;
                    }
                }else{
                    // Query Error
                    $this->error = true;
                    $this->errorMsg = $db->errorMsg;
                    $this->responseCode = $db->responseCode;
                }
            }else{
                $result = $db->query("SELECT id, name, lastModified FROM brewer ORDER BY name LIMIT ?, ?", [$offset, $count]);
                if(!$db->error){
                    while($array = $result->fetch_assoc()){
                        $brewerInfo = array('id'=>$array['id'], 'name'=>$array['name'], 'last_modified'=>intval($array['lastModified']));
                        $brewerArray[] = $brewerInfo;
                    }
                }else{
                    // Query Error
                    $this->error = true;
                    $this->errorMsg = $db->errorMsg;
                    $this->responseCode = $db->responseCode;
                }
            }
            $db->close();
        }

        // Return
        return $brewerArray;
    }

    public function nextCursor($cursor, $count){
        // Use cached count from validateCursorCount() called by getBrewers()
        $numBrewers = ($this->totalCount > 0) ? $this->totalCount : $this->countBrewers();

        // Next Cursor
        $offset = intval(base64_decode($cursor));
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
        $result = $db->query("SELECT COUNT(*) AS numBrewers FROM brewer");
        if(!$db->error){
            $array = $result->fetch_assoc();
            $count = intval($array['numBrewers']);
        }else{
            // Query Error
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = $db->responseCode;
        }
        $db->close();

        return $count;
    }

    public function permissions($brewerID, $userID){
        /*---
        GET https://api.catalog.beer/brewer/{brewer_id}/permissions
        What may the requesting key's user do with this brewer — and, by
        extension, its beers and locations? Computed per-request for the
        authenticated key; responses must never be cached across keys.

        edit/delete are the verdicts for the brewer itself. For the subtree:
        admin/staff may edit and delete everything under the brewer; general
        users may edit only unverified entities and may never delete.
        ---*/
        if($this->validate($brewerID, true)){
            $users = new Users();
            if($users->validate($userID, true)){
                // Read-only staff check — no privilege grant on domain match
                $privileges = new Privileges();
                $isBreweryStaff = $privileges->isBreweryStaff($users, $this);

                if($users->admin){
                    $role = 'admin';
                }elseif($isBreweryStaff){
                    $role = 'staff';
                }else{
                    $role = 'general';
                }
                $canManage = ($users->admin || $isBreweryStaff);

                $this->json['object'] = 'permissions';
                $this->json['brewer_id'] = $this->brewerID;
                $this->json['role'] = $role;
                $this->json['edit'] = ($canManage || (!$this->cbVerified && !$this->brewerVerified));
                $this->json['delete'] = $canManage;
            }else{
                // User Validation Error
                $this->error = true;
                $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
                $this->responseCode = 500;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 298;
                $errorLog->errorMsg = 'Invalid userID for API key, GET /brewer/{id}/permissions';
                $errorLog->badData = "User: $userID / Brewer: $brewerID";
                $errorLog->filename = $this->filename;
                $errorLog->write();
            }
        }
    }

    public function delete($brewerID, $userID){
        if($this->validate($brewerID, true)){
            // Get User Information
            $users = new Users();
            $users->validate($userID, true);

            // Check Permissions (grant-on-domain-match keeps verification sticky)
            $privileges = new Privileges();
            $isBreweryStaff = $privileges->isBreweryStaff($users, $this, true);

            if($users->admin || $isBreweryStaff){
                // Look up Algolia IDs before deleting — the MySQL delete
                // cascades to beers, locations, AND their algolia-table rows,
                // so this mapping is unreadable afterward. (Skipping this was
                // the old orphan bug: the brewer's own record was removed but
                // its children's search records lingered as hits that 404.)
                $algolia = new Algolia();
                $algoliaId = $algolia->getAlgoliaIdByRecord('brewer', $brewerID);
                $childAlgoliaIds = $this->childAlgoliaIds();

                // Delete Brewer (cascades to beers/locations in MySQL)
                $db = new Database();
                $db->query("DELETE FROM brewer WHERE id=?", [$brewerID]);
                if(!$db->error){
                    // Delete from Algolia: the brewer's record, then every
                    // child record in one batched call. No local algolia-table
                    // cleanup needed — the FK cascade already removed the rows.
                    if($algoliaId !== null){
                        $algolia->deleteObject('catalog', $algoliaId);
                    }
                    $algolia->batchDelete('catalog', $childAlgoliaIds);
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
                $this->errorMsg = 'Sorry, you do not have permission to delete this brewery.';
                $this->responseCode = 403;

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 163;
                $errorLog->errorMsg = 'Forbidden: Non-Admin, DELETE, /brewer';
                $errorLog->badData = "User: $userID / Brewer: $this->brewerID";
                $errorLog->filename = $this->filename;
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
        $array['last_modified'] = $this->lastModified;

        if($json){
            // Add to JSON Output
            $this->json = $array;
        }else{
            // Return as array
            return $array;
        }
    }

    public function generateBrewerSearchObject(){
        // Generates the Brewer Object for Algolia

        // Setup Return Array
        $array = array();

        // Get Algolia ID
        $algolia = new Algolia();
        $array['objectID'] = $algolia->getAlgoliaIdByRecord('brewer', $this->brewerID);

        // Known Values - Required
        $array['brewerID'] = $this->brewerID;
        $array['name'] = $this->name;

        // Optional Values that may be stored as null
        if(!empty($this->description)){$array['description'] = $this->description;}
        if(!empty($this->shortDescription)){$array['short_description'] = $this->shortDescription;}
        if(!empty($this->url)){$array['url'] = $this->url;}

        // Location Denormalization — a brewer row carries no geography of its
        // own, so everything geographic here is borrowed from its locations.
        $locations = $this->locationFacets();
        $array['location_count'] = $locations['count'];
        if(!empty($locations['geoloc'])){$array['_geoloc'] = $locations['geoloc'];}
        if(!empty($locations['states'])){$array['states'] = $locations['states'];}
        if(!empty($locations['cities'])){$array['cities'] = $locations['cities'];}
        if(!empty($locations['countries'])){$array['countries'] = $locations['countries'];}

        // Beer count — lets a search result row read "214 beers · 3 locations".
        // Kept fresh by the refreshSearchObject() calls on beer create/delete.
        // Omitted (not zeroed) on a query error so a transient failure can't
        // misrepresent a stocked brewer as empty.
        $db = new Database();
        $result = $db->query("SELECT COUNT(*) AS beer_count FROM beer WHERE brewerID=?", [$this->brewerID]);
        if(!$db->error && ($row = $result->fetch_assoc()) !== null){
            $array['beer_count'] = intval($row['beer_count']);
        }elseif($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 283;
            $errorLog->errorMsg = 'Failed to count beers for brewer search object.';
            $errorLog->badData = "brewerID: {$this->brewerID} / DB Error: {$db->errorMsg}";
            $errorLog->filename = $this->filename;
            $errorLog->write();
        }
        $db->close();

        // SiteSearch Fields
        $array['type'] = 'brewer';
        // Cross-type tie-break for customRanking (see algolia/settings.php):
        // when textual relevance ties, the brand itself outranks records that
        // merely carry its name (a beer named "Ballast Point …").
        $array['type_rank'] = 40;
        $array['page_url'] = '/brewer/' . $this->brewerID;

        // Subtitle — parallels how beers and locations use it for parent
        // context: geography, and only geography. It used to fall back to the
        // short description, which put the same sentence on a search row twice
        // (the row renders subtitle as context AND short_description as its
        // snippet). Omitted entirely for a brewer with no located taproom; the
        // blurb is still searchable and still snippeted on its own.
        if(!empty($locations['primary'])){
            $array['subtitle'] = $locations['primary'];
        }

        // Return as array
        return $array;
    }

    /*
    Re-sync a brewer's search object after one of its children changed.

    The reverse of cascadeNameToChildren(): because brewer records denormalize
    their locations' geography (_geoloc / states / cities / location_count), any
    location or address write invalidates the parent. Callers in
    Location.class.php and USAddresses.class.php use this instead of rebuilding
    a Brewer by hand.

    Runs on its own instance so validate() failures can't leak error state into
    the caller's response, and stays silent on a bad ID — the child write has
    already succeeded by this point and must not be failed by a sync problem.

    $cascadeGeography: beers borrow their brewer's states/cities/countries, so
    a location or address write must also re-patch every beer of the brewer —
    pass true from those paths. Beer create/delete calls leave it false: only
    beer_count changed, and patching N sibling beers on every beer write would
    make adding one beer cost a batch proportional to the brewer's catalog.
    */
    public static function refreshSearchObject($brewerID, $cascadeGeography = false){
        if(empty($brewerID)){
            return;
        }

        $brewer = new Brewer();
        if($brewer->validate($brewerID, true)){
            $algolia = new Algolia();
            $algolia->saveObject('catalog', $brewer->generateBrewerSearchObject());

            if($cascadeGeography){
                $brewer->cascadeGeographyToBeers();
            }
        }
    }

    /*
    Expose the brewer's geography for denormalization onto its beers.

    Static cache keyed by brewerID because generateBeerSearchObject() calls
    this once per beer, and a full batch re-index would otherwise run the
    locations query 60k+ times for ~6.5k distinct brewers. Safe to cache for
    the process lifetime: within a single API request only one entity is
    written, so a beer's search object is never generated after a location
    write that would invalidate the cache. (The location-write path itself
    bypasses this cache — cascadeGeographyToBeers() reads locationFacets()
    directly.)
    */
    public function searchGeography(){
        static $cache = array();

        if(!empty($this->brewerID) && isset($cache[$this->brewerID])){
            return $cache[$this->brewerID];
        }

        $facets = $this->locationFacets();
        $geo = array(
            'states'    => $facets['states'],
            'cities'    => $facets['cities'],
            'countries' => $facets['countries']
        );

        if(!empty($this->brewerID)){
            $cache[$this->brewerID] = $geo;
        }
        return $geo;
    }

    /*
    Push the brewer's current geography onto every one of its beers in Algolia.

    The geographic mirror of cascadeNameToChildren(): beers carry their
    brewer's states/cities/countries so that a geography refinement doesn't
    silently drop every beer from the results — and those copies go stale the
    moment a location or address changes. Called from refreshSearchObject()
    when the trigger was a location/address write.

    Always sends all three keys, even when empty — an empty array must
    propagate so that closing a brewer's last Oregon taproom removes its beers
    from the Oregon facet. One batched call rather than N PUTs.
    */
    private function cascadeGeographyToBeers(){
        // Required Classes
        $algolia = new Algolia();
        $db = new Database();
        $updates = array();

        // Fresh read, deliberately not searchGeography()'s cache — this runs
        // immediately after the location write the cache would predate.
        $facets = $this->locationFacets();
        $patch = array(
            'states'    => $facets['states'],
            'cities'    => $facets['cities'],
            'countries' => $facets['countries']
        );

        // Join through the algolia table so objectIDs come back with the rows,
        // instead of one getAlgoliaIdByRecord() round-trip per beer.
        $result = $db->query("SELECT a.algolia_id FROM beer b JOIN algolia a ON a.beer_id = b.id WHERE b.brewerID=?", [$this->brewerID]);
        if($db->error){
            // Query Error — log and skip; the next full re-index heals it
            $errorLog = new LogError();
            $errorLog->errorNumber = 284;
            $errorLog->errorMsg = 'Failed to collect beer records for brewer geography cascade.';
            $errorLog->badData = "brewerID: {$this->brewerID} / DB Error: {$db->errorMsg}";
            $errorLog->filename = $this->filename;
            $errorLog->write();
            $db->close();
            return;
        }

        while($row = $result->fetch_assoc()){
            $updates[] = array_merge(array('objectID'=>$row['algolia_id']), $patch);
        }
        $db->close();

        $algolia->batchPartialUpdate('catalog', $updates);
    }

    /*
    Push this brewer's current name onto every one of its beers and locations
    in Algolia.

    Those records carry the brewer name denormalized twice — as brewer.name and
    as subtitle — because Algolia has no joins. A rename leaves both stale. That
    was cosmetic while the name was only display text; once brewer.name is
    facetable, a stale copy silently splits one brewer into two facet buckets.

    Callers must guard on an actual name change. One batched call rather than N
    PUTs: a large brewer has hundreds of beers and this runs inside the request.
    */
    private function cascadeNameToChildren(){
        // Required Classes
        $algolia = new Algolia();
        $db = new Database();
        $updates = array();

        // The denormalized payload is identical for beers and locations
        $patch = array(
            'subtitle' => $this->name,
            // A partial update replaces a nested attribute wholesale rather than
            // merging into it, so brewerID must be resent or it gets dropped.
            'brewer'   => array('brewerID'=>$this->brewerID, 'name'=>$this->name)
        );

        // Join through the algolia table so objectIDs come back with the rows,
        // instead of one getAlgoliaIdByRecord() round-trip per child.
        $children = array(
            'beer'     => "SELECT a.algolia_id FROM beer b JOIN algolia a ON a.beer_id = b.id WHERE b.brewerID=?",
            'location' => "SELECT a.algolia_id FROM location l JOIN algolia a ON a.location_id = l.id WHERE l.brewerID=?"
        );

        foreach($children as $type => $sql){
            $result = $db->query($sql, [$this->brewerID]);
            if($db->error){
                // Query Error — log and skip this child type
                $errorLog = new LogError();
                $errorLog->errorNumber = 279;
                $errorLog->errorMsg = 'Failed to collect ' . $type . ' records for brewer rename cascade.';
                $errorLog->badData = "brewerID: {$this->brewerID} / DB Error: {$db->errorMsg}";
                $errorLog->filename = $this->filename;
                $errorLog->write();

                // Reset so the next child type still runs
                $db->error = false;
                $db->errorMsg = null;
                $db->responseCode = 200;
                continue;
            }

            while($row = $result->fetch_assoc()){
                $updates[] = array_merge(array('objectID'=>$row['algolia_id']), $patch);
            }
        }
        $db->close();

        $algolia->batchPartialUpdate('catalog', $updates);
    }

    /*
    Collect the Algolia objectIDs of every beer and location under this brewer.

    Used by delete(): MySQL cascades a brewer delete to its children and to
    their algolia-table rows, so the IDs must be captured BEFORE the delete or
    the index-side records become unreachable orphans. Failures degrade to
    whatever was collected (logged) — a sync problem must not block the delete.
    */
    private function childAlgoliaIds(){
        $db = new Database();
        $ids = array();

        // Join through the algolia table so objectIDs come back with the rows,
        // instead of one getAlgoliaIdByRecord() round-trip per child.
        $children = array(
            'beer'     => "SELECT a.algolia_id FROM beer b JOIN algolia a ON a.beer_id = b.id WHERE b.brewerID=?",
            'location' => "SELECT a.algolia_id FROM location l JOIN algolia a ON a.location_id = l.id WHERE l.brewerID=?"
        );

        foreach($children as $type => $sql){
            $result = $db->query($sql, [$this->brewerID]);
            if($db->error){
                // Query Error — log and skip this child type
                $errorLog = new LogError();
                $errorLog->errorNumber = 294;
                $errorLog->errorMsg = 'Failed to collect ' . $type . ' Algolia IDs for brewer delete.';
                $errorLog->badData = "brewerID: {$this->brewerID} / DB Error: {$db->errorMsg}";
                $errorLog->filename = $this->filename;
                $errorLog->write();

                // Reset so the next child type still runs
                $db->error = false;
                $db->errorMsg = null;
                $db->responseCode = 200;
                continue;
            }

            while($row = $result->fetch_assoc()){
                $ids[] = $row['algolia_id'];
            }
        }
        $db->close();

        return $ids;
    }

    /*
    Collect the brewer's location data for the search index.

    Algolia has no joins, so geographic search over brewers only works if the
    children are folded into the parent. _geoloc accepts an ARRAY of positions
    and ranks against the closest one — exactly the multi-taproom case.

    Any location or address write must re-save the parent brewer or these values
    go stale; see the cascades in Location.class.php and USAddresses.class.php.
    */
    private function locationFacets(){
        $facets = array('geoloc'=>array(), 'states'=>array(), 'cities'=>array(), 'countries'=>array(), 'count'=>0, 'primary'=>null);

        // A brewer being saved for the first time has no locations yet
        if(empty($this->brewerID)){
            return $facets;
        }

        $db = new Database();
        $result = $db->query("SELECT l.latitude, l.longitude, l.countryCode, a.city, a.sub_code FROM location l LEFT JOIN US_addresses a ON a.locationID = l.id WHERE l.brewerID=? ORDER BY l.name", [$this->brewerID]);

        if($db->error){
            // Query Error — degrade to no geo rather than failing the sync
            $errorLog = new LogError();
            $errorLog->errorNumber = 278;
            $errorLog->errorMsg = 'Failed to collect location facets for brewer search object.';
            $errorLog->badData = "brewerID: {$this->brewerID} / DB Error: {$db->errorMsg}";
            $errorLog->filename = $this->filename;
            $errorLog->write();
            $db->close();
            return $facets;
        }

        while($row = $result->fetch_assoc()){
            $facets['count']++;

            // Coordinates are optional per location
            if($row['latitude'] !== null && $row['longitude'] !== null){
                $facets['geoloc'][] = array('lat'=>floatval($row['latitude']), 'lng'=>floatval($row['longitude']));
            }

            // Country
            if(!empty($row['countryCode'])){
                $facets['countries'][] = $row['countryCode'];
            }

            // State — sub_code is 'US-OR'; same substring convention as brewerLocations()
            $stateShort = !empty($row['sub_code']) ? substr($row['sub_code'], 3, 2) : null;
            if(!empty($stateShort)){
                $facets['states'][] = $stateShort;
            }

            // City
            if(!empty($row['city'])){
                $facets['cities'][] = $row['city'];
            }

            // Primary — first location by name with a usable "City, ST"
            if($facets['primary'] === null && !empty($row['city']) && !empty($stateShort)){
                $facets['primary'] = $row['city'] . ', ' . $stateShort;
            }
        }
        $db->close();

        // Dedupe — a brewer with three taprooms in one city must yield one facet
        // value. array_values() re-indexes so these encode as JSON arrays;
        // array_unique() alone leaves gaps that json_encode turns into objects.
        $facets['states'] = array_values(array_unique($facets['states']));
        $facets['cities'] = array_values(array_unique($facets['cities']));
        $facets['countries'] = array_values(array_unique($facets['countries']));

        return $facets;
    }

    public function search($query, $cursor, $count){
        // Validate query
        $query = trim($query ?? '');
        if(empty($query)){
            // Missing Query
            $this->error = true;
            $this->errorMsg = "Missing search query. Include a 'q' parameter with your search terms.";
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 234;
            $errorLog->errorMsg = 'Missing search query';
            $errorLog->badData = '';
            $errorLog->filename = $this->filename;
            $errorLog->write();
            return;
        }

        if(!SearchQuery::validUtf8($query)){
            // Malformed UTF-8 — see SearchQuery::validUtf8(). Caught here so it
            // answers 400 instead of reaching json_encode() and returning a 200
            // that carries index.php's generic "encoding error" body.
            $this->error = true;
            $this->errorMsg = 'Search query is not valid UTF-8. Percent-encode non-ASCII characters as UTF-8 (Ü is %C3%9C, not %DC).';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 299;
            $errorLog->errorMsg = 'Search query is not valid UTF-8';
            $errorLog->badData = bin2hex(substr($query, 0, 64));
            $errorLog->filename = $this->filename;
            $errorLog->write();
            return;
        }

        if(strlen($query) > 255){
            // Query Too Long
            $this->error = true;
            $this->errorMsg = 'Search query is too long. Please limit your query to 255 characters.';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 235;
            $errorLog->errorMsg = 'Search query too long';
            $errorLog->badData = strlen($query) . ' characters';
            $errorLog->filename = $this->filename;
            $errorLog->write();
            return;
        }

        // Validate count
        $count = intval($count);
        if($count < 1 || $count > 100){
            $this->error = true;
            $this->errorMsg = 'The count value must be between 1 and 100.';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 236;
            $errorLog->errorMsg = 'Invalid count for search';
            $errorLog->badData = $count;
            $errorLog->filename = $this->filename;
            $errorLog->write();
            return;
        }

        // Validate cursor
        $offset = intval(base64_decode($cursor));
        if($offset < 0){
            $offset = 0;
        }

        // Request count+1 to determine if there are more results
        $fetchCount = $count + 1;

        // Sanitised FULLTEXT query strings (see SearchQuery.class.php)
        $searchTerms = SearchQuery::terms($query);
        // Distinctive terms drive the match label only, never the ranking —
        // see SearchQuery::brewerDistinctiveTerms()
        $distinctiveTerms = SearchQuery::brewerDistinctiveTerms($query);

        // Tiered ranking; same scheme as Beer::search() — exact name, then
        // all-terms-in-name (prefix match), then the natural-language match,
        // with name relevance ranked above blended relevance within a tier.
        $db = new Database();
        $result = $db->query("SELECT id, name, description, shortDescription, url, cbVerified, brewerVerified, lastModified, CASE WHEN LOWER(name) = LOWER(?) THEN 0 WHEN MATCH(name) AGAINST(? IN BOOLEAN MODE) > 0 THEN 1 ELSE 2 END AS tier, MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE) AS name_rel, MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE) AS name_rel_distinctive, MATCH(name, description, shortDescription) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance FROM brewer WHERE MATCH(name, description, shortDescription) AGAINST(? IN NATURAL LANGUAGE MODE) OR MATCH(name) AGAINST(? IN BOOLEAN MODE) OR LOWER(name) = LOWER(?) ORDER BY tier, name_rel DESC, relevance DESC, name, id LIMIT ?, ?", [$query, $searchTerms['bool'], $searchTerms['nl'], $distinctiveTerms, $searchTerms['nl'], $searchTerms['nl'], $searchTerms['bool'], $query, $offset, $fetchCount]);
        if(!$db->error){
            $rowCount = 0;
            $data = array();
            while($row = $result->fetch_assoc()){
                $rowCount++;
                if($rowCount > $count){
                    // Extra row — indicates more results exist
                    break;
                }

                // Build brewer object
                $brewerObj = array();
                $brewerObj['id'] = $row['id'];
                $brewerObj['object'] = 'brewer';
                $brewerObj['name'] = $row['name'];
                $brewerObj['description'] = $row['description'] ?? null;
                $brewerObj['short_description'] = $row['shortDescription'] ?? null;
                $brewerObj['url'] = $row['url'] ?? null;
                $brewerObj['cb_verified'] = $row['cbVerified'] ? true : false;
                $brewerObj['brewer_verified'] = $row['brewerVerified'] ? true : false;
                $brewerObj['last_modified'] = intval($row['lastModified']);
                // Why this row matched — see SearchQuery::matchQuality() (I6).
                // Judged on the distinctive relevance: "Brewing" matching the
                // name is not name evidence.
                $brewerObj['match'] = SearchQuery::matchQuality($row['tier'], $row['name_rel_distinctive']);

                $data[] = $brewerObj;
            }

            // Build response
            $hasMore = ($rowCount > $count);
            $this->json['object'] = 'list';
            $this->json['url'] = '/brewer/search';
            $this->json['query'] = $query;
            $this->json['has_more'] = $hasMore;
            if($hasMore){
                $this->json['next_cursor'] = base64_encode($offset + $count);
            }
            $this->json['data'] = $data;
        }else{
            // Query Error
            $this->error = true;
            $this->errorMsg = 'Sorry, we encountered an error while processing your search.';
            $this->responseCode = 500;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 238;
            $errorLog->errorMsg = 'Brewer FULLTEXT query error';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = $this->filename;
            $errorLog->write();
        }
        $db->close();
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
        GET https://api.catalog.beer/brewer/{brewer_id}/permissions

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
                            case 'search':
                                // GET https://api.catalog.beer/brewer/search?q=
                                $searchQuery = isset($_GET['q']) ? $_GET['q'] : '';
                                $searchCount = isset($_GET['count']) ? $_GET['count'] : 25;
                                $searchCursor = isset($_GET['cursor']) ? $_GET['cursor'] : base64_encode('0');
                                $this->search($searchQuery, $searchCursor, $searchCount);
                                if($this->error){
                                    $this->json['error'] = true;
                                    $this->json['error_msg'] = $this->errorMsg;
                                }
                                break;
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
                            case 'permissions':
                                // GET https://api.catalog.beer/brewer/{brewer_id}/permissions
                                // Get userID for the requesting key
                                $apiKeys = new apiKeys();
                                $apiKeys->validate($apiKey, true);

                                $this->permissions($id, $apiKeys->userID);
                                if($this->error){
                                    $this->json['error'] = true;
                                    $this->json['error_msg'] = $this->errorMsg;
                                }
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
                                $errorLog->filename = $this->filename;
                                $errorLog->write();
                        }
                    }else{
                        // List Breweries
                        // GET https://api.catalog.beer/brewer
                        // Enriched rows (beer_count) are master-key only; a
                        // non-master caller passing ?enriched gets the standard
                        // shape (no error, no leak).
                        $enriched = false;
                        if(!empty($_GET['enriched']) && in_array($apiKey, unserialize(MASTER_API_KEYS))){
                            $enriched = true;
                        }
                        $brewerArray = $this->getBrewers($cursor, $count, $enriched);
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
                if(!empty($function)){
                    // Method Not Allowed for /brewer/{function}
                    $this->responseCode = 405;
                    $this->responseHeader = 'Allow: GET';
                    $this->json['error'] = true;
                    $this->json['error_msg'] = 'Method Not Allowed. Use GET for this endpoint.';

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 239;
                    $errorLog->errorMsg = 'Invalid Method for /brewer/' . $function;
                    $errorLog->badData = $method;
                    $errorLog->filename = 'API / Brewer.class.php';
                    $errorLog->write();
                    break;
                }

                // POST https://api.catalog.beer/brewer
                $apiKeys = new apiKeys();
                $apiKeys->validate($apiKey, true);

                // Handle Empty Fields
                if(empty($data->name)){$data->name = '';}
                if(empty($data->description)){$data->description = '';}
                if(empty($data->short_description)){$data->short_description = '';}
                if(empty($data->url)){$data->url = '';}
                if(empty($data->url_note)){$data->url_note = '';}

                // Add Brewer
                $this->add($data->name, $data->description, $data->short_description, $data->url, $apiKeys->userID, 'POST', '', array(), $data->url_note);
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
                if(empty($data->url_note)){$data->url_note = '';}

                // Update Brewer
                $this->add($data->name, $data->description, $data->short_description, $data->url, $apiKeys->userID, 'PUT', $id, array(), $data->url_note);
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
            case 'PATCH':
                // PATCH https://api.catalog.beer/brewer/{brewer_id}
                $apiKeys = new apiKeys();
                $apiKeys->validate($apiKey, true);

                // Which fields are we updating?
                $patchFields = array();

                /*--
                property_exists(), not isset(): isset() is false for an explicit
                null, so "clear this field" and "don't touch this field" arrived
                here as the same request. The field never joined $patchFields,
                nothing was written, and the 200 that came back looked like a
                successful clear.

                Required fields are listed the same way on purpose. Their
                validators below already reject an empty value with a 400, so an
                explicit null on name is answered "we need a name" instead of
                being silently discarded.
                --*/
                if(property_exists($data, 'name')){$patchFields[] = 'name';}
                else{$data->name = '';}

                if(property_exists($data, 'description')){$patchFields[] = 'description';}
                else{$data->description = '';}

                if(property_exists($data, 'short_description')){$patchFields[] = 'short_description';}
                else{$data->short_description = '';}

                if(property_exists($data, 'url')){$patchFields[] = 'url';}
                else{$data->url = '';}

                /*--
                url_note is write-only and admin-only: a short reason recorded
                against the URL change in brewer_url_history ("domain lapsed,
                now a casino"). It is not a brewer field, so it never joins
                $patchFields and never appears in the response.
                --*/
                if(empty($data->url_note)){$data->url_note = '';}

                // Update Brewer
                $this->add($data->name, $data->description, $data->short_description, $data->url, $apiKeys->userID, 'PATCH', $id, $patchFields, $data->url_note);
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
                $errorLog->filename = $this->filename;
                $errorLog->write();
        }
    }
}
?>
