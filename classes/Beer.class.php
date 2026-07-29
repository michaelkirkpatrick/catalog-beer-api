<?php
class Beer {

    // Properties
    public $beerID = '';
    public $brewerID = '';
    public $name = '';
    public $style = '';                 // Human-readable label — the brewer's own wording, stored verbatim
    public $styleID = null;             // Canonical style FK (style.id slug) — null if filed at family/class level
    public $parent = null;              // Family FK (style_parent.slug) — derived up, or filed directly
    public $class = null;               // Super-class FK (style_class.slug: ale|lager) — derived up, or filed directly
    public $beverageType = 'beer';      // Derived from the resolved row: beer|cider|perry|mead
    public $styleConfidence = null;     // Guided Style Field provenance (internal — stored, never returned): confident|override|family|catch-all|unresolved
    public $description = '';           // Optional
    public $abv = 0;
    public $ibu = 0;                    // Optional
    public $cbVerified = false;
    public $brewerVerified = false;
    public $lastModified = 0;

    // Error Handling
    public $error = false;
    public $errorMsg = null;
    public $validState = array('brewer_id'=>null, 'name'=>null, 'style'=>null, 'description'=>null, 'abv'=>null, 'ibu'=>null);
    public $validMsg = array('brewer_id'=>null, 'name'=>null, 'style'=>null, 'description'=>null, 'abv'=>null, 'ibu'=>null);
    // Machine-actionable recovery candidates for a rejected field, keyed by
    // field name like validState/validMsg. Emitted as `suggestions` alongside
    // them, and only when non-empty. Kept out of validMsg because that string
    // is rendered to people in the Guided Style Field — prose for humans there,
    // slugs for clients here.
    public $suggestions = array();

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    // Verification
    private $isBV = false;  // Is the brewery, brewerVerified?
    private $isCBV = false; // Is the brewery, catalog.beer verified (cbVerified)?

    // Cached objects to avoid redundant queries
    private $brewerObj = null;
    private $totalCount = 0;

    // How resolveStyle() reached the tier, so resolveConfidence() can tell an
    // auto-match apart from a mapping judgment. Set via setTier().
    //   'label'    — the submitted label matched the vocabulary on its own
    //   'explicit' — style_id/parent/class named the tier
    //   'carried'  — unchanged label on an existing beer kept its stored tier
    private $styleResolvedBy = null;
    // Only meaningful on the 'explicit' style_id path: did the submitted label
    // match that style's canonical name or one of its aliases anyway, and is
    // the chosen style a catch-all?
    private $styleLabelMatched = false;
    private $styleIsCatchAll = false;


