<?php
/*--
ISO 3166-2 subdivisions: the `subdivisions` table, seeded for the 40 Google
Address Validation regions at the level Google reports as an administrative
area (catalog-beer-mysql/migrations/2026-10-13-international-addresses.sql).
Names are the local official form (West-Vlaanderen, Bayern); `countryCode`
says which country a code belongs to, `subType` is ISO's category (state,
province, region, canton, ...) and `parentCode` its ISO parent (a Belgian
province sits under a region).
--*/
class Subdivisions {

    public $sub_code = '';
    public $sub_name = '';
    public $countryCode = '';
    public $subType = '';
    public $parentCode = '';

    public $error = false;
    public $errorMsg = null;
    public $responseCode = 200;

    public function validate($sub_code, $saveToClass){
        // Valid
        $valid = false;

        // Trim
        $sub_code = trim($sub_code ?? '');

        if(!empty($sub_code)){
            // Query Database
            $db = new Database();
            $result = $db->query("SELECT sub_code, sub_name, countryCode, sub_type, parentCode FROM subdivisions WHERE sub_code=?", [$sub_code]);
            if(!$db->error){
                if($result->num_rows == 1){
                    // Valid
                    $valid = true;

                    if($saveToClass){
                        $array = $result->fetch_assoc();
                        $this->sub_code = $array['sub_code'];
                        $this->sub_name = $array['sub_name'];
                        $this->countryCode = $array['countryCode'];
                        $this->subType = strval($array['sub_type'] ?? '');
                        $this->parentCode = strval($array['parentCode'] ?? '');
                    }
                }elseif($result->num_rows > 1){
                    // Undesirable number of results
                    $this->error = true;
                    $this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
                    $this->responseCode = 500;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 56;
                    $errorLog->errorMsg = 'Duplicate sub_codes';
                    $errorLog->badData = "sub_code: $sub_code";
                    $errorLog->filename = 'API / Subdivisions.class.php';
                    $errorLog->write();
                }else{
                    // Not Found
                    // Subdivision Code Does Not Exist
                    $this->error = true;
                    $this->errorMsg = "Sorry, we couldn't find a subdivision with the sub_code you provided.";
                    $this->responseCode = 404;

                    // Log Error
                    $errorLog = new LogError();
                    $errorLog->errorNumber = 139;
                    $errorLog->errorMsg = 'sub_code Not Found';
                    $errorLog->badData = $sub_code;
                    $errorLog->filename = 'API / Subdivisions.class.php';
                    $errorLog->write();
                }
            }else{
                // Database Error
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = $db->responseCode;
            }
            $db->close();
        }else{
            // Missing Subdivision Code
            $this->error = true;
            $this->errorMsg = 'Sorry, we seem to be missing the subdivision code (sub_code) you\'re requesting';
            $this->responseCode = 400;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 55;
            $errorLog->errorMsg = 'Missing sub_code';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Subdivisions.class.php';
            $errorLog->write();
        }

        // Return
        return $valid;
    }

    /*--
    Match what Google reports as an administrative area against this
    country's seeded rows. Each candidate is tried in order as a bare ISO
    suffix (QC -> CA-QC, VIC -> AU-VIC, MI -> IT-MI) and as a name; the
    table's accent- and case-insensitive collation does the rest (Québec,
    "comunidad de madrid"). Returns the row (sub_code, sub_name, sub_type) or
    null when nothing matches -- the caller stores NULL, never a 400.
    --*/
    public static function lookup($countryCode, $candidates){
        $cc = strtoupper(trim(strval($countryCode ?? '')));
        if($cc === '' || empty($candidates)){ return null; }
        $db = new Database();
        $found = null;
        foreach($candidates as $text){
            $text = trim(strval($text ?? ''));
            if($text === ''){ continue; }
            $result = $db->query("SELECT sub_code, sub_name, sub_type FROM subdivisions WHERE countryCode=? AND (sub_code=? OR sub_name=?) ORDER BY sub_code LIMIT 1", [$cc, $cc . '-' . strtoupper($text), $text]);
            if($db->error){ break; }
            if($result->num_rows === 1){
                $found = $result->fetch_assoc();
                break;
            }
        }
        $db->close();
        return $found;
    }

    /* Does the catalog hold any subdivision rows for this country? */
    public static function countrySeeded($countryCode){
        $db = new Database();
        $result = $db->query("SELECT 1 FROM subdivisions WHERE countryCode=? LIMIT 1", [strtoupper(trim(strval($countryCode ?? '')))]);
        $seeded = !$db->error && $result->num_rows === 1;
        $db->close();
        return $seeded;
    }

    /* Every seeded row for one country, in code order, as wire objects. */
    public function listForCountry($countryCode){
        $rows = array();
        $db = new Database();
        $result = $db->query("SELECT sub_code, sub_name, sub_type, parentCode FROM subdivisions WHERE countryCode=? ORDER BY sub_code", [strtoupper(trim(strval($countryCode ?? '')))]);
        if($db->error){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = $db->responseCode;
        }else{
            while($row = $result->fetch_assoc()){
                $rows[] = array(
                    'sub_code' => $row['sub_code'],
                    'name' => $row['sub_name'],
                    'type' => $row['sub_type'],
                    'parent_code' => $row['parentCode'],
                );
            }
        }
        $db->close();
        return $rows;
    }
}
?>
