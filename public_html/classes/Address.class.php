<?php
/*--
The address of a location: its validator, its parsers and its wire shape
(since v6.0.0, 2026-10-17; the US-only USAddresses class and a separate
address table before that).

There is no address resource. The address lives on the location row -- eleven
nullable columns, NULL as a block when the location has none -- and is written
through POST/PUT/PATCH /location as a nested `address` object. Location::add()
hands this class the submitted fields, the location's country and (on PATCH)
the stored columns; validateFor() runs the shape checks and Google Address
Validation and leaves the column values in columns(). Nothing here touches the
database except Subdivisions lookups.

Validation is Google Address Validation for all of COUNTRIES (the 40 regions
Google covers); the USPS CASS layer rides along only for US and PR. Two
parsers share the acceptance gate:

  US      parseValidatedAddress() + deriveCity(): the CASS-vs-Google rules
          decided 2026-08-05 and replayed by tests/address-parse.php. Not a
          line of them changed for this release.
  others  parseInternational(): Google's own rendering is the truth. The
          street line is postalAddress.addressLines[0] with the postal
          abbreviation undone from the route component ("Köpenicker Str." ->
          "Köpenicker Straße", "Bd" -> "Boulevard"), the unit is the
          subpremise, the locality is postalAddress.locality (Google already
          resolves "Westvleteren" against "Vleteren"), and `formatted` is
          formattedAddress split into lines with the country dropped. No AP
          abbreviation, no smart-casing. Replayed by tests/address-parse.php
          against fixtures/google-address-validation-intl.json.

One vocabulary, Google's postalAddress one: line1, line2, locality,
dependent_locality, sub_code, postal_code, telephone on the way in; those
plus formatted, language_code and admin_area on the way out.
Inside this class the US parsing block still speaks address1/address2/city,
because tests/address-parse.php and the 2026-08-05 audit do; syncLegacy()
keeps the two property sets equal and nothing outside the class sees them.

telephone is an E.164 string ("+15035550100"). It was an int of ten NANP
digits; a Belgian number does not fit that, and without the "+" the digits
are ambiguous (3257400376 is both +32 57 40 03 76 and a Texas number).
--*/
class Address {

    /* The regions Google Address Validation covers (2026-10). A location
       outside this list is refused at POST /location (error 51), so an
       address never has to degrade. */
    const COUNTRIES = array('AR', 'AT', 'AU', 'BE', 'BG', 'BR', 'CA', 'CH', 'CL', 'CO', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'HR', 'HU', 'IE', 'IN', 'IT', 'JP', 'LT', 'LU', 'LV', 'MX', 'MY', 'NL', 'NO', 'NZ', 'PL', 'PR', 'PT', 'SE', 'SG', 'SI', 'SK', 'US');

    /* USPS CASS only standardizes US and Puerto Rico addresses. */
    const CASS_REGIONS = array('US', 'PR');

    /* NANP countries: a national number is ten digits, optionally led by 1. */
    const NANP = array('US', 'CA', 'PR');

    /* E.164 country calling codes, for normalizing a nationally-formatted
       number using the location's country. */
    const CALLING_CODES = array(
        'AR' => '54', 'AT' => '43', 'AU' => '61', 'BE' => '32', 'BG' => '359', 'BR' => '55',
        'CA' => '1', 'CH' => '41', 'CL' => '56', 'CO' => '57', 'CZ' => '420', 'DE' => '49',
        'DK' => '45', 'EE' => '372', 'ES' => '34', 'FI' => '358', 'FR' => '33', 'GB' => '44',
        'HR' => '385', 'HU' => '36', 'IE' => '353', 'IN' => '91', 'IT' => '39', 'JP' => '81',
        'LT' => '370', 'LU' => '352', 'LV' => '371', 'MX' => '52', 'MY' => '60', 'NL' => '31',
        'NO' => '47', 'NZ' => '64', 'PL' => '48', 'PR' => '1', 'PT' => '351', 'SE' => '46',
        'SG' => '65', 'SI' => '386', 'SK' => '421', 'US' => '1',
    );

    /* National trunk prefix dropped when a number is dialled internationally,
       as a regex alternation. Most of Europe and the Commonwealth use 0;
       Hungary 06; Lithuania 8 until 2021 and 0 since. Italy KEEPS its 0 (it
       is part of the subscriber number), as do the countries that have no
       trunk prefix at all, so those are listed empty rather than defaulted. */
    const TRUNK_PREFIX = array(
        'AR' => '0', 'AT' => '0', 'AU' => '0', 'BE' => '0', 'BG' => '0', 'BR' => '0',
        'CH' => '0', 'CL' => '', 'CO' => '', 'CZ' => '', 'DE' => '0', 'DK' => '',
        'EE' => '', 'ES' => '', 'FI' => '0', 'FR' => '0', 'GB' => '0', 'HR' => '0',
        'HU' => '06', 'IE' => '0', 'IN' => '0', 'IT' => '', 'JP' => '0', 'LT' => '[08]',
        'LU' => '', 'LV' => '', 'MX' => '', 'MY' => '0', 'NL' => '0', 'NO' => '',
        'NZ' => '0', 'PL' => '', 'PT' => '', 'SE' => '0', 'SG' => '', 'SI' => '0', 'SK' => '0',
    );

    /* Pre-flight shape of a postal code, deliberately loose (separators
       optional, case-insensitive). This exists to turn an obvious typo into
       a 400 naming the field before the Google call, not to be the
       authority: Google's answer is what gets stored. A country not listed
       (IE's Eircode varies too much) is sent through as typed. */
    const POSTAL_SHAPES = array(
        'US' => '^\d{5}(-\d{4})?$',   'PR' => '^\d{5}(-\d{4})?$',   'CA' => '^[A-Z]\d[A-Z] ?\d[A-Z]\d$',
        'GB' => '^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$', 'AU' => '^\d{4}$', 'NZ' => '^\d{4}$',
        'BE' => '^\d{4}$',  'NL' => '^\d{4} ?[A-Z]{2}$', 'LU' => '^(L-?)?\d{4}$', 'DE' => '^\d{5}$',
        'FR' => '^\d{5}$',  'AT' => '^\d{4}$',  'CH' => '^\d{4}$',  'DK' => '^\d{4}$',  'NO' => '^\d{4}$',
        'SE' => '^\d{3} ?\d{2}$', 'FI' => '^\d{5}$', 'PL' => '^\d{2}-?\d{3}$', 'CZ' => '^\d{3} ?\d{2}$',
        'SK' => '^\d{3} ?\d{2}$', 'HU' => '^\d{4}$', 'ES' => '^\d{5}$', 'IT' => '^\d{5}$',
        'PT' => '^\d{4}(-\d{3})?$', 'HR' => '^\d{5}$', 'SI' => '^(SI-?)?\d{4}$', 'BG' => '^\d{4}$',
        'EE' => '^\d{5}$',  'LV' => '^(LV-?)?\d{4}$', 'LT' => '^(LT-?)?\d{5}$', 'JP' => '^\d{3}-?\d{4}$',
        'IN' => '^\d{6}$',  'SG' => '^\d{6}$',  'MY' => '^\d{5}$',  'MX' => '^\d{5}$',  'BR' => '^\d{5}-?\d{3}$',
        'AR' => '^([A-Z]\d{4}[A-Z]{3}|\d{4})$', 'CL' => '^\d{3}-?\d{4}$', 'CO' => '^\d{6}$',
    );

    /* The request keys; the body is read through this list in api(). */
    const FIELDS = array('line1', 'line2', 'locality', 'dependent_locality', 'sub_code', 'postal_code', 'telephone');

    // Properties -- the stored shape (address.* columns)
    public $locationID = '';        // Required
    public $countryCode = '';       // Always the location's; set from it in add()/validate()
    public $line1 = '';             // Required (street)
    public $line2 = '';             // Optional (unit)
    public $locality = '';          // locality OR postal_code required
    public $dependentLocality = '';
    public $sub_code = '';          // Required for US; optional elsewhere
    public $postalCode = '';
    public $formatted = null;       // array of display lines, or null
    public $languageCode = '';
    public $telephone = null;       // E.164 string, or null
    public $validatedAt = null;      // when the validator last accepted it

    // Subdivision as loaded/looked up
    public $subName = '';
    public $subType = '';

    // US-only derived values (the legacy keys)
    public $stateShort = '';
    public $stateLong = '';
    public $zip5 = '';              // Strings: ZIP codes have significant leading zeros (01085)
    public $zip4 = '';

    /* Internal only: the US parsing block below and tests/address-parse.php
       refer to the street as address2 and the unit as address1 (USPS order).
       They are the SAME values as line1/line2 and are synced in both
       directions by syncLegacy(); nothing on the wire carries these names. */
    public $address1 = '';
    public $address2 = '';
    public $city = '';

    // Error Handling
    public $error = false;
    public $errorMsg = null;
    public $validState = array('line1'=>null, 'line2'=>null, 'locality'=>null, 'dependent_locality'=>null, 'sub_code'=>null, 'postal_code'=>null, 'telephone'=>null);
    public $validMsg = array('line1'=>null, 'line2'=>null, 'locality'=>null, 'dependent_locality'=>null, 'sub_code'=>null, 'postal_code'=>null, 'telephone'=>null);
    public $responseCode = 200;
    public $responseHeader = '';
    public $json = array();
    private $latLongFound = false;
    private $latitude = null;
    private $longitude = null;
    private $filename = 'API / Address.class.php';

    private function flag($field, $state, $message = null){
        $this->validState[$field] = $state;
        if($message !== null){ $this->validMsg[$field] = $message; }
    }

    private function logError($number, $msg, $badData){
        $errorLog = new LogError();
        $errorLog->errorNumber = $number;
        $errorLog->errorMsg = $msg;
        $errorLog->badData = $badData;
        $errorLog->filename = $this->filename;
        $errorLog->write();
    }

    /* Keep the two property vocabularies equal. $from says which side was
       just written. */
    private function syncLegacy($from){
        if($from === 'legacy'){
            $this->line1 = $this->address2;
            $this->line2 = $this->address1;
            $this->locality = $this->city;
        }else{
            $this->address2 = $this->line1;
            $this->address1 = $this->line2;
            $this->city = $this->locality;
        }
    }

    public static function isUS($countryCode){
        return $countryCode === 'US';
    }

    /*--
    Validate a submitted address for a location in $countryCode and leave the
    column values in columns(). Called by Location::add() before the location
    row is written, so a refused address writes nothing.

      $fields       the seven request keys, '' when absent (api() reduces the
                    nested object to this shape)
      $method       POST / PUT / PATCH
      $patchFields  PATCH only: the keys the request actually sent
      $storedRow    PATCH only: the location's current address columns (the
                    row from Location::validate()), or null when it has none

    On PATCH the sent fields are merged over the stored ones and the merged
    whole re-validates as one; a telephone-only patch skips Google. POST and
    PUT validate what was sent, nothing more. Returns true when valid; on
    failure $error, $validState, $validMsg and $responseCode say why.
    --*/
    public function validateFor($countryCode, array $fields, $method, array $patchFields, $storedRow){
        $this->countryCode = strval($countryCode);
        $patching = ($method === 'PATCH' && is_array($storedRow) && !empty($storedRow['line1']));
        if($patching){
            $this->loadRow($storedRow['id'] ?? '', $storedRow);
        }

        foreach(array('line1', 'line2', 'locality', 'dependent_locality', 'sub_code', 'postal_code', 'telephone') as $key){
            if(!$patching || in_array($key, $patchFields, true)){
                $prop = $key === 'dependent_locality' ? 'dependentLocality' : ($key === 'postal_code' ? 'postalCode' : $key);
                $this->{$prop} = $fields[$key];
            }
        }
        $this->syncLegacy('new');

        /*--
        locality+sub_code and postal_code are two spellings of the same fact --
        the locality. When a patch supplies one group and not the other, the
        stored other group describes the PREVIOUS locality, and merging it in
        hands Google a contradiction it resolves unpredictably: a stale city
        next to a fresh ZIP degraded CASS enough to store an unnormalized
        "# 800" unit, and a stale CA sub_code beat a patched Massachusetts ZIP
        outright. Drop the un-patched group instead -- Google re-derives it
        from the one the client vouched for. A patch touching neither group
        (line1, line2, telephone) keeps the stored pair, which was validated
        together and cannot disagree with itself.
        --*/
        if($patching){
            $postalPatched = in_array('postal_code', $patchFields, true);
            $localityPatched = in_array('locality', $patchFields, true) || in_array('sub_code', $patchFields, true);
            if($postalPatched && !$localityPatched){
                $this->locality = '';
                $this->dependentLocality = '';
                $this->sub_code = '';
                $this->stateShort = '';
                $this->stateLong = '';
                $this->subName = '';
                $this->subType = '';
                $this->syncLegacy('new');
            }elseif($localityPatched && !$postalPatched){
                $this->postalCode = '';
                $this->zip5 = '';
                $this->zip4 = '';
            }
        }

        $addressFieldsSent = !$patching || count(array_diff($patchFields, array('telephone'))) > 0;
        if($addressFieldsSent){
            // Validate & standardize the address (Google Address Validation)
            $this->validateAddress();
        }
        if(!$patching || in_array('telephone', $patchFields, true)){
            $this->validateTelephone();
        }
        return !$this->error;
    }

    /* The ten column values after a successful validateFor(), in table
       order: line1, line2, locality, dependentLocality, sub_code, postalCode,
       formatted, languageCode, telephone, validatedAt. Empty optionals go to
       NULL. */
    public function columns(){
        return array(
            $this->line1,
            $this->line2 !== '' ? $this->line2 : null,
            $this->locality,
            $this->dependentLocality !== '' ? $this->dependentLocality : null,
            $this->sub_code !== '' ? $this->sub_code : null,
            $this->postalCode !== '' ? $this->postalCode : null,
            is_array($this->formatted) && !empty($this->formatted) ? json_encode($this->formatted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $this->languageCode !== '' ? $this->languageCode : null,
            $this->telephone,
            $this->validatedAt,
        );
    }

    const COLUMN_NAMES = array('line1', 'line2', 'locality', 'dependentLocality', 'sub_code', 'postalCode', 'formatted', 'languageCode', 'telephone', 'validatedAt');

    /* [latitude, longitude] from the validation that just ran, or null when
       this validateFor() did not call Google (a telephone-only patch). */
    public function coordinates(){
        return $this->latLongFound ? array($this->latitude, $this->longitude) : null;
    }

    // ----- Validation -----

    private function validateAddress(){
        // Trim Inputs
        $this->line1 = TextInput::trim($this->line1 ?? '');
        $this->line2 = TextInput::trim($this->line2 ?? '');
        $this->locality = TextInput::trim($this->locality ?? '');
        $this->dependentLocality = TextInput::trim($this->dependentLocality ?? '');
        $this->sub_code = strtoupper(trim($this->sub_code ?? ''));
        $this->postalCode = trim($this->postalCode ?? '');

        if(!in_array($this->countryCode, self::COUNTRIES, true)){
            // The location gate (error 51) should make this unreachable; it
            // is here for a location that predates a list change.
            $this->error = true;
            $this->errorMsg = 'Sorry, we can\'t validate addresses in ' . (CountryCode::shortName($this->countryCode) ?? $this->countryCode) . ' yet.';
            $this->responseCode = 400;
            $this->logError(337, 'Address country not in Address::COUNTRIES', 'countryCode: ' . $this->countryCode . ' / locationID: ' . $this->locationID);
            return;
        }

        if(self::isUS($this->countryCode)){
            // Substitute Accented Characters -- USPS data is ASCII, and this
            // is what the US path has always done. Google returns the proper
            // spelling for anything it standardizes.
            $accented_chars = array('Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
            $this->line1 = strtr($this->line1, $accented_chars);
            $this->line2 = strtr($this->line2, $accented_chars);
            $this->locality = strtr($this->locality, $accented_chars);
        }
        $this->syncLegacy('new');

        // Street Address (required)
        if($this->line1 === ''){
            // Missing Address Line
            $this->error = true;
            $this->flag('line1', 'invalid', 'Sorry, we seem to be missing the street address for this location. Please double check your submission.');
            $this->responseCode = 400;
            $this->logError(58, 'Missing street address', '');
        }

        if(self::isUS($this->countryCode)){
            $this->validateUSInputs();
        }else{
            $this->validateInternationalInputs();
        }

        if(!$this->error){
            // Submit to Google Address Validation API
            $this->googleAddressValidationAPI();
        }
    }

    /* The US input rules, unchanged: zip5 (+ zip4) OR city + sub_code. The
       postal code is split into the two legacy parts here and rejoined on
       the way out. */
    private function validateUSInputs(){
        $this->zip5 = '';
        $this->zip4 = '';
        if($this->postalCode !== ''){
            if(strpos($this->postalCode, '-') !== false){
                list($this->zip5, $this->zip4) = explode('-', $this->postalCode, 2);
            }else{
                $this->zip5 = $this->postalCode;
            }
            $this->zip5 = trim($this->zip5);
            $this->zip4 = trim($this->zip4);
        }

        if($this->zip5 !== ''){
            // Validate ZIP Code
            if(!preg_match('/^[0-9]{5}$/', $this->zip5)){
                // Invalid ZIP Code
                $this->error = true;
                $this->flag('postal_code', 'invalid', 'Sorry, this appears to be an invalid US ZIP Code. Send postal_code as five digits (97201), or five digits, a hyphen and four (97201-1234).');
                $this->responseCode = 400;
                $this->logError(59, 'Invalid Zip5', "Zip5: " . $this->zip5);
            }

            // Validate ZIP Code + 4 (optional)
            if($this->zip4 !== '' && !preg_match('/^[0-9]{4}$/', $this->zip4)){
                // Invalid ZIP Code + 4
                $this->error = true;
                $this->flag('postal_code', 'invalid', 'Sorry, this appears to be an invalid ZIP Code + 4. Send postal_code as five digits, a hyphen and four (97201-1234), or the five digits alone.');
                $this->responseCode = 400;
                $this->logError(60, 'Invalid Zip4', "Zip4: " . $this->zip4);
            }

            // Derive state from sub_code if provided (optional; Google infers it from the ZIP otherwise)
            if(empty($this->stateShort) && !empty($this->sub_code)){
                $this->stateShort = substr($this->sub_code, 3, 2);
            }
        }else{
            // No ZIP Code provided, City & State Required

            // Check City
            if($this->locality === ''){
                // Missing City
                $this->error = true;
                $this->flag('locality', 'invalid', 'What city is this location in? If you don\'t know the city, you can alternatively provide the postal code.');
                $this->responseCode = 400;
                $this->logError(61, 'Missing City', '');
            }

            // Check State
            if(!empty($this->sub_code)){
                $subdivisions = new Subdivisions();
                if($subdivisions->validate($this->sub_code, true) && $subdivisions->countryCode === 'US'){
                    // Get State Info
                    $this->stateShort = substr($this->sub_code, 3, 2);
                    $this->stateLong = $subdivisions->sub_name;
                    $this->subName = $subdivisions->sub_name;
                    $this->subType = $subdivisions->subType;
                    $this->validState['sub_code'] = 'valid';
                }else{
                    // Invalid Subdivision
                    $this->error = true;
                    $this->validState['sub_code'] = 'invalid';
                    $this->validMsg['sub_code'] = 'Sorry, this appears to be an invalid sub_code. Please double check the parameter.';
                    $this->responseCode = 400;
                    $this->logError(62, 'Invalid sub_code', "sub_code: " . $this->sub_code);
                }
            }else{
                // Missing sub_code
                $this->error = true;
                $this->validState['sub_code'] = 'invalid';
                $this->validMsg['sub_code'] = 'Sorry, we seem to be missing the sub_code for this location. Please check your submission.';
                $this->responseCode = 400;
            }
        }
    }

    /* Outside the US: a street plus a locality or a postal code; sub_code
       optional but, when sent, a seeded subdivision of this country; the
       postal code shape-checked where the shape is known. */
    private function validateInternationalInputs(){
        if($this->locality === '' && $this->postalCode === ''){
            $this->error = true;
            $this->flag('locality', 'invalid', 'What city or town is this location in? If you don\'t know, you can alternatively provide the postal code.');
            $this->responseCode = 400;
            $this->logError(61, 'Missing City', 'countryCode: ' . $this->countryCode);
        }

        if($this->postalCode !== '' && isset(self::POSTAL_SHAPES[$this->countryCode])){
            if(!preg_match('/' . self::POSTAL_SHAPES[$this->countryCode] . '/i', $this->postalCode)){
                $this->error = true;
                $this->flag('postal_code', 'invalid', 'Sorry, "' . $this->postalCode . '" doesn\'t look like a postal code in ' . CountryCode::shortName($this->countryCode) . '. Please double check it.');
                $this->responseCode = 400;
                $this->logError(339, 'Postal code shape', 'countryCode: ' . $this->countryCode . ' / postal_code: ' . $this->postalCode);
            }
        }

        if($this->sub_code !== ''){
            // A bare region code is accepted and prefixed with the country,
            // the way BrewerLead already does for a lead.
            if(preg_match('/^[A-Z0-9]{1,3}$/', $this->sub_code)){
                $this->sub_code = $this->countryCode . '-' . $this->sub_code;
            }
            $subdivisions = new Subdivisions();
            if(!$subdivisions->validate($this->sub_code, true)){
                $this->error = true;
                $this->validState['sub_code'] = 'invalid';
                $this->validMsg['sub_code'] = 'Sorry, this appears to be an invalid sub_code. Please double check the parameter.';
                $this->responseCode = 400;
                $this->logError(62, 'Invalid sub_code', "sub_code: " . $this->sub_code);
            }elseif($subdivisions->countryCode !== $this->countryCode){
                $this->error = true;
                $this->validState['sub_code'] = 'invalid';
                $this->validMsg['sub_code'] = 'sub_code ' . $this->sub_code . ' is in ' . CountryCode::shortName($subdivisions->countryCode) . ', but this location is in ' . CountryCode::shortName($this->countryCode) . '.';
                $this->responseCode = 400;
                $this->logError(338, 'sub_code in another country', 'sub_code: ' . $this->sub_code . ' / countryCode: ' . $this->countryCode);
            }else{
                $this->subName = $subdivisions->sub_name;
                $this->subType = $subdivisions->subType;
                $this->validState['sub_code'] = 'valid';
            }
        }
    }

    private function googleAddressValidationAPI(){
        $isUS = self::isUS($this->countryCode);

        // Build the address payload from class properties
        $addressLines = array();
        if($this->line1 !== ''){
            $addressLines[] = $this->line1;
        }
        if($this->line2 !== ''){
            $addressLines[] = $this->line2;
        }

        $address = array(
            'regionCode' => $this->countryCode,
            'addressLines' => $addressLines
        );
        if($this->locality !== ''){
            $address['locality'] = $this->locality;
        }
        if($this->dependentLocality !== ''){
            $address['sublocality'] = $this->dependentLocality;
        }
        if($isUS){
            if(!empty($this->stateShort)){
                $address['administrativeArea'] = $this->stateShort;
            }
            if($this->zip5 !== ''){
                $address['postalCode'] = $this->zip4 !== '' ? ($this->zip5 . '-' . $this->zip4) : $this->zip5;
            }
        }else{
            // The subdivision goes along only where its ISO suffix is the
            // everyday abbreviation (QC, VIC); elsewhere it would be a token
            // Google has to explain away.
            if($this->sub_code !== '' && in_array($this->countryCode, array('CA', 'AU', 'BR', 'MX', 'IN', 'MY'), true)){
                $address['administrativeArea'] = substr($this->sub_code, 3);
            }
            if($this->postalCode !== ''){
                $address['postalCode'] = $this->postalCode;
            }
        }

        $requestBody = json_encode(array(
            'address' => $address,
            'enableUspsCass' => in_array($this->countryCode, self::CASS_REGIONS, true)
        ), JSON_UNESCAPED_UNICODE);

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
            $this->logError(265, 'Google Address Validation cURL Error', $err);
            return;
        }

        if($httpCode !== 200){
            // API Error (bad request, auth/key, rate limit, etc.)
            $this->error = true;
            $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
            $this->responseCode = 500;
            $this->logError(266, 'Google Address Validation API Error', 'HTTP ' . $httpCode . ' // ' . $requestData . ' // Response: ' . $response);
            return;
        }

        // Parse JSON Response
        $responseData = json_decode($response, true);
        $result = $responseData['result'] ?? array();
        $verdict = $result['verdict'] ?? array();
        $granularity = $verdict['validationGranularity'] ?? '';
        $geo = $result['geocode']['location'] ?? array();

        if(empty($result) || empty($verdict)){
            // Empty / unparseable result
            $this->error = true;
            $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
            $this->responseCode = 500;
            $this->logError(268, 'Google Address Validation Empty Result', $requestData . ' // Response: ' . $response);
            return;
        }

        // What the client described, before standardization overwrites it —
        // the relocation gate below compares Google's match against these.
        $sentStreet = $this->line1;
        $sentCity = $this->locality;

        /*--
        Accept building-level matches — a human can physically find those —
        and, in the US, below-building matches that USPS itself confirms as a
        delivery point (dpvConfirms: dpv Y/D/S with a complete CASS block). A
        street Google's road data doesn't know can still be a fully
        USPS-confirmed address: "1 Helmsley Pl, Exeter RI" validates at ROUTE
        granularity with dpv=Y and a complete CASS block (OPEN-ITEMS I16), and
        rejecting it left that address with no storable correct form at all.
        On the dpv path parseValidatedAddress() takes CASS's street rendering
        wholesale, since ROUTE granularity is Google saying it could not
        parse the line to a premise.

        Outside the US there is no second opinion, so ROUTE and OTHER are
        refused: Fuller's unnumbered "Chiswick Lane South" and Guinness's
        "St James's Gate" both come back below premise level (fixtures GB1,
        IE1) and are the price of the rule.
        --*/
        if(!self::findable($result, $isUS)){
            // Not a findable place
            $this->error = true;
            $this->errorMsg = $isUS
                ? 'We were not able to find a location based on the address you provided. Please double check the street, city, and ZIP code.'
                : 'We were not able to find a location based on the address you provided. Please double check the street, city, and postal code.';
            $this->responseCode = 400;
            $this->logError(267, 'Address Not Found - Google Address Validation', 'Granularity: ' . $granularity . ' // ' . $requestData . ' // Response: ' . $response);
            return;
        }

        /*--
        Relocation gate: a 200 must never move the venue (OPEN-ITEMS I15,
        I21). Google returns a well-formed, plausible match even when it has
        matched a DIFFERENT street ("1158 Broadway" -> "909 E Union St", the
        house number reinterpreted as a unit) or a different city 100 miles
        away ("5054 N US Highway 31, Peru IN" -> Columbus IN, the sent city
        REPLACED). Both shapes are detectable from the response; storing them
        poisons `GET /location/zip` with a venue that is not there, which is
        worse than any 400. The one legitimate locality replacement — USPS
        relabeling the same building's mailing city ("San Francisco" ->
        "Presidio", dpv=Y on the exact street sent) — passes, verified
        against all 587 audited production addresses. The same two prongs
        hold abroad: without CASS the house-number prong only fires when the
        number leads the line (French, British, Luxembourgish order), and a
        replaced locality always rejects.
        --*/
        $conflict = self::relocationConflict($result, $sentStreet);
        if($conflict !== null){
            $this->error = true;
            $this->errorMsg = 'The closest match we could find is on a different ' . $conflict['type'] . ' — ' . $conflict['found'] . ' — so we haven\'t saved it, rather than silently relocate this venue. Please double check the address.'
                . ($isUS ? ' For a rural route or numbered highway, try zip5 with a short street form (e.g. "5054 N US 31").' : '');
            $this->responseCode = 400;
            $blame = $conflict['type'] === 'street' ? 'line1' : 'locality';
            $this->flag($blame, 'invalid', $this->errorMsg);
            $this->logError($conflict['type'] === 'street' ? 310 : 311, 'Address match relocated the ' . $conflict['type'] . ' - Google Address Validation', 'Found: ' . $conflict['found'] . ' // ' . $requestData . ' // Response: ' . $response);
            return;
        }

        // ----- Accepted: populate standardized fields -----
        if($isUS){
            $this->populateUS($result, $sentCity, $requestData, $response);
        }else{
            $this->populateInternational($result, $requestData, $response);
        }
        if($this->error){
            return;
        }
        $this->validatedAt = time();

        // Capture coordinates from the same response (no separate geocode call needed)
        if(isset($geo['latitude']) && isset($geo['longitude'])){
            $this->latitude = $geo['latitude'];
            $this->longitude = $geo['longitude'];
            $this->latLongFound = true;
        }
    }

    /* The US population path, verbatim from USAddresses apart from the
       final fold of zip5/zip4 and city into the shared columns. */
    private function populateUS($result, $sentCity, $requestData, $response){
        $uspsData = $result['uspsData'] ?? array();
        $std = $uspsData['standardizedAddress'] ?? array();
        $postal = $result['address']['postalAddress'] ?? array();

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
            // City: USPS's mailing city, un-abbreviated. The full derivation
            // and its evidence live on deriveCity() — the short version is
            // that CASS abbreviates ANY city name longer than 13 characters
            // ("COLORADO SPGS", "LONG IS CITY", "P C BEACH"), so the result
            // length says nothing, and the old strlen<13 branch stored the
            // abbreviations (OPEN-ITEMS I17) and once even swapped in a
            // different post office's name (I20).
            $this->city = self::deriveCity($result, $sentCity);
            /*--
            A premise-level verdict does not guarantee a complete CASS block.
            When USPS does not recognize the submitted community as a mailing
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
            $this->city = self::smartCase($postal['locality'] ?? '');
            $this->stateShort = strval($postal['administrativeArea'] ?? '');
            $this->zip5 = $this->padZip($postalZip5, 5);
            $this->zip4 = $this->padZip($postalZip4, 4);
        }

        // Derive sub_code and state_long
        if(!empty($this->stateShort) && (empty($this->sub_code) || empty($this->stateLong) || $this->sub_code !== 'US-' . $this->stateShort)){
            $subdivisions = new Subdivisions();
            $sub_code = 'US-' . $this->stateShort;
            if($subdivisions->validate($sub_code, true)){
                $this->sub_code = $subdivisions->sub_code;
                $this->stateLong = $subdivisions->sub_name;
                $this->subName = $subdivisions->sub_name;
                $this->subType = $subdivisions->subType;
            }
        }

        /*--
        Completeness gate. Everything below feeds columns with hard schema
        constraints — the US postal code has a CHECK for five digits, sub_code
        a foreign key into subdivisions and a CHECK that a US row has one — so
        an address still incomplete after the backfill above has to stop here
        as a 400 the client can act on, not surface as the database's own 500
        with every valid_state key null.
        --*/
        if(!preg_match('/^[0-9]{5}$/', $this->zip5) || empty($this->sub_code) || empty($this->city) || empty($this->address2)){
            $this->error = true;
            $this->errorMsg = 'We found this location but could not fully standardize its mailing address. If the community is not a USPS mailing city (common for unincorporated places), try the city its post office uses, or provide the ZIP code.';
            $this->responseCode = 400;
            // Blame the field the client can most usefully change.
            $blame = !preg_match('/^[0-9]{5}$/', $this->zip5) ? 'postal_code' : (empty($this->sub_code) ? 'sub_code' : (empty($this->city) ? 'locality' : 'line1'));
            $this->flag($blame, 'invalid', $this->errorMsg);
            // Log Error — badData carries the raw response, same as 267, so
            // these edge-case addresses are diagnosable without a repro.
            $this->logError(303, 'Address standardization incomplete after postalAddress backfill', $requestData . ' // Response: ' . $response);
            return;
        }

        // Fold into the shared columns
        $this->syncLegacy('legacy');
        $this->dependentLocality = '';
        $this->postalCode = $this->zip4 !== '' ? $this->zip5 . '-' . $this->zip4 : $this->zip5;
        $this->languageCode = 'en';
        $this->formatted = self::formatUS($this->line1, $this->line2, $this->locality, $this->stateShort, $this->postalCode);
    }

    /* Display lines for a US address, the same rendering the migration gave
       the copied rows: street, unit on its own line when present, then
       "City, ST 97201-1234". */
    public static function formatUS($line1, $line2, $locality, $stateShort, $postalCode){
        $lines = array($line1);
        if($line2 !== '' && $line2 !== null){ $lines[] = $line2; }
        $lines[] = trim($locality . ', ' . $stateShort . ' ' . $postalCode);
        return $lines;
    }

    private function populateInternational($result, $requestData, $response){
        $parsed = self::parseInternational($result);
        $this->line1 = $parsed['line1'];
        $this->line2 = $parsed['line2'];
        $this->locality = $parsed['locality'];
        $this->dependentLocality = $parsed['dependent_locality'];
        $this->postalCode = $parsed['postal_code'];
        $this->languageCode = $parsed['language_code'];
        $this->formatted = $parsed['formatted'];
        $this->syncLegacy('new');
        $this->stateShort = '';
        $this->stateLong = '';
        $this->zip5 = '';
        $this->zip4 = '';

        // Subdivision: Google's administrative areas, matched against the
        // seeded ISO rows for this country (code suffix or name). Google's
        // answer wins over a client-sent sub_code; no match keeps the
        // client's validated code; neither is NULL, never a 400 -- Belgium
        // returns no province at all, and that is fine.
        if(!empty($parsed['admin_areas'])){
            $found = Subdivisions::lookup($this->countryCode, $parsed['admin_areas']);
            if($found !== null){
                $this->sub_code = $found['sub_code'];
                $this->subName = $found['sub_name'];
                $this->subType = $found['sub_type'];
            }
        }

        // Completeness: the two NOT NULL columns.
        if($this->line1 === '' || $this->locality === ''){
            $this->error = true;
            $this->errorMsg = 'We found this location but could not read a street line and a city or town from the validated address. Please double check the address, or provide the postal code.';
            $this->responseCode = 400;
            $this->flag($this->line1 === '' ? 'line1' : 'locality', 'invalid', $this->errorMsg);
            $this->logError(340, 'International address incomplete after validation', $requestData . ' // Response: ' . $response);
        }
    }

    /*--
    Parse a non-US Google Address Validation result. Pure — no DB, no
    logging — replayed by tests/address-parse.php against the international
    fixtures. Returns line1, line2, locality, dependent_locality,
    postal_code, language_code, formatted (array of lines) and admin_areas
    (the texts to try against subdivisions, most specific first).

    Google is the source of truth here, so the job is choosing WHICH of its
    renderings to keep (every rule below is anchored to a captured fixture):

      line1      postalAddress.addressLines[0] -- the one line Google writes
                 in the country's own order ("Donkerstraat 12" vs "28 Rue de
                 la Goutte d'Or") -- with the POSTAL abbreviation undone from
                 the route component: the postal line says "Köpenicker Str.
                 70", "2 Bd J.F. Kennedy", "Av. P.º de la Reforma 222" while
                 formattedAddress and the route spell them out (DE2, LU1,
                 MX1). Full form, like the US convention of spelling names.
      line2      the subpremise component, cut out of line1 when Google folded
                 it in ("Meir 85/bus 3" -> "Meir 85" + "bus 3", BE6); an
                 unconfirmed unit on an unconfirmed route is a parse artifact
                 (the US rule, kept). A second addressLines entry is line2 too.
      locality   postalAddress.locality -- Google has already decided between
                 the municipality and the village (Westvleteren vs Vleteren,
                 BE1/BE2) and between locality and postal_town (London, GB1).
      dependent  postalAddress.sublocality (Cuauhtémoc, MX1); else a
                 sublocality_level_1 component that differs from the locality.
      formatted  formattedAddress split on ", ", the country segment dropped
                 (the reader has country_short_name). When the expanded line1
                 leads it, the split starts after it, which keeps a street
                 whose own rendering contains a comma whole ("Carrer de
                 Pujades, 95", ES1). Japanese has no ", " and leads with the
                 country ("日本、〒100-0005 ..."): one line, country stripped.
    --*/
    public static function parseInternational($result){
        $address = $result['address'] ?? array();
        $postal = $address['postalAddress'] ?? array();
        $granularity = $result['verdict']['validationGranularity'] ?? '';

        $comp = array();
        $conf = array();
        foreach(($address['addressComponents'] ?? array()) as $c){
            $type = $c['componentType'] ?? '';
            $text = trim($c['componentName']['text'] ?? '');
            if($type !== '' && $text !== '' && !isset($comp[$type])){
                $comp[$type] = $text;
                $conf[$type] = $c['confirmationLevel'] ?? '';
            }
        }

        $lines = array_values(array_filter(array_map('trim', $postal['addressLines'] ?? array()), 'strlen'));
        $line1 = $lines[0] ?? '';
        $line2 = $lines[1] ?? '';

        if(isset($comp['route'])){
            $line1 = self::expandPostalLine($line1, $comp['route']);
        }

        $unit = $comp['subpremise'] ?? '';
        if($unit !== '' && ($conf['subpremise'] ?? '') === 'UNCONFIRMED_BUT_PLAUSIBLE'
            && ($conf['route'] ?? '') !== 'CONFIRMED' && $granularity !== 'SUB_PREMISE'){
            $unit = '';
        }
        if($unit !== ''){
            $pos = mb_stripos($line1, $unit);
            if($pos !== false){
                // Cut the unit and the separator Google used to fold it in.
                $line1 = trim(mb_substr($line1, 0, $pos) . mb_substr($line1, $pos + mb_strlen($unit)));
                $line1 = trim(preg_replace('/[\s\/,\-]+$/u', '', $line1));
                $line1 = trim(preg_replace('/^[\s\/,\-]+/u', '', $line1));
            }
            if($line2 === ''){ $line2 = $unit; }
        }

        $locality = trim($postal['locality'] ?? '');
        if($locality === ''){
            $locality = $comp['locality'] ?? ($comp['postal_town'] ?? ($comp['sublocality_level_1'] ?? ''));
        }
        $dependent = trim($postal['sublocality'] ?? '');
        if($dependent === '' && isset($comp['sublocality_level_1']) && mb_strtolower($comp['sublocality_level_1']) !== mb_strtolower($locality)){
            $dependent = $comp['sublocality_level_1'];
        }

        // formatted
        $formattedAddress = trim($address['formattedAddress'] ?? '');
        $country = $comp['country'] ?? '';
        if($country !== ''){
            if(mb_substr($formattedAddress, -mb_strlen(', ' . $country)) === ', ' . $country){
                $formattedAddress = mb_substr($formattedAddress, 0, -mb_strlen(', ' . $country));
            }elseif(mb_substr($formattedAddress, 0, mb_strlen($country . '、')) === $country . '、'){
                $formattedAddress = mb_substr($formattedAddress, mb_strlen($country . '、'));
            }
        }
        $firstLine = $lines[0] ?? '';
        if(isset($comp['route'])){ $firstLine = self::expandPostalLine($firstLine, $comp['route']); }
        if($firstLine !== '' && mb_substr($formattedAddress, 0, mb_strlen($firstLine . ', ')) === $firstLine . ', '){
            $formatted = array_merge(array($firstLine), explode(', ', mb_substr($formattedAddress, mb_strlen($firstLine . ', '))));
        }elseif(strpos($formattedAddress, ', ') !== false){
            $formatted = explode(', ', $formattedAddress);
        }else{
            $formatted = $formattedAddress === '' ? array() : array($formattedAddress);
        }
        $formatted = array_values(array_filter(array_map('trim', $formatted), 'strlen'));
        // "Chambly, QC J3L 2H6": the comma inside a North-American locality
        // line is Google's, not a line break. Rejoin a bare locality segment
        // with the postal-code segment that follows it (CA1).
        $postalCode = trim($postal['postalCode'] ?? ($comp['postal_code'] ?? ''));
        for($i = 1; $i + 1 < count($formatted); $i++){
            if($locality !== '' && mb_strtolower($formatted[$i]) === mb_strtolower($locality) && $postalCode !== '' && mb_strpos($formatted[$i + 1], $postalCode) !== false){
                array_splice($formatted, $i, 2, array($formatted[$i] . ', ' . $formatted[$i + 1]));
                break;
            }
        }

        // Administrative areas to try against the seeded subdivisions, most
        // specific level first where Google gives several (IT: MI then
        // Lombardia). postalAddress.administrativeArea is the short form
        // Google itself would print (QC, VIC, "Co. Dublin").
        $adminAreas = array();
        foreach(array('administrative_area_level_1', 'administrative_area_level_2') as $t){
            if(isset($comp[$t])){ $adminAreas[] = $comp[$t]; }
        }
        if(!empty($postal['administrativeArea'])){ $adminAreas[] = trim($postal['administrativeArea']); }
        // "County Dublin" / "Co. Dublin" -> "Dublin"; "Région de X" is left,
        // the seed carries that form.
        foreach($adminAreas as $a){
            $stripped = preg_replace('/^(County|Co\.)\s+/i', '', $a);
            if($stripped !== $a){ $adminAreas[] = $stripped; }
        }
        $adminAreas = array_values(array_unique($adminAreas));

        return array(
            'line1' => $line1,
            'line2' => $line2,
            'locality' => $locality,
            'dependent_locality' => $dependent,
            'postal_code' => trim($postal['postalCode'] ?? ($comp['postal_code'] ?? '')),
            'language_code' => trim($postal['languageCode'] ?? ''),
            'formatted' => $formatted,
            'admin_areas' => $adminAreas,
        );
    }

    /*--
    Undo the postal abbreviation of the route inside a postal address line.
    Google's addressLines use each country's postal short forms ("Str.",
    "Bd", "Av.", "P.º", "St") while its route component is spelled out. Find
    the window of line tokens that reads as the route -- each token equal to
    its counterpart, or a same-first-letter subsequence of it (Str ⊂ Straße,
    Bd ⊂ Boulevard, P ⊂ Paseo) -- and replace that window with the route,
    keeping any punctuation that trailed it ("Carrer de Pujades," keeps its
    comma). No window: the line is returned as it came. Pure.
    --*/
    public static function expandPostalLine($line, $routeFull){
        $routeFull = trim($routeFull ?? '');
        $line = trim($line ?? '');
        if($routeFull === '' || $line === ''){ return $line; }
        $rt = preg_split('/\s+/u', $routeFull, -1, PREG_SPLIT_NO_EMPTY);
        $lt = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($rt);
        if($n === 0 || count($lt) < $n){ return $line; }
        // Letters and digits only: dots, commas and the Spanish ordinal
        // indicators ("P.º", "1ª") are punctuation here, not letters.
        $letters = function($s){ return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+|[ºª]/u', '', $s)); };
        for($start = 0; $start + $n <= count($lt); $start++){
            $match = true;
            $changed = false;
            for($i = 0; $i < $n; $i++){
                $a = $letters($lt[$start + $i]);
                $b = $letters($rt[$i]);
                if($a === $b){ continue; }
                if($a === '' || $b === '' || mb_strlen($a) >= mb_strlen($b) || mb_substr($a, 0, 1) !== mb_substr($b, 0, 1)){ $match = false; break; }
                $pos = 0;
                for($j = 0, $len = mb_strlen($a); $j < $len; $j++){
                    $pos = mb_strpos($b, mb_substr($a, $j, 1), $pos);
                    if($pos === false){ $match = false; break 2; }
                    $pos++;
                }
                $changed = true;
            }
            if($match){
                if(!$changed){ return $line; }
                // Trailing punctuation on the last window token survives when
                // that token was spelled out already ("Pujades," keeps its
                // comma); an abbreviation's own dot ("Str.") goes with it.
                $trail = '';
                $lastTok = $lt[$start + $n - 1];
                if($letters($lastTok) === $letters($rt[$n - 1]) && preg_match('/([^\p{L}\p{N}]+)$/u', $lastTok, $m)){ $trail = $m[1]; }
                $replaced = array_merge(array_slice($lt, 0, $start), array($routeFull . $trail), array_slice($lt, $start + $n));
                return implode(' ', $replaced);
            }
        }
        return $line;
    }

    /* The acceptance gate, pure so tests/address-parse.php can replay it:
       building-level granularity, or (US only) a USPS DPV confirmation. */
    public static function findable($result, $isUS){
        $granularity = $result['verdict']['validationGranularity'] ?? '';
        $buildingLevel = array('SUB_PREMISE', 'PREMISE', 'PREMISE_PROXIMITY');
        return in_array($granularity, $buildingLevel, true) || ($isUS && self::dpvConfirms($result));
    }

    // USPS secondary-unit designators per Publication 28, Appendix C2. Used
    // to cut CASS's unit rendering out of its own folded line — and ONLY
    // there, anchored to an identifier Google already confirmed. This
    // dictionary never decides whether a unit exists; Google's subpremise
    // component does. (Its predecessor, splitSecondaryUnit(), guessed
    // structure from rendered text and produced the I10 bug family.)
    private const SECONDARY_DESIGNATORS = 'APT|BSMT|BLDG|DEPT|FL|FRNT|HNGR|KEY|LBBY|LOT|LOWR|OFC|PH|PIER|REAR|RM|SIDE|SLIP|SPC|STOP|STE|TRLR|UNIT|UPPR';

    /*--
    USPS city-name contractions (Publication 28 / observed CASS output), for
    un-abbreviating a mailing city when no source in the response carries the
    full form. Every entry is either witnessed in the production audit corpus
    (COLORADO SPGS, BLK RIVER FLS, LONG IS CITY, GT BARRINGTON, ELK GROVE
    VLG, MOUNTLAKE TER, MT PLEASANT, SALT LAKE CTY, LK HAVASU CTY...) or a
    Pub 28 standard. Tokens NOT here stay as CASS wrote them — expanding is
    only safe from a closed vocabulary; guessing at squeezed words
    ("HUNTINGTN") is not, and those cases are covered instead by the
    full-form candidates in deriveCity().
    --*/
    private const CITY_TOKEN_EXPANSIONS = array(
        'SPGS'=>'Springs', 'SPG'=>'Spring', 'FLS'=>'Falls', 'VLG'=>'Village',
        'VLY'=>'Valley', 'HTS'=>'Heights', 'JCT'=>'Junction', 'BCH'=>'Beach',
        'CTY'=>'City', 'CTR'=>'Center', 'TER'=>'Terrace', 'MT'=>'Mount',
        'MTN'=>'Mountain', 'IS'=>'Island', 'LK'=>'Lake', 'GT'=>'Great',
        'BLK'=>'Black', 'PRT'=>'Port', 'FT'=>'Fort', 'PT'=>'Point',
        'HBR'=>'Harbor', 'TWP'=>'Township',
    );

    // Single-letter directionals expand only as a city name's FIRST token
    // ("N CHARLESTON", "S EL MONTE", "E FALMOUTH") — anywhere else a bare
    // letter is initials ("P C BEACH") and guessing would corrupt it.
    private const CITY_LEADING_DIRECTIONALS = array(
        'N'=>'North', 'S'=>'South', 'E'=>'East', 'W'=>'West',
    );

    // USPS confirms this exact delivery point: dpv Y (deliverable), D
    // (deliverable, secondary missing) or S (deliverable, secondary ignored),
    // with a CASS block complete enough to store (city + ZIP).
    public static function dpvConfirms($result){
        $usps = $result['uspsData'] ?? array();
        $std = $usps['standardizedAddress'] ?? array();
        return in_array($usps['dpvConfirmation'] ?? '', array('Y', 'D', 'S'), true)
            && !empty($std['zipCode']) && !empty($std['city']);
    }

    /*--
    Does Google's match describe the place the client described? Returns null
    when it does, else array(type => 'street'|'city', found => what Google
    matched instead) for the 400. Pure — replayed by tests/address-parse.php.

    Two prongs, each anchored to a real relocation (2026-08-17 captures):

    1. The sent house number must survive as the match's street number.
       "1158 Broadway, 98122" comes back as street 909 E UNION ST with
       subpremise "#1158" — the number became a unit on a different street
       (I15). Checked against the street_number component OR CASS's first
       line (Google parses "1 Helmsley Pl" as an unnumbered route while CASS
       carries the 1 — that must pass). Skipped when Google produced no
       street at all: that is the unparsed-street shape, handled by the
       passthrough in parseValidatedAddress(), not a relocation.
    2. A REPLACED route or locality component. Locality replacement alone is
       legitimate when USPS relabels the same building's mailing city
       ("San Francisco" -> "Presidio", dpv=Y on the exact street sent), so it
       only rejects without dpvConfirms(); with no USPS corroboration it is
       "5054 N US Highway 31, Peru IN" -> Columbus, 100 miles away (I21).
       Zero false positives across the 587-address audit corpus.
    --*/
    public static function relocationConflict($result, $sentStreet){
        $comps = array();
        foreach(($result['address']['addressComponents'] ?? array()) as $c){
            $t = $c['componentType'] ?? '';
            if($t !== '' && !isset($comps[$t])){ $comps[$t] = $c; }
        }
        $std = $result['uspsData']['standardizedAddress'] ?? array();
        $cassFirst = strval($std['firstAddressLine'] ?? '');

        $hasStreet = isset($comps['street_number']) || isset($comps['route']);
        if($hasStreet && preg_match('/^(\d+)[\s-]/', trim($sentStreet ?? ''), $m)){
            $foundNumber = trim($comps['street_number']['componentName']['text'] ?? '');
            $numberSurvives = ($foundNumber !== '' && strpos($foundNumber, $m[1]) === 0)
                || ($cassFirst !== '' && strpos($cassFirst, $m[1]) === 0);
            if(!$numberSurvives){
                $found = $cassFirst !== '' ? $cassFirst
                    : trim($foundNumber . ' ' . ($comps['route']['componentName']['text'] ?? ''));
                return array('type' => 'street', 'found' => self::smartCase($found));
            }
        }

        if(!empty($comps['route']['replaced'])){
            $found = $cassFirst !== '' ? $cassFirst : strval($comps['route']['componentName']['text'] ?? '');
            return array('type' => 'street', 'found' => self::smartCase($found));
        }

        if(!empty($comps['locality']['replaced']) && !self::dpvConfirms($result)){
            $foundCity = strval($comps['locality']['componentName']['text'] ?? '');
            $foundZip = strval($result['address']['postalAddress']['postalCode'] ?? '');
            return array('type' => 'city', 'found' => trim($foundCity . ' ' . $foundZip));
        }

        return null;
    }

    /*--
    Derive the stored city. Pure — replayed by tests/address-parse.php.

    The authority is CASS's mailing city, but CASS abbreviates ANY name
    longer than its 13-character field ("COLORADO SPGS", "LONG IS CITY",
    "HUNTINGTN BCH", "P C BEACH") — so the abbreviated result can be any
    length, and nothing in the block says whether abbreviation happened. The
    old strlen<13 branch was built on the opposite assumption and stored the
    abbreviations (I17); worse, its "fallback" to Google's locality replaced
    an exactly-13-char CORRECT city with a different post office's name
    ("EAST FALMOUTH" -> Google's municipality "Falmouth", I20).

    So, in order:
    1. CASS didn't standardize (no ZIP in the block) or has no city: its city
       is at best an echo of the client's input, and Google's locality holds
       the real mailing city ("Paoli" -> "Belleville"). Unchanged behavior.
    2. A candidate carries the unabbreviated form of the SAME name — Google's
       locality/sublocality/neighborhood, or the client's own sent city
       (postal.locality is NOT reliable for this: on zip5-only requests it
       often just echoes CASS's abbreviation). cityExpands() accepts a
       candidate only when it expands CASS's tokens one-for-one, so
       "Bonner-West Riverside" never replaces BONNER and "Falmouth" never
       replaces EAST FALMOUTH.
    3. No full-form candidate: expand CASS's tokens from the closed USPS
       vocabulary (COLORADO SPGS -> Colorado Springs even when every source
       in the response echoes the abbreviation).
    4. Nothing to expand: keep a same-name candidate's styling if one exists
       ("Sault Ste. Marie" keeps Google's period), else CASS's own text.
    --*/
    public static function deriveCity($result, $sentCity = ''){
        $std = $result['uspsData']['standardizedAddress'] ?? array();
        $postal = $result['address']['postalAddress'] ?? array();
        $stdCity = strval($std['city'] ?? '');

        if(empty($std['zipCode']) || $stdCity === ''){
            return self::smartCase(strval($postal['locality'] ?? $stdCity));
        }

        $candidates = array(strval($postal['locality'] ?? ''));
        foreach(($result['address']['addressComponents'] ?? array()) as $c){
            if(in_array($c['componentType'] ?? '', array('locality', 'sublocality_level_1', 'neighborhood'), true)){
                $candidates[] = strval($c['componentName']['text'] ?? '');
            }
        }
        $candidates[] = strval($sentCity ?? '');

        foreach($candidates as $candidate){
            if(self::cityExpands($stdCity, $candidate)){
                return self::smartCase($candidate);
            }
        }

        $expanded = self::expandCityTokens($stdCity);
        if($expanded !== null){
            return self::smartCase($expanded);
        }

        foreach($candidates as $candidate){
            if($candidate !== '' && self::sameCityTokens($stdCity, $candidate)){
                return self::smartCase($candidate);
            }
        }
        return self::smartCase($stdCity);
    }

    // True when $candidate is the SAME city name with at least one of
    // CASS's tokens un-abbreviated: token counts match, and every CASS token
    // equals its counterpart or is a shorter same-first-letter subsequence
    // of it (SPGS ⊂ SPRINGS, IS ⊂ ISLAND, KNG ⊂ KING, P ⊂ PANAMA).
    private static function cityExpands($cassCity, $candidate){
        $ct = preg_split('/[^A-Z0-9]+/', strtoupper(trim($cassCity)), -1, PREG_SPLIT_NO_EMPTY);
        $kt = preg_split('/[^A-Z0-9]+/', strtoupper(trim($candidate ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        if(empty($ct) || count($ct) !== count($kt)){ return false; }
        $expandedAny = false;
        foreach($ct as $i => $c){
            $k = $kt[$i];
            if($c === $k){ continue; }
            if(strlen($c) >= strlen($k) || $c[0] !== $k[0]){ return false; }
            $pos = 0;
            for($j = 0, $len = strlen($c); $j < $len; $j++){
                $pos = strpos($k, $c[$j], $pos);
                if($pos === false){ return false; }
                $pos++;
            }
            $expandedAny = true;
        }
        return $expandedAny;
    }

    private static function sameCityTokens($a, $b){
        return preg_split('/[^A-Z0-9]+/', strtoupper(trim($a)), -1, PREG_SPLIT_NO_EMPTY)
            === preg_split('/[^A-Z0-9]+/', strtoupper(trim($b)), -1, PREG_SPLIT_NO_EMPTY);
    }

    // Expand CASS's abbreviations from the closed vocabularies above.
    // Returns the expanded string, or null when no token changed.
    private static function expandCityTokens($cassCity){
        $tokens = preg_split('/\s+/', strtoupper(trim($cassCity)), -1, PREG_SPLIT_NO_EMPTY);
        $changed = false;
        foreach($tokens as $i => $t){
            if($i === 0 && count($tokens) > 1 && isset(self::CITY_LEADING_DIRECTIONALS[$t])){
                $tokens[$i] = self::CITY_LEADING_DIRECTIONALS[$t];
                $changed = true;
            }elseif(isset(self::CITY_TOKEN_EXPANSIONS[$t])){
                $tokens[$i] = self::CITY_TOKEN_EXPANSIONS[$t];
                $changed = true;
            }
        }
        return $changed ? implode(' ', $tokens) : null;
    }

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

        /*--
        Below building level (reachable only via the dpvConfirms() acceptance
        path): ROUTE granularity is Google saying it could not parse the line
        to a premise, so its components are not trustworthy structure — "1
        Helmsley Pl" comes back with the whole line as an unnumbered route.
        USPS confirmed the delivery point, so USPS's rendering is the street.
        --*/
        $granularity = $result['verdict']['validationGranularity'] ?? '';
        if(!in_array($granularity, array('SUB_PREMISE', 'PREMISE', 'PREMISE_PROXIMITY'))
            && !empty($std['firstAddressLine'])){
            $cassUnit = trim($std['secondAddressLine'] ?? '');
            return array(
                'address2' => self::apAbbreviate(self::smartCase($std['firstAddressLine'])),
                'address1' => $cassUnit !== '' ? self::unitCase($cassUnit) : '',
            );
        }

        /*--
        Google parsed NO street at all — no street_number, no route, no
        post_box; the entire line landed in subpremise ("1 Hemsley Place" on
        a street CASS doesn't know, OPEN-ITEMS I16). Any split of that line
        is invented structure — the dictionary path below once turned it into
        address2 "1 Hemsley" + address1 "Place", a street that doesn't exist.
        Pass the line through as the street and derive no unit from it.
        --*/
        if($number === '' && $route === '' && $postBox === ''){
            $line = trim(strval($std['firstAddressLine'] ?? $unitG));
            if($line !== ''){
                return array(
                    'address2' => self::apAbbreviate(self::smartCase($line)),
                    'address1' => '',
                );
            }
        }

        // Grid street numbers (Wisconsin's "N71W13040") reach Google in the
        // client's casing; CASS canonicalizes them.
        if($number !== '' && preg_match('/[A-Za-z]/', $number) && !empty($std['firstAddressLine'])
            && stripos($std['firstAddressLine'], strtoupper($number)) === 0){
            $number = strtoupper($number);
        }

        // The unit identifier survives every rendering: "Suite 12", "BLDG 12"
        // and "#12" all end in "12". It anchors the cuts below. A zero-padded
        // number is the one exception — CASS strips the padding ("Suite 001"
        // renders as "STE 1"), so the anchor pattern accepts both spellings;
        // without that the anchor missed, the raw padded string fell through
        // to address1 verbatim AND the street kept the folded unit (I14).
        $unitId = '';
        $unitIdPattern = '';
        if($unitG !== '' && preg_match('/([A-Za-z0-9][A-Za-z0-9\-\/]*)\s*$/', $unitG, $m)){ $unitId = $m[1]; }
        if($unitId !== ''){
            $unitIdPattern = preg_quote($unitId, '/');
            $unpadded = ltrim($unitId, '0');
            if(ctype_digit($unitId) && $unpadded !== '' && $unpadded !== $unitId){
                $unitIdPattern = '(?:' . $unitIdPattern . '|' . preg_quote($unpadded, '/') . ')';
            }
        }

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
                $re = '/\s+(?:(?:' . self::SECONDARY_DESIGNATORS . ')\s+)?#?\s*' . $unitIdPattern . '$/i';
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
                || preg_match('/(?:^|\s)' . preg_quote($number, '/') . '(?:\s|$)/i', $std['firstAddressLine']))){
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
            3. A different SKELETON — the directionals and street type, which
               is the structure USPS owns and Google's road data regularly
               disagrees with. See streetSkeleton().

            What is left for Google is the spelling of the name itself, which
            is what it is genuinely better at: "COMRCL CTR BLVD" ->
            "Commercial Center Boulevard", "SUB ZERO" -> "Sub-Zero", "ALY" ->
            "Alley", "MCMURRAY" -> "McMurray", "KALAKAUA" -> "Kalākaua".
            --*/
            $isDesignation = $route !== '' && preg_match('/(?:^|\s)\d+(?:\s|$)/', $route);
            if($cassStreet !== '' && ($isDesignation || $googleStreet === ''
                || self::isTokenPrefix($googleStreet, $cassStreet)
                || self::streetStructureConflict($googleStreet, $cassStreet))){
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

        /*--
        RECOGNIZING a directional or type has to work on either spelling, and
        REWRITING only ever applies to the spelled-out one. Keeping those apart
        matters because this function sees both: Google's text spells words out
        ("North Avenue Northeast") while a CASS-sourced street arrives already
        abbreviated ("North Ave NE"). When recognition was spelled-only, the
        guard below could not see that "NE" was a directional, decided the
        leading "North" was not part of the name, and turned Atlanta's North
        Avenue into "N Ave NE" — the same street rendering two ways depending
        on which source won.
        --*/
        $isDir  = function($t){ return isset(self::DIRECTIONAL_CANON[strtoupper($t)]); };
        $isType = function($t){ return isset(self::STREET_TYPE_CANON[strtoupper($t)]); };

        // Directional + type + at most a trailing directional means the
        // directional IS the name ("680 North Avenue NE").
        $directionalIsName = $isType($tokens[2]) && ($n === 3 || ($n === 4 && $isDir($tokens[3])));
        if($n >= 4 && $isDir($tokens[1]) && !$directionalIsName && isset($DIR[$tokens[1]])){
            $tokens[1] = $DIR[$tokens[1]];
        }

        $last = $n - 1;
        if($isDir($tokens[$last])){
            if(isset($DIR[$tokens[$last]])){ $tokens[$last] = $DIR[$tokens[$last]]; }
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
    ABBREVIATE for display, while this one has to recognize types we
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

    private const DIRECTIONAL_CANON = array(
        'N'=>'N', 'NORTH'=>'N', 'S'=>'S', 'SOUTH'=>'S', 'E'=>'E', 'EAST'=>'E', 'W'=>'W', 'WEST'=>'W',
        'NE'=>'NE', 'NORTHEAST'=>'NE', 'NW'=>'NW', 'NORTHWEST'=>'NW',
        'SE'=>'SE', 'SOUTHEAST'=>'SE', 'SW'=>'SW', 'SOUTHWEST'=>'SW',
    );

    /*--
    The street's SKELETON: its directional and type tokens in order, each
    reduced to one canonical form, with the name words dropped.

        "980 East Northeast 4th Street"  ->  dirs [E, NE]  types [ST]
        "980 E 4TH ST"                   ->  dirs [E]      types [ST]

    This is the part USPS owns. Google is the better source for how a NAME is
    spelled — McDowell, AleSmith, Kalākaua, Sub-Zero, Three Notch'd, "Intl
    Arpt" -> "International Airport" — but its road data disagrees with USPS
    about structure often enough to matter, and always in a way that changes
    where mail goes: it drops the S from 18525 S Main St, adds an N to 1151
    Main St, and turns 980 E 4th St into "East Northeast 4th Street". CASS
    confirms delivery (dpv=Y) at its own rendering for every one of those.

    So: same skeleton means the two only disagree about spelling, and Google
    wins. A different skeleton is a structural disagreement, and USPS wins.
    Comparing skeletons rather than whole strings is what lets a hyphen or an
    expanded word through ("SUB ZERO PKWY" -> "Sub-Zero Pkwy" changes the
    token count but not the skeleton) while stopping a moved directional.
    --*/
    private static function streetSkeleton($street){
        $dirs = array(); $types = array();
        foreach(preg_split('/\s+/', strtoupper(trim($street ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $token){
            $token = trim($token, '.,');
            if(isset(self::DIRECTIONAL_CANON[$token])){ $dirs[] = self::DIRECTIONAL_CANON[$token]; }
            elseif(isset(self::STREET_TYPE_CANON[$token])){ $types[] = self::STREET_TYPE_CANON[$token]; }
        }
        return array($dirs, $types);
    }

    private static function streetStructureConflict($googleStreet, $cassStreet){
        list($gd, $gt) = self::streetSkeleton($googleStreet);
        list($cd, $ct) = self::streetSkeleton($cassStreet);
        return $gd !== $cd || $gt !== $ct;
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
            }elseif(preg_match('/^[A-Za-z]?\d+[A-Za-z]\d+[A-Za-z]?$/', $token)){
                // Grid house numbers (Wisconsin's "N71W13040", "W226N6339")
                // are letter-digit-letter-digit; title case turns them into
                // "N71w13040". Ordinals never match — they have no digit
                // AFTER the letters.
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


    // ----- Telephone -----

    /*--
    Normalize a submitted telephone number to E.164 using the location's
    country for the default calling code. Pure — replayed by
    tests/address-parse.php. Returns the "+..." string, or null when the
    input cannot be read as a number of that country.

      "+32 57 40 03 76"        -> +3257400376   (international form, any country)
      "0032 57 40 03 76"       -> +3257400376   (00 international prefix)
      "011 32 57 40 03 76"     -> +3257400376   (NANP international prefix)
      "057 40 03 76" from BE   -> +3257400376   (trunk 0 dropped)
      "02 1234 5678" from IT   -> +390212345678 (Italy keeps its 0)
      "(503) 555-0100" from US -> +15035550100
      "1-503-555-0100" from US -> +15035550100
    --*/
    public static function normalizeTelephone($input, $countryCode){
        $raw = trim(strval($input ?? ''));
        if($raw === ''){ return null; }
        $cc = strtoupper(trim(strval($countryCode ?? '')));
        $digitsOnly = preg_replace('/\D/', '', $raw);
        if($digitsOnly === ''){ return null; }

        $international = null;
        if($raw[0] === '+'){
            $international = $digitsOnly;
        }elseif(preg_match('/^00\d/', $digitsOnly)){
            $international = substr($digitsOnly, 2);
        }elseif(in_array($cc, self::NANP, true) && preg_match('/^011\d/', $digitsOnly)){
            $international = substr($digitsOnly, 3);
        }

        if($international === null){
            if(!isset(self::CALLING_CODES[$cc])){ return null; }
            $national = $digitsOnly;
            if(in_array($cc, self::NANP, true)){
                if(strlen($national) === 11 && $national[0] === '1'){ $national = substr($national, 1); }
                if(strlen($national) !== 10 || $national[0] === '0' || $national[0] === '1'){ return null; }
            }else{
                $trunk = self::TRUNK_PREFIX[$cc] ?? '0';
                if($trunk !== ''){
                    $national = preg_replace('/^(' . $trunk . ')/', '', $national, 1);
                }
            }
            $international = self::CALLING_CODES[$cc] . $national;
        }

        $e164 = '+' . $international;
        return preg_match('/^\+[1-9]\d{6,14}$/', $e164) ? $e164 : null;
    }

    private function validateTelephone(){
        $raw = trim(strval($this->telephone ?? ''));
        if($raw === ''){
            $this->telephone = null;
            return;
        }
        $e164 = self::normalizeTelephone($raw, $this->countryCode);
        if($e164 === null){
            // Can't read it
            $this->error = true;
            $this->validState['telephone'] = 'invalid';
            $this->validMsg['telephone'] = 'The telephone number you submitted appears to be invalid. Send it with its country code (+32 57 40 03 76, +1 503-555-0100) or in the national format for ' . (CountryCode::shortName($this->countryCode) ?? 'this country') . '.';
            $this->responseCode = 400;
            $this->logError(65, 'Invalid telephone number', "telephone: $raw / countryCode: " . $this->countryCode);
            $this->telephone = null;
        }elseif(Telephone::lengthKnown($e164) === false){
            // Readable, but not a length the number's own country issues: a
            // digit dropped or doubled. The check is only on length (see
            // Telephone::lengthKnown) so a real number is never refused by a
            // stale table.
            $parts = Telephone::parse($e164);
            $this->error = true;
            $this->validState['telephone'] = 'invalid';
            $this->validMsg['telephone'] = 'The telephone number you submitted has ' . strlen($parts['nsn']) . ' digits after the +' . $parts['cc'] . ' country code, which is not a length ' . (Telephone::countryName($e164) ?? 'that country') . ' issues. Please check it for a missing or extra digit.';
            $this->responseCode = 400;
            $this->logError(346, 'Telephone number of a length its country does not issue', "telephone: $raw / e164: $e164 / countryCode: " . $this->countryCode);
            $this->telephone = null;
        }else{
            $this->telephone = $e164;
        }
    }

    // ----- Read -----

    /* Populate from a location row's address columns (column names as in
       the table, plus the joined sub_name/sub_type). */
    public function loadRow($locationID, $row){
        $this->locationID = $locationID;
        $this->countryCode = strval($row['countryCode'] ?? '');
        $this->line1 = strval($row['line1'] ?? '');
        $this->line2 = strval($row['line2'] ?? '');
        $this->locality = strval($row['locality'] ?? '');
        $this->dependentLocality = strval($row['dependentLocality'] ?? '');
        $this->sub_code = strval($row['sub_code'] ?? '');
        $this->postalCode = strval($row['postalCode'] ?? '');
        $decoded = is_string($row['formatted'] ?? null) ? json_decode($row['formatted'], true) : null;
        $this->formatted = is_array($decoded) ? $decoded : null;
        $this->languageCode = strval($row['languageCode'] ?? '');
        $this->telephone = isset($row['telephone']) && $row['telephone'] !== '' ? strval($row['telephone']) : null;
        $this->validatedAt = isset($row['validatedAt']) ? intval($row['validatedAt']) : null;
        $this->subName = strval($row['sub_name'] ?? '');
        $this->subType = strval($row['sub_type'] ?? '');
        $this->syncLegacy('new');

        $this->stateShort = '';
        $this->stateLong = '';
        $this->zip5 = '';
        $this->zip4 = '';
        if(self::isUS($this->countryCode)){
            if($this->sub_code !== ''){
                $this->stateShort = substr($this->sub_code, 3, 2);
                $this->stateLong = $this->subName;
            }
            if(strpos($this->postalCode, '-') !== false){
                list($this->zip5, $this->zip4) = explode('-', $this->postalCode, 2);
            }else{
                $this->zip5 = $this->postalCode;
            }
        }
    }

    /*--
    The address object on the wire, built from a location row's address
    columns — the one shape builder for GET /location/{id},
    /brewer/{id}/locations, /location/nearby and the search object.
    --*/
    public static function wireObject($row){
        $a = new Address();
        $a->loadRow('', $row);
        return $a->toArray();
    }

    public function toArray(){
        $nullable = function($v){ return ($v === '' || $v === null) ? null : $v; };
        // A stored row always has a street and a locality; every key null
        // is the "no address" block the nearby list renders for a location
        // without one.
        return array(
            'line1' => $nullable($this->line1),
            'line2' => $nullable($this->line2),
            'locality' => $nullable($this->locality),
            'dependent_locality' => $nullable($this->dependentLocality),
            'sub_code' => $nullable($this->sub_code),
            'postal_code' => $nullable($this->postalCode),
            'formatted' => is_array($this->formatted) ? array_values($this->formatted) : null,
            'language_code' => $nullable($this->languageCode),
            'telephone' => $nullable($this->telephone),
            // The same number the way its country writes it, grouped from the
            // numbering plan (Telephone.class.php); the E.164 string when the
            // calling code is one we hold no plan for.
            'telephone_formatted' => $nullable($this->telephone) === null ? null : (Telephone::formatInternational($this->telephone) ?? $this->telephone),
            'telephone_national' => $nullable($this->telephone) === null ? null : (Telephone::formatNational($this->telephone) ?? $this->telephone),
            'admin_area' => $this->sub_code === '' ? null : array(
                'sub_code' => $this->sub_code,
                'name' => $nullable($this->subName),
                'type' => $nullable($this->subType),
            ),
        );
    }

    // ----- Reference lists -----

    /* GET /location/countries: the countries a location may be in, with
       whether the catalog holds subdivisions for each (so a form knows
       whether to show a region dropdown). */
    public function countries(){
        $db = new Database();
        $seeded = array();
        $result = $db->query("SELECT countryCode, COUNT(*) AS n FROM subdivisions GROUP BY countryCode");
        if($db->error){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = $db->responseCode;
            $db->close();
            return;
        }
        while($row = $result->fetch_assoc()){ $seeded[$row['countryCode']] = intval($row['n']); }
        $db->close();

        $data = array();
        foreach(self::COUNTRIES as $cc){
            $data[] = array(
                'country_code' => $cc,
                'country_short_name' => CountryCode::shortName($cc),
                'subdivisions' => $seeded[$cc] ?? 0,
                'calling_code' => '+' . self::CALLING_CODES[$cc],
            );
        }
        $this->json = array('object' => 'list', 'url' => '/location/countries', 'has_more' => false, 'data' => $data);
    }

    /* GET /location/subdivisions?country=BE: the seeded ISO 3166-2 rows for
       one country, in code order. */
    public function subdivisions($countryCode){
        $cc = CountryCode::normalize($countryCode);
        if($cc === null){
            $this->error = true;
            $this->errorMsg = 'country must be an ISO 3166-1 alpha-2 code (e.g. "BE").';
            $this->responseCode = 400;
            $this->logError(342, 'Invalid country for GET /location/subdivisions', strval($countryCode));
            return;
        }
        $subdivisions = new Subdivisions();
        $rows = $subdivisions->listForCountry($cc);
        if($subdivisions->error){
            $this->error = true;
            $this->errorMsg = $subdivisions->errorMsg;
            $this->responseCode = $subdivisions->responseCode;
            return;
        }
        $this->json = array('object' => 'list', 'url' => '/location/subdivisions', 'country_code' => $cc, 'has_more' => false, 'data' => $rows);
    }
}
?>