    public function add($brewerID, $name, $style, $styleID, $parent, $class, $styleConfidence, $description, $abv, $ibu, $userID, $method, $beerID, $patchFields){

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
                // Snapshot the stored label + classification (loaded by
                // validate() when PUT targets an existing beer) so an
                // unchanged-but-unresolvable label keeps its classification
                // and an unchanged tier keeps its provenance.
                $prevLabel = $newBeer ? null : $this->style;
                $prevTier = array($this->styleID, $this->parent, $this->class, $this->beverageType);
                $prevConfidence = $this->styleConfidence;
                $this->style = $style;
                $this->description = $description;
                $this->abv = $abv;
                $this->ibu = $ibu;

                // Validate Fields
                $this->validateName();
                $this->resolveStyle($styleID, $parent, $class, $prevLabel, $prevTier);
                $this->resolveConfidence($styleConfidence, $prevConfidence, !$newBeer && array_slice($prevTier, 0, 3) === array($this->styleID, $this->parent, $this->class));
                $this->validateDescription();
                $this->validateABV();
                $this->validateIBU();

                if(!$this->error){
                    $this->lastModified = time();

                    // Construct SQL Statement
                    if($newBeer){
                        // Add Beer (POST/PUT)
                        $columns = ['id', 'brewerID', 'name', 'style', 'style_id', 'parent', 'class', 'beverage_type', 'style_confidence', 'abv', 'cbVerified', 'brewerVerified', 'lastModified'];
                        $params = [$this->beerID, $this->brewerID, $this->name, $this->style, $this->styleID, $this->parent, $this->class, $this->beverageType, $this->styleConfidence, $this->abv, $dbCBV, $dbBV, $this->lastModified];
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
                        // PUT is a full replacement — omitted fields are cleared
                        $setClauses = ['brewerID=?', 'name=?', 'style=?', 'style_id=?', 'parent=?', 'class=?', 'beverage_type=?', 'style_confidence=?', 'abv=?', 'cbVerified=?', 'brewerVerified=?', 'lastModified=?'];
                        $setParams = [$this->brewerID, $this->name, $this->style, $this->styleID, $this->parent, $this->class, $this->beverageType, $this->styleConfidence, $this->abv, $dbCBV, $dbBV, $this->lastModified];
                        if(!empty($this->description)){
                            $setClauses[] = 'description=?';
                            $setParams[] = $this->description;
                        }else{
                            $setClauses[] = 'description=NULL';
                        }
                        if(!empty($this->ibu)){
                            $setClauses[] = 'ibu=?';
                            $setParams[] = $this->ibu;
                        }else{
                            $setClauses[] = 'ibu=NULL';
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

                // Resolve Style (style / style_id / parent / class changed)
                if(in_array('style', $patchFields)){
                    // Re-resolve from the submitted label + tier pick. Any of style_id/
                    // parent/class can change, so always re-resolve when style is patched.
                    // Snapshot the stored label, tier + confidence first (loaded by
                    // validate() above) so an unchanged-but-unresolvable label keeps its
                    // classification and an unchanged tier keeps its provenance.
                    $prevLabel = $this->style;
                    $prevTier = array($this->styleID, $this->parent, $this->class, $this->beverageType);
                    $prevConfidence = $this->styleConfidence;
                    $this->style = $style;
                    $this->resolveStyle($styleID, $parent, $class, $prevLabel, $prevTier);
                    $this->resolveConfidence($styleConfidence, $prevConfidence, array_slice($prevTier, 0, 3) === array($this->styleID, $this->parent, $this->class));
                    if(!$this->error){
                        $setClauses[] = "style=?";        $setParams[] = $this->style;
                        $setClauses[] = "style_id=?";     $setParams[] = $this->styleID;
                        $setClauses[] = "parent=?";       $setParams[] = $this->parent;
                        $setClauses[] = "class=?";        $setParams[] = $this->class;
                        $setClauses[] = "beverage_type=?"; $setParams[] = $this->beverageType;
                        $setClauses[] = "style_confidence=?"; $setParams[] = $this->styleConfidence;
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

                        // Create Algolia ID and sync to Algolia
                        $algolia = new Algolia();
                        $algolia->add('beer', $this->beerID);
                        $algolia->saveObject('catalog', $this->generateBeerSearchObject());

                        // The brewer record's beer_count just changed
                        Brewer::refreshSearchObject($this->brewerID);
                    }else{
                        $this->responseCode = 200;

                        // Sync updated beer to Algolia
                        $algolia = new Algolia();
                        $algolia->saveObject('catalog', $this->generateBeerSearchObject());
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

    /*--
    Resolve the submitted classification to a style, family (parent), or
    super-class (Ale/Lager), preserving the brewer's raw label. A beer may be
    filed at any tier — encyclopedic brewers pick a style; generic brewers pick
    a family or class. Sets $this->style (label), $this->styleID, $this->parent,
    $this->class, $this->beverageType (all derived up the tree — never trusted
    from the client). Resolution:
      1. explicit style_id / parent / class (the typeahead's pick), most specific first
      2. raw label: exact style name -> class alias -> family alias -> style alias
      3. unchanged label on an existing beer -> keep the stored classification
         ($prevLabel / $prevTier: a GET->PUT/PATCH round-trip of a legacy beer
         whose label predates the vocabulary isn't a new style claim and must
         not 400 the update)
      4. unresolved -> 400 with a helpful, suggestion-oriented message
    Collation utf8mb4_0900_ai_ci makes name/alias matching case/accent-insensitive.
    --*/
    private function resolveStyle($styleID, $parent = '', $class = '', $prevLabel = null, $prevTier = null){
        // Trim
        $this->style = trim($this->style ?? '');
        $styleID = trim($styleID ?? '');
        $parent = trim($parent ?? '');
        $class = trim($class ?? '');

        // Label length guard (style is varchar(255))
        if(!empty($this->style) && strlen($this->style) > 255){
            $this->error = true;
            $this->validState['style'] = 'invalid';
            $this->validMsg['style'] = 'We hate to say it but this beer style is too long for our database. Style names are limited to 255 bytes. Any chance you can shorten it?';
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 16;
            $errorLog->errorMsg = 'Beer style name too long (>255 Characters)';
            $errorLog->badData = $this->style;
            $errorLog->filename = 'API / Beer.class.php';
            $errorLog->write();
            return;
        }

        $db = new Database();

        // 1. Explicit picks (most specific first: style_id > parent > class)
        if(!empty($styleID)){
            // label_match rides along on the same query: it's what separates a
            // caller spelling out a match the vocabulary would have made anyway
            // ("NEIPA" + hazy-ipa) from one asserting a mapping we never
            // could have ("Cali Pilsner" + contemporary-american-pilsner). Only
            // the second is an override. Case-insensitivity comes from the
            // utf8mb4_0900_ai_ci collation, as elsewhere in this method.
            $result = $db->query("SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.class, s.is_catch_all, (s.canonical_name = ? OR EXISTS(SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND x.alias = ?)) AS label_match FROM style s JOIN style_parent p ON s.parent=p.slug WHERE s.id=?", [$this->style, $this->style, $styleID]);
            if($db->error){ return $this->resolveDbError($db); }
            if($result !== null && $result->num_rows === 1){
                $row = $result->fetch_assoc();
                $this->styleIsCatchAll = (bool) $row['is_catch_all'];
                // An empty label gets filled from canonical_name below — that's
                // the caller accepting our name, not overriding it.
                $this->styleLabelMatched = empty($this->style) || (bool) $row['label_match'];
                if(empty($this->style)){ $this->style = $row['canonical_name']; }
                $this->setTier($row['id'], $row['parent'], $row['class'], $row['beverage_type'], 'explicit');
                $db->close();
                return;
            }
            return $this->resolveBadId($db, 'style_id', $styleID);
        }
        if(!empty($parent)){
            $result = $db->query("SELECT slug, beverage_type, class FROM style_parent WHERE slug=?", [$parent]);
            if($db->error){ return $this->resolveDbError($db); }
            if($result !== null && $result->num_rows === 1){
                $row = $result->fetch_assoc();
                $this->setTier(null, $row['slug'], $row['class'], $row['beverage_type'], 'explicit');
                $db->close();
                return;
            }
            return $this->resolveBadId($db, 'parent', $parent);
        }
        if(!empty($class)){
            $result = $db->query("SELECT slug, beverage_type FROM style_class WHERE slug=?", [$class]);
            if($db->error){ return $this->resolveDbError($db); }
            if($result !== null && $result->num_rows === 1){
                $row = $result->fetch_assoc();
                $this->setTier(null, null, $row['slug'], $row['beverage_type'], 'explicit');
                $db->close();
                return;
            }
            return $this->resolveBadId($db, 'class', $class);
        }

        // 2. No explicit pick: need a label to resolve
        if(empty($this->style)){
            $this->error = true;
            $this->validState['style'] = 'invalid';
            $this->validMsg['style'] = 'What\'s the style of this beer? We seem to be missing its style.';
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 17;
            $errorLog->errorMsg = 'Missing Beer Style';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Beer.class.php';
            $errorLog->write();
            $db->close();
            return;
        }

        // 2a. Exact style canonical name
        $result = $db->query("SELECT s.id, s.beverage_type, s.parent, p.class FROM style s JOIN style_parent p ON s.parent=p.slug WHERE s.canonical_name=?", [$this->style]);
        if($db->error){ return $this->resolveDbError($db); }
        if($result !== null && $result->num_rows >= 1){
            $row = $result->fetch_assoc();
            $this->setTier($row['id'], $row['parent'], $row['class'], $row['beverage_type'], 'label');
            $db->close();
            return;
        }

        // 2b. Class alias (Ale / Lager)
        $result = $db->query("SELECT c.slug, c.beverage_type FROM class_alias ca JOIN style_class c ON ca.class=c.slug WHERE ca.alias=?", [$this->style]);
        if($db->error){ return $this->resolveDbError($db); }
        if($result !== null && $result->num_rows >= 1){
            $row = $result->fetch_assoc();
            $this->setTier(null, null, $row['slug'], $row['beverage_type'], 'label');
            $db->close();
            return;
        }

        // 2c. Family (parent) alias
        $result = $db->query("SELECT p.slug, p.beverage_type, p.class FROM parent_alias pa JOIN style_parent p ON pa.parent=p.slug WHERE pa.alias=?", [$this->style]);
        if($db->error){ return $this->resolveDbError($db); }
        if($result !== null && $result->num_rows >= 1){
            $row = $result->fetch_assoc();
            $this->setTier(null, $row['slug'], $row['class'], $row['beverage_type'], 'label');
            $db->close();
            return;
        }

        // 2d. Style alias
        $result = $db->query("SELECT s.id, s.beverage_type, s.parent, p.class FROM style_alias sa JOIN style s ON sa.style_id=s.id JOIN style_parent p ON s.parent=p.slug WHERE sa.alias=?", [$this->style]);
        if($db->error){ return $this->resolveDbError($db); }
        if($result !== null && $result->num_rows >= 1){
            $row = $result->fetch_assoc();
            $this->setTier($row['id'], $row['parent'], $row['class'], $row['beverage_type'], 'label');
            $db->close();
            return;
        }
        $db->close();

        // 3. Unchanged label on an existing beer: keep its stored
        // classification rather than blocking the update. The caller isn't
        // making a new style claim — they're resending the beer's own data
        // (e.g. a GET->PUT round-trip of a legacy beer whose label predates
        // the vocabulary).
        if($prevLabel !== null && strcasecmp($this->style, trim($prevLabel)) === 0){
            $this->setTier($prevTier[0] ?? null, $prevTier[1] ?? null, $prevTier[2] ?? null, $prevTier[3] ?? 'beer', 'carried');
            return;
        }

        // 4. Unresolved — guide the caller toward the closest matches.
        // The message stays prose because the Guided Style Field renders it to
        // a person verbatim; the slugs that make it actionable for an API
        // client ride in `suggestions` instead.
        $this->error = true;
        $this->validState['style'] = 'invalid';
        $this->validMsg['style'] = 'We couldn\'t match "' . $this->style . '" to a known style, family, or class. Choose the closest match, or a catch-all so nothing is lost, and send it back with your label unchanged.';
        $this->responseCode = 400;
        $this->suggestStyles($this->style);

        $errorLog = new LogError();
        $errorLog->errorNumber = 261;
        $errorLog->errorMsg = 'Unresolved beer style (no canonical match)';
        // The top suggestion rides along so the log records not just what was
        // rejected but what it most likely meant — the raw material for turning
        // repeat offenders into aliases.
        $topMatch = $this->suggestions['style']['styles'][0]['style_id'] ?? ($this->suggestions['style']['families'][0]['parent'] ?? 'none');
        $errorLog->badData = $this->style . ' / closest: ' . $topMatch;
        $errorLog->filename = 'API / Beer.class.php';
        $errorLog->write();
    }

    // Attach recovery candidates for a style value we just rejected. Failure is
    // silent by design — see Style::suggest().
    private function suggestStyles($label){
        $style = new Style();
        $candidates = $style->suggest($label);
        if(!empty($candidates['styles']) || !empty($candidates['families'])){
            $this->suggestions['style'] = $candidates;
        }
    }

    // Set the resolved tier (style/parent/class), all derived up the tree.
    // $resolvedBy records which of the resolution paths got us here — see the
    // $styleResolvedBy property. It's a required argument rather than a
    // property set beside each call so a new branch can't silently skip it.
    private function setTier($styleID, $parent, $class, $beverageType, $resolvedBy){
        $this->styleID = $styleID;
        $this->parent = $parent;
        $this->class = $class;
        $this->beverageType = $beverageType;
        $this->styleResolvedBy = $resolvedBy;
        $this->validState['style'] = 'valid';
    }

    // Resolution provenance from the Guided Style Field. Unlike the tier (which is
    // re-derived server-side and never trusted from the client), confidence is an
    // inherently client-authored signal — it records HOW the brewer interacted with
    // the field (e.g. override vs auto-match), which the server cannot reconstruct.
    // Internal only: stored for data-quality review, never returned in beer objects.
    // A client value is accepted when it's consistent with the resolved tier AND no
    // stronger than the evidence supports (see capConfidence); anything else falls
    // back to the tier-derived default. On PATCH, an unchanged tier keeps its stored
    // confidence so provenance survives unrelated edits.
    private function resolveConfidence($clientConfidence, $prevConfidence = null, $tierUnchanged = false){
        // Values consistent with the resolved tier
        if(!empty($this->styleID)){
            $valid = array('confident', 'override', 'catch-all');
        }elseif(!empty($this->parent) || !empty($this->class)){
            $valid = array('family');
        }else{
            $valid = array();
        }

        $c = trim((string)($clientConfidence ?? ''));
        if(in_array($c, $valid, true)){
            $this->styleConfidence = $this->capConfidence($c);
        }elseif($tierUnchanged && ($prevConfidence === null || in_array($prevConfidence, $valid, true))){
            // Unchanged tier keeps its provenance — including NULL, which marks
            // a backfilled/legacy classification no person has asserted yet.
            $this->styleConfidence = $prevConfidence;
        }elseif(!empty($this->styleID)){
            // Filed at style tier with no client signal — derive it. A style_id
            // paired with a label the vocabulary wouldn't have matched on its
            // own is a mapping judgment, not an auto-match, and the review queue
            // exists to see exactly those. Defaulting it to 'confident' (as this
            // did) launders every API-client mapping into a clean match; the
            // Guided Style Field always sends its own value, so this branch is
            // reached by API clients only.
            if(!$this->labelMatchedVocabulary()){
                $this->styleConfidence = $this->styleIsCatchAll ? 'catch-all' : 'override';
            }else{
                $this->styleConfidence = 'confident';
            }
        }elseif(!empty($this->parent) || !empty($this->class)){
            $this->styleConfidence = 'family';
        }else{
            $this->styleConfidence = 'unresolved';
        }
    }

    // Did the submitted label resolve against the vocabulary on its own? True
    // when the label alone reached the tier, and when an explicitly named
    // style turned out to carry that label as its canonical name or an alias
    // ("NEIPA" + hazy-ipa). This is the one part of provenance the server can
    // verify for itself.
    private function labelMatchedVocabulary(){
        return $this->styleResolvedBy === 'label' || $this->styleLabelMatched;
    }

    /*--
    capConfidence — a client may state a weaker provenance than the evidence
    supports, never a stronger one.

    Confidence is client-authored because the client knows things we can't: that
    it inferred the style from the beer's name rather than a stated one, or
    picked the least-wrong of two plausible families. Those are honest
    downgrades and we want them.

    What the client must not do is upgrade. Whether the label matches the
    vocabulary is something we check, not something to take on trust, so a
    caller pairing an unmatched label with style_confidence 'confident' is
    claiming an auto-match that demonstrably didn't happen. Left unchecked, any
    client could route its guesses straight past review — which is the single
    thing the review queue exists to prevent, and it only takes one careless
    integration to make the whole signal worthless.

    Capping is silent rather than a 400: the field is internal and never
    returned, so an error would fail an otherwise good write over metadata the
    caller can't even read back.

    Ranked strongest first. Only the style tier has multiple values to rank —
    'family' is the sole legal value at its own tier, so it passes through.
    --*/
    private static $confidenceLadder = array('confident', 'override', 'catch-all');

    private function capConfidence($clientValue){
        $ceiling = $this->labelMatchedVocabulary() ? 'confident' : 'override';
        $ceilingRank = array_search($ceiling, self::$confidenceLadder, true);
        $clientRank = array_search($clientValue, self::$confidenceLadder, true);

        if($clientRank === false || $ceilingRank === false){
            // Not a ranked value (i.e. 'family') — nothing to cap.
            return $clientValue;
        }

        // Lower index = stronger claim.
        return ($clientRank < $ceilingRank) ? $ceiling : $clientValue;
    }

    // Shared: a DB error during resolution
    private function resolveDbError($db){
        $this->error = true;
        $this->errorMsg = $db->errorMsg;
        $this->responseCode = $db->responseCode;
        $db->close();
    }

    // Shared: an explicit id (style_id/parent/class) that matched nothing
    private function resolveBadId($db, $field, $value){
        $this->error = true;
        $this->validState['style'] = 'invalid';
        $this->validMsg['style'] = 'The ' . $field . ' you provided doesn\'t match anything in our catalog. Choose the closest match and try again.';
        $this->responseCode = 400;
        $db->close();

        // Suggest from the label where there is one — it describes the beer,
        // whereas the rejected slug is only a guess at our vocabulary. A slug
        // still searches usefully as a fallback, since ftTerms() splits on the
        // hyphens.
        $this->suggestStyles(!empty($this->style) ? $this->style : $value);

        $errorLog = new LogError();
        $errorLog->errorNumber = ($field === 'style_id') ? 262 : 264;
        $errorLog->errorMsg = 'Invalid ' . $field . ' (no match)';
        $errorLog->badData = $value;
        $errorLog->filename = 'API / Beer.class.php';
        $errorLog->write();
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
            $result = $db->query("SELECT brewerID, name, style, style_id, parent, class, beverage_type, style_confidence, description, abv, ibu, cbVerified, brewerVerified, lastModified FROM beer WHERE id=?", [$beerID]);
            if(!$db->error){
                if($result->num_rows == 1){
                    // Valid Result
                    $valid = true;

                    if($saveToClass){
                        $array = $result->fetch_assoc();

                        $this->beerID = $beerID;
                        $this->brewerID = $array['brewerID'];
                        $this->name = $array['name'];
                        // Prefer the canonical label column; fall back to legacy `style` during transition
                        $this->style = $array['style'];
                        $this->styleID = $array['style_id'];
                        $this->parent = $array['parent'];
                        $this->class = $array['class'];
                        $this->beverageType = $array['beverage_type'] ?? 'beer';
                        $this->styleConfidence = $array['style_confidence'] ?? null;
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
    //
    // $enriched adds style + a representative SRM to each row (a beer -> style
    // JOIN). It is only ever passed true for a master API key (see api()), so the
    // public GET /beer contract stays id/name/last_modified; the website's A-Z
    // beer index uses the enriched shape to render style names + SRM swatches
    // without a per-row fetch. Nothing new is stored — SRM comes from the style.
    public function getBeers($cursor, $count, $enriched = false){
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
            if($enriched){
                // Master-key only: style name + representative SRM (from the
                // beer's style) for the website's beer index. LEFT JOIN so beers
                // with no matched style_id still return (srm/style null).
                $result = $db->query("SELECT b.id, b.name, b.style, s.srm_min, s.srm_max FROM beer b LEFT JOIN style s ON b.style_id = s.id ORDER BY b.name LIMIT ?, ?", [$offset, $count]);
                if(!$db->error){
                    while($array = $result->fetch_assoc()){
                        $beerInfo = array(
                            'id'    => $array['id'],
                            'name'  => $array['name'],
                            'style' => ($array['style'] === null || $array['style'] === '') ? null : $array['style'],
                            'srm'   => $this->representativeSRM($array['srm_min'], $array['srm_max']),
                        );
                        $beerArray[] = $beerInfo;
                    }
                }else{
                    // Query Error
                    $this->error = true;
                    $this->errorMsg = $db->errorMsg;
                    $this->responseCode = $db->responseCode;
                }
            }else{
                $result = $db->query("SELECT id, name, lastModified FROM beer ORDER BY name LIMIT ?, ?", [$offset, $count]);
                if(!$db->error){
                    while($array = $result->fetch_assoc()){
                        // Beer Info
                        $beerInfo = array('id'=>$array['id'], 'name'=>$array['name'], 'last_modified'=>intval($array['lastModified']));
                        $beerArray[] = $beerInfo;
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
        return $beerArray;
    }

    // Collapse a style's SRM range (srm_min..srm_max) to one representative
    // integer for a color swatch, clamped to the 1-40 chart the frontend maps.
    // Returns null when the style carries no SRM (or the beer has no style),
    // which the frontend renders as a neutral swatch.
    private function representativeSRM($min, $max){
        if($min === null && $max === null){
            return null;
        }
        if($min === null){
            $val = intval($max);
        }elseif($max === null){
            $val = intval($min);
        }else{
            $val = intval(round((intval($min) + intval($max)) / 2));
        }
        if($val < 1){ $val = 1; }
        if($val > 40){ $val = 40; }
        return $val;
    }

    public function nextCursor($cursor, $count){
        // Number of Beers
        $numBeers = ($this->totalCount > 0) ? $this->totalCount : $this->countBeers();

        // Next Cursor
        $offset = intval(base64_decode($cursor));
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
                $result = $db->query("SELECT id, name, style, style_id, parent, class, beverage_type, abv, cbVerified, brewerVerified FROM beer WHERE brewerID=? ORDER BY name", [$brewerID]);
                if(!$db->error){
                    if($result->num_rows >= 1){
                        // Has Beers associated with it
                        $i=0;
                        while($array = $result->fetch_assoc()){
                            $beerInfo['data'][$i]['id'] = $array['id'];
                            $beerInfo['data'][$i]['name'] = $array['name'];
                            $beerInfo['data'][$i]['style'] = $array['style'];
                            $beerInfo['data'][$i]['style_id'] = $array['style_id'];
                            $beerInfo['data'][$i]['parent'] = $array['parent'];
                            $beerInfo['data'][$i]['class'] = $array['class'];
                            $beerInfo['data'][$i]['beverage_type'] = $array['beverage_type'] ?? 'beer';
                            $beerInfo['data'][$i]['abv'] = floatval($array['abv']);
                            $beerInfo['data'][$i]['cb_verified'] = $array['cbVerified'] ? true : false;
                            $beerInfo['data'][$i]['brewer_verified'] = $array['brewerVerified'] ? true : false;
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

            // Get User's Email Domain Name
            $userEmailDomain = $users->emailDomainName($users->email);

            // Get Brewer Information
            $brewer = new Brewer();
            $brewer->validate($this->brewerID, true);

            // Get Brewer Privileges
            $privileges = new Privileges();
            $brewerPrivilegesList = $privileges->brewerList($userID);

            // Check Permissions
            $isBreweryStaff = false;
            if(!empty($brewer->domainName) && $userEmailDomain == $brewer->domainName){
                $isBreweryStaff = true;

                if(!in_array($this->brewerID, $brewerPrivilegesList)){
                    // Give user privileges for this brewer
                    $privileges->add($userID, $this->brewerID, true);
                }
            }elseif(in_array($this->brewerID, $brewerPrivilegesList)){
                $isBreweryStaff = true;
            }

            if($users->admin || $isBreweryStaff){
                // Look up Algolia ID before deleting
                $algolia = new Algolia();
                $algoliaId = $algolia->getAlgoliaIdByRecord('beer', $beerID);

                // Delete Beer
                $db = new Database();
                $db->query("DELETE FROM beer WHERE id=?", [$beerID]);
                if(!$db->error){
                    // Delete from Algolia
                    if($algoliaId !== null){
                        $algolia->deleteObject('catalog', $algoliaId);
                    }

                    // The brewer record's beer_count just changed
                    Brewer::refreshSearchObject($this->brewerID);
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
        $this->json['style'] = $this->style;                    // human label (unchanged contract)
        $this->json['style_id'] = $this->styleID;               // canonical style FK (null if filed at family/class level)
        $this->json['parent'] = $this->parent;                  // family slug
        $this->json['class'] = $this->class;                    // super-class slug (ale|lager|null)
        $this->json['beverage_type'] = $this->beverageType;     // beer|cider|perry|mead
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
        if(!empty($this->styleID)){$array['style_id'] = $this->styleID;}
        $array['beverage_type'] = $this->beverageType;

        // Style Family / Class — facetable. Index the resolved DISPLAY names so
        // the refinement list can render them directly; a slug is never safe to
        // title-case ("ipa" -> "Ipa"). Slugs ride along for stable URL state.
        $family = $this->styleFamilyNames();
        if(!empty($this->parent)){
            $array['style_family_slug'] = $this->parent;
            if(!empty($family['parent_name'])){$array['style_family'] = $family['parent_name'];}
        }
        if(!empty($this->class)){
            $array['style_class_slug'] = $this->class;
            if(!empty($family['class_name'])){$array['style_class'] = $family['class_name'];}
        }
        if(!empty($this->description)){$array['description'] = $this->description;}
        // Omit unknown numerics rather than indexing zero. filterOnly(abv)
        // range filters match a literal 0, so an unknown ABV indexed as 0 would
        // put the beer inside every "under X%" refinement. Records missing the
        // attribute are excluded from numeric filters — correct for unknown.
        if(!empty($this->abv)){
            $array['abv'] = floatval($this->abv);
        }
        if(!empty($this->ibu)){
            $array['ibu'] = intval($this->ibu);
        }

        // Representative SRM from the beer's canonical style — the swatch color
        // the frontend renders. Omitted when the style carries no SRM.
        $srm = $this->styleSRM();
        if($srm !== null){
            $array['srm'] = $srm;
        }

        // Verification flags — the trust signals (facetable)
        $array['cb_verified'] = (bool) $this->cbVerified;
        $array['brewer_verified'] = (bool) $this->brewerVerified;

        $array['brewer']['brewerID'] = $brewer->brewerID;
        $array['brewer']['name'] = $brewer->name;

        // Geography, borrowed from the brewer's locations. Without this a
        // states/cities refinement silently drops every beer from the results —
        // "hazy IPAs in Colorado" only works if beers carry Colorado. Location
        // and address writes keep these fresh via cascadeGeographyToBeers().
        $geo = $brewer->searchGeography();
        if(!empty($geo['states'])){$array['states'] = $geo['states'];}
        if(!empty($geo['cities'])){$array['cities'] = $geo['cities'];}
        if(!empty($geo['countries'])){$array['countries'] = $geo['countries'];}

        // SiteSearch Fields
        $array['type'] = 'beer';
        // Cross-type tie-break — see customRanking in algolia/settings.php.
        $array['type_rank'] = 10;
        $array['subtitle'] = $brewer->name;
        $array['page_url'] = '/beer/' . $this->beerID;

        // Return
        return $array;
    }

    /*
    Resolve the beer's style to a representative SRM integer for the search
    index — the same collapse the enriched list endpoint uses. Returns null
    when the beer has no matched style or the style carries no SRM.

    Static cache for the same reason as styleFamilyNames(): ~250 styles shared
    across 60k+ beers, and a full batch re-index would otherwise repeat the
    lookup once per beer. Lookup failures degrade to null — the Algolia sync is
    best-effort by design.
    */
    private function styleSRM(){
        if(empty($this->styleID)){
            return null;
        }

        static $cache = array();
        if(array_key_exists($this->styleID, $cache)){
            return $cache[$this->styleID];
        }

        $db = new Database();
        $result = $db->query("SELECT srm_min, srm_max FROM style WHERE id=?", [$this->styleID]);
        if($db->error){
            // Query Error — log and return null, but do NOT cache: a transient
            // failure must not pin a missing swatch for the process.
            $errorLog = new LogError();
            $errorLog->errorNumber = 289;
            $errorLog->errorMsg = 'Failed to resolve style SRM for search object.';
            $errorLog->badData = "beerID: {$this->beerID} / styleID: {$this->styleID}";
            $errorLog->filename = 'API / Beer.class.php';
            $errorLog->write();
            $db->close();
            return null;
        }

        $srm = null;
        if(($row = $result->fetch_assoc()) !== null){
            $srm = $this->representativeSRM($row['srm_min'], $row['srm_max']);
        }
        $db->close();

        $cache[$this->styleID] = $srm;
        return $srm;
    }

    /*
    Resolve the beer's family/class slugs to their display names for the search
    index. A beer may be filed at class level with no family, so the class name
    is looked up independently rather than derived through style_parent.

    Returns array('parent_name'=>string|null, 'class_name'=>string|null).
    Lookup failures degrade to null — a missing facet label must never break the
    Algolia sync, which is best-effort by design.
    */
    private function styleFamilyNames(){
        // Families and classes are a small static vocabulary (~30 rows) shared
        // across thousands of beers. Without this, a full batch re-index would
        // repeat the same handful of lookups once per beer.
        static $cache = array();

        $names = array('parent_name'=>null, 'class_name'=>null);

        // Nothing filed above the style level
        if(empty($this->parent) && empty($this->class)){
            return $names;
        }

        $key = $this->parent . '|' . $this->class;
        if(isset($cache[$key])){
            return $cache[$key];
        }

        $db = new Database();

        // Family (carries its class through the FK)
        if(!empty($this->parent)){
            $result = $db->query("SELECT p.name AS parent_name, c.name AS class_name FROM style_parent p LEFT JOIN style_class c ON p.class = c.slug WHERE p.slug=?", [$this->parent]);
            if(!$db->error && ($row = $result->fetch_assoc()) !== null){
                $names['parent_name'] = $row['parent_name'];
                $names['class_name'] = $row['class_name'];
            }
        }

        // Filed directly at class level, or the family had no class attached
        if(!empty($this->class) && empty($names['class_name'])){
            $result = $db->query("SELECT name FROM style_class WHERE slug=?", [$this->class]);
            if(!$db->error && ($row = $result->fetch_assoc()) !== null){
                $names['class_name'] = $row['name'];
            }
        }

        if($db->error){
            // Query Error — log and return whatever resolved, but do NOT cache:
            // a transient failure must not pin empty labels for the process.
            $errorLog = new LogError();
            $errorLog->errorNumber = 277;
            $errorLog->errorMsg = 'Failed to resolve style family names for search object.';
            $errorLog->badData = "beerID: {$this->beerID} / parent: {$this->parent} / class: {$this->class}";
            $errorLog->filename = 'API / Beer.class.php';
            $errorLog->write();
            $db->close();
            return $names;
        }

        $db->close();
        $cache[$key] = $names;
        return $names;
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
            $errorLog->filename = 'API / Beer.class.php';
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
            $errorLog->filename = 'API / Beer.class.php';
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
            $errorLog->filename = 'API / Beer.class.php';
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

        // Ranking is tiered, not a blended score. A single relevance value
        // across (name, style, description) weights a description mention the
        // same as the beer's own name, and FULLTEXT has no stemming — "mash"
        // is not the token "Mashed". Both together buried "Triple-Mashed" at
        // position ~23 for the query "triple mash".
        //
        //   0  the query IS the beer's name
        //   1  every query term appears in the name as a word prefix
        //   2  anything else the natural-language match returns
        //
        // Within a tier: name relevance, then blended relevance, then
        // name/id so pagination order is deterministic.
        //
        // The boolean-mode term in the WHERE clause also widens recall:
        // natural-language matching is exact-token, so "mash" alone would
        // never return a beer named "Triple-Mashed" without it.
        $db = new Database();
        $result = $db->query("SELECT b.id, b.brewerID, b.name, b.style, b.style_id, b.parent, b.class, b.beverage_type, b.description, b.abv, b.ibu, b.cbVerified, b.brewerVerified, b.lastModified, br.id AS brewer_id, br.name AS brewer_name, br.description AS brewer_description, br.shortDescription AS brewer_shortDescription, br.url AS brewer_url, br.cbVerified AS brewer_cbVerified, br.brewerVerified AS brewer_brewerVerified, br.lastModified AS brewer_lastModified, CASE WHEN LOWER(b.name) = LOWER(?) THEN 0 WHEN MATCH(b.name) AGAINST(? IN BOOLEAN MODE) > 0 THEN 1 ELSE 2 END AS tier, MATCH(b.name) AGAINST(? IN NATURAL LANGUAGE MODE) AS name_rel, MATCH(b.name, b.style, b.description) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance FROM beer b JOIN brewer br ON b.brewerID = br.id WHERE MATCH(b.name, b.style, b.description) AGAINST(? IN NATURAL LANGUAGE MODE) OR MATCH(b.name) AGAINST(? IN BOOLEAN MODE) OR LOWER(b.name) = LOWER(?) ORDER BY tier, name_rel DESC, relevance DESC, b.name, b.id LIMIT ?, ?", [$query, $searchTerms['bool'], $searchTerms['nl'], $searchTerms['nl'], $searchTerms['nl'], $searchTerms['bool'], $query, $offset, $fetchCount]);
        if(!$db->error){
            $rowCount = 0;
            $data = array();
            while($row = $result->fetch_assoc()){
                $rowCount++;
                if($rowCount > $count){
                    // Extra row — indicates more results exist
                    break;
                }

                // Build beer object
                $beerObj = array();
                $beerObj['id'] = $row['id'];
                $beerObj['object'] = 'beer';
                $beerObj['name'] = $row['name'];
                $beerObj['style'] = $row['style'];
                $beerObj['style_id'] = $row['style_id'];
                $beerObj['parent'] = $row['parent'];
                $beerObj['class'] = $row['class'];
                $beerObj['beverage_type'] = $row['beverage_type'] ?? 'beer';
                $beerObj['description'] = $row['description'] ?? null;
                $beerObj['abv'] = floatval($row['abv']);
                $beerObj['ibu'] = !empty($row['ibu']) ? intval($row['ibu']) : null;
                $beerObj['cb_verified'] = $row['cbVerified'] ? true : false;
                $beerObj['brewer_verified'] = $row['brewerVerified'] ? true : false;
                $beerObj['last_modified'] = intval($row['lastModified']);

                // Build brewer sub-object
                $brewerObj = array();
                $brewerObj['id'] = $row['brewer_id'];
                $brewerObj['object'] = 'brewer';
                $brewerObj['name'] = $row['brewer_name'];
                $brewerObj['description'] = $row['brewer_description'] ?? null;
                $brewerObj['short_description'] = $row['brewer_shortDescription'] ?? null;
                $brewerObj['url'] = $row['brewer_url'] ?? null;
                $brewerObj['cb_verified'] = $row['brewer_cbVerified'] ? true : false;
                $brewerObj['brewer_verified'] = $row['brewer_brewerVerified'] ? true : false;
                $brewerObj['last_modified'] = intval($row['brewer_lastModified']);

                $beerObj['brewer'] = $brewerObj;
                $data[] = $beerObj;
            }

            // Build response
            $hasMore = ($rowCount > $count);
            $this->json['object'] = 'list';
            $this->json['url'] = '/beer/search';
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
            $errorLog->errorNumber = 237;
            $errorLog->errorMsg = 'Beer FULLTEXT query error';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Beer.class.php';
            $errorLog->write();
        }
        $db->close();
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
                            case 'search':
                                // GET https://api.catalog.beer/beer/search?q=
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
                        $cursor = base64_encode('0');   // Page
                        $count = 500;

                        // Get Variables
                        if(isset($_GET['cursor'])){
                            $cursor = $_GET['cursor'];
                        }
                        if(isset($_GET['count'])){
                            $count = $_GET['count'];
                        }

                        // Enriched rows (style + SRM) are master-key only. A
                        // non-master caller passing ?enriched just gets the
                        // standard shape — no error, no leak.
                        $enriched = false;
                        if(!empty($_GET['enriched']) && in_array($apiKey, unserialize(MASTER_API_KEYS))){
                            $enriched = true;
                        }

                        // Query
                        $beerArray = $this->getBeers($cursor, $count, $enriched);
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
                if(!empty($function)){
                    // Method Not Allowed for /beer/{function}
                    $this->responseCode = 405;
                    $this->responseHeader = 'Allow: GET';
                    $this->json['error'] = true;
                    $this->json['error_msg'] = 'Method Not Allowed. Use GET for this endpoint.';

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 240;
                    $errorLog->errorMsg = 'Invalid Method for /beer/' . $function;
                    $errorLog->badData = $method;
                    $errorLog->filename = 'API / Beer.class.php';
                    $errorLog->write();
                    break;
                }

                // POST https://api.catalog.beer/beer
                // Handle Empty Fields
                if(empty($data->brewer_id)){$data->brewer_id = '';}
                if(empty($data->name)){$data->name = '';}
                if(empty($data->style)){$data->style = '';}
                if(empty($data->style_id)){$data->style_id = '';}
                if(empty($data->parent)){$data->parent = '';}
                if(empty($data->class)){$data->class = '';}
                if(empty($data->style_confidence)){$data->style_confidence = '';}
                if(empty($data->description)){$data->description = '';}
                if(!isset($data->abv)){$data->abv = '';}
                if(!isset($data->ibu)){$data->ibu = '';}

                // Validate API Key for userID
                $apiKeys = new apiKeys();
                $apiKeys->validate($apiKey, true);

                // Add Beer
                $this->add($data->brewer_id, $data->name, $data->style, $data->style_id, $data->parent, $data->class, $data->style_confidence, $data->description, $data->abv, $data->ibu, $apiKeys->userID, 'POST', '', array());
                if(!$this->error){
                    // Beer Object JSON
                    $this->generateBeerObject();
                }else{
                    $this->json['error'] = true;
                    $this->json['error_msg'] = $this->errorMsg;
                    $this->json['valid_state'] = $this->validState;
                    $this->json['valid_msg'] = $this->validMsg;
                    // Additive and optional: present only when we have
                    // candidates, so clients must treat it as such.
                    if(!empty($this->suggestions)){
                        $this->json['suggestions'] = $this->suggestions;
                    }
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
                if(empty($data->style_id)){$data->style_id = '';}
                if(empty($data->parent)){$data->parent = '';}
                if(empty($data->class)){$data->class = '';}
                if(empty($data->style_confidence)){$data->style_confidence = '';}
                if(empty($data->description)){$data->description = '';}
                if(!isset($data->abv)){$data->abv = '';}
                if(!isset($data->ibu)){$data->ibu = '';}

                // Validate API Key for userID
                $apiKeys = new apiKeys();
                $apiKeys->validate($apiKey, true);

                // Add/Update/Replace Beer
                $this->add($data->brewer_id, $data->name, $data->style, $data->style_id, $data->parent, $data->class, $data->style_confidence, $data->description, $data->abv, $data->ibu, $apiKeys->userID, 'PUT', $id, array());
                if(!$this->error){
                    // Beer Object JSON
                    $this->generateBeerObject();
                }else{
                    $this->json['error'] = true;
                    $this->json['error_msg'] = $this->errorMsg;
                    $this->json['valid_state'] = $this->validState;
                    $this->json['valid_msg'] = $this->validMsg;
                    // Additive and optional: present only when we have
                    // candidates, so clients must treat it as such.
                    if(!empty($this->suggestions)){
                        $this->json['suggestions'] = $this->suggestions;
                    }
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

                // Style change triggered by any of style, style_id, parent, or class
                if(isset($data->style) || isset($data->style_id) || isset($data->parent) || isset($data->class)){
                    $patchFields[] = 'style';
                    if(!isset($data->style)){$data->style = '';}
                    if(!isset($data->style_id)){$data->style_id = '';}
                    if(!isset($data->parent)){$data->parent = '';}
                    if(!isset($data->class)){$data->class = '';}
                    if(!isset($data->style_confidence)){$data->style_confidence = '';}
                }else{
                    $data->style = '';
                    $data->style_id = '';
                    $data->parent = '';
                    $data->class = '';
                    $data->style_confidence = '';
                }

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
                $this->add($data->brewer_id, $data->name, $data->style, $data->style_id, $data->parent, $data->class, $data->style_confidence, $data->description, $data->abv, $data->ibu, $apiKeys->userID, 'PATCH', $id, $patchFields);
                if(!$this->error){
                    // Beer Object JSON
                    $this->generateBeerObject();
                }else{
                    $this->json['error'] = true;
                    $this->json['error_msg'] = $this->errorMsg;
                    $this->json['valid_state'] = $this->validState;
                    $this->json['valid_msg'] = $this->validMsg;
                    // Additive and optional: present only when we have
                    // candidates, so clients must treat it as such.
                    if(!empty($this->suggestions)){
                        $this->json['suggestions'] = $this->suggestions;
                    }
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