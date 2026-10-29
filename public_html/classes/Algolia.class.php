<?php
class Algolia {

    // Error Variables
    public $error = false;
    public $errorMsg = null;

    /*--
    The most bytes of prose a search object carries. Algolia refuses a record
    over 10 KB, and saveObject() logs that refusal (error 228) without failing
    the write that triggered it -- so a brewer or beer with a very long
    description was stored in the catalog and silently never indexed. The
    catalog allows a 65,535-byte description; a search object needs enough to
    match on and to snippet (30 words), not the whole essay. 3,000 bytes leaves
    room for a brewer's denormalised geography (_geoloc, cities, states) in
    the same record.
    --*/
    const PROSE_MAX_BYTES = 3000;

    /*--
    Cut $text to at most $maxBytes without splitting a UTF-8 sequence, then
    back to the last whitespace so the cut never lands mid-word (unless the
    text has no whitespace in the window, when a mid-word cut is the only
    one). Returns $text unchanged when it fits; null and '' pass through.
    No mbstring -- production does not have it; PCRE's /u is the validity
    check, as elsewhere in the cron scripts.
    --*/
    public static function truncateProse($text, $maxBytes = self::PROSE_MAX_BYTES){
        if(!is_string($text) || strlen($text) <= $maxBytes){
            return $text;
        }
        $cut = substr($text, 0, $maxBytes);
        // Drop a trailing partial multibyte sequence (at most 3 bytes)
        for($i = 0; $i < 3 && $cut !== '' && !preg_match('//u', $cut); $i++){
            $cut = substr($cut, 0, -1);
        }
        // Prefer a whitespace boundary within the last quarter of the window
        $space = strrpos($cut, ' ');
        $nl = strrpos($cut, "\n");
        $boundary = max($space === false ? -1 : $space, $nl === false ? -1 : $nl);
        if($boundary >= intdiv($maxBytes * 3, 4)){
            $cut = substr($cut, 0, $boundary);
        }
        return rtrim($cut);
    }

    /**
     * The physical index behind the logical name 'catalog'
     *
     * Staging and production share one Algolia application, so the logical
     * index every caller names ('catalog') maps to a physical index per
     * environment: `catalog` on production, `catalog_staging` on staging.
     * Before 2026-10-29 both environments wrote to `catalog`, so every
     * staging write -- the Newman suite's creates and deletes, a rehearsal
     * of batch-upload.php -- landed in live search. Callers keep passing
     * 'catalog'; only the URL changes. The frontend's search code picks the
     * same name by environment.
     *
     * @return string
     */
    public static function indexName(){
        if(defined('ENVIRONMENT') && ENVIRONMENT === 'staging'){
            return 'catalog_staging';
        }
        return 'catalog';
    }

    /**
     * Save an object to an Algolia index
     *
     * PUTs the search object to Algolia. Errors are logged but do NOT
     * set $this->error — Algolia failures should not fail the API response.
     *
     * @param string $indexName The index ('catalog')
     * @param array  $searchObject The array from generateSearchObject(), must contain 'objectID'
     */
    public function saveObject($indexName, $searchObject){
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';

        // Validate Index
        $validIndexes = ['catalog'];
        if(!in_array($indexName, $validIndexes)){
            // Invalid Index
            $errorLog->errorNumber = 226;
            $errorLog->errorMsg = 'Invalid index name for saveObject.';
            $errorLog->badData = $indexName;
            $errorLog->write();
            return;
        }

        // Get objectID
        $objectID = $searchObject['objectID'] ?? null;
        if($objectID === null){
            $errorLog->errorNumber = 233;
            $errorLog->errorMsg = 'Missing objectID in search object for saveObject.';
            $errorLog->badData = $indexName;
            $errorLog->write();
            return;
        }

        // Build URL
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/" . self::indexName() . "/{$objectID}";

        // JSON Payload
        $jsonData = json_encode($searchObject);

        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-algolia-application-id: " . ALGOLIA_APPLICATION_ID,
            "x-algolia-api-key: " . ALGOLIA_WRITE_API_KEY,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

        // Execute
        $response = curl_exec($ch);

        if(curl_errno($ch)){
            // cURL Error
            $errorLog->errorNumber = 227;
            $errorLog->errorMsg = curl_error($ch);
            $errorLog->badData = "Index: {$indexName} / objectID: {$objectID}";
            $errorLog->write();
            return;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if($httpStatus < 200 || $httpStatus >= 300){
            // HTTP Error
            $errorLog->errorNumber = 228;
            $errorLog->errorMsg = "HTTP Status {$httpStatus}";
            $errorLog->badData = "Index: {$indexName} / objectID: {$objectID} / Response: {$response}";
            $errorLog->write();
        }
    }

    /**
     * Delete an object from an Algolia index
     *
     * DELETEs the object from Algolia. Errors are logged but do NOT set
     * $this->error.
     *
     * @param string $indexName The index ('catalog')
     * @param string $objectID The Algolia objectID to delete
     */
    public function deleteObject($indexName, $objectID){
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';

        // Validate Index
        $validIndexes = ['catalog'];
        if(!in_array($indexName, $validIndexes)){
            // Invalid Index
            $errorLog->errorNumber = 229;
            $errorLog->errorMsg = 'Invalid index name for deleteObject.';
            $errorLog->badData = $indexName;
            $errorLog->write();
            return;
        }

        // Build URL
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/" . self::indexName() . "/{$objectID}";

        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-algolia-application-id: " . ALGOLIA_APPLICATION_ID,
            "x-algolia-api-key: " . ALGOLIA_WRITE_API_KEY,
            "Content-Type: application/json"
        ]);

        // Execute
        $response = curl_exec($ch);

        if(curl_errno($ch)){
            // cURL Error
            $errorLog->errorNumber = 230;
            $errorLog->errorMsg = curl_error($ch);
            $errorLog->badData = "Index: {$indexName} / objectID: {$objectID}";
            $errorLog->write();
            return;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if($httpStatus < 200 || $httpStatus >= 300){
            // HTTP Error
            $errorLog->errorNumber = 231;
            $errorLog->errorMsg = "HTTP Status {$httpStatus}";
            $errorLog->badData = "Index: {$indexName} / objectID: {$objectID} / Response: {$response}";
            $errorLog->write();
        }
    }

    /**
     * Push index settings to Algolia
     *
     * PUTs searchableAttributes / attributesForFaceting / etc. Index settings
     * were previously untracked dashboard state, which made faceting behavior
     * impossible to review or reproduce across environments.
     *
     * Unlike the sync methods this RETURNS a bool, because its only caller is a
     * CLI script that needs to report success to an operator.
     *
     * @param string $indexName The index ('catalog')
     * @param array  $settings  Settings payload
     *
     * @return bool True when Algolia accepted the settings
     */
    public function setSettings($indexName, $settings){
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';

        // Validate Index
        $validIndexes = ['catalog'];
        if(!in_array($indexName, $validIndexes)){
            // Invalid Index
            $errorLog->errorNumber = 280;
            $errorLog->errorMsg = 'Invalid index name for setSettings.';
            $errorLog->badData = $indexName;
            $errorLog->write();
            return false;
        }

        // Build URL
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/" . self::indexName() . "/settings";

        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-algolia-application-id: " . ALGOLIA_APPLICATION_ID,
            "x-algolia-api-key: " . ALGOLIA_WRITE_API_KEY,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($settings));

        // Execute
        $response = curl_exec($ch);

        if(curl_errno($ch)){
            // cURL Error
            $errorLog->errorNumber = 281;
            $errorLog->errorMsg = curl_error($ch);
            $errorLog->badData = "Index: {$indexName}";
            $errorLog->write();
            return false;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if($httpStatus < 200 || $httpStatus >= 300){
            // HTTP Error
            $errorLog->errorNumber = 282;
            $errorLog->errorMsg = "HTTP Status {$httpStatus}";
            $errorLog->badData = "Index: {$indexName} / Response: {$response}";
            $errorLog->write();
            return false;
        }

        return true;
    }

    /**
     * Delete many objects in one request
     *
     * POSTs a batch of deleteObject operations. Used by the brewer delete
     * cascade: MySQL cascades a brewer delete to its beers and locations, so
     * their Algolia records must go in the same pass — and a large brewer has
     * hundreds of beers, so N records cost one HTTP call instead of N DELETEs.
     *
     * Errors are logged but do NOT set $this->error — Algolia failures should
     * not fail the API response.
     *
     * @param string $indexName The index ('catalog')
     * @param array  $objectIDs List of Algolia objectIDs to delete
     */
    public function batchDelete($indexName, $objectIDs){
        $bodies = array();
        foreach(array_filter($objectIDs) as $objectID){
            $bodies[] = array('objectID' => $objectID);
        }
        $this->batch($indexName, 'deleteObject', $bodies, array('index' => 291, 'curl' => 292, 'http' => 293));
    }

    /**
     * Replace the index's synonym set
     *
     * POSTs a batch of synonym objects with replaceExistingSynonyms=true, so
     * the pushed set IS the synonym state — same source-of-truth discipline as
     * setSettings(): synonyms are generated from the style_alias / parent_alias
     * tables by algolia/synonyms.php, never hand-edited in the dashboard.
     *
     * Like setSettings(), RETURNS a bool because its only caller is a CLI
     * script that needs to report success to an operator.
     *
     * @param string $indexName The index ('catalog')
     * @param array  $synonyms  List of synonym objects, each with objectID,
     *                          type ('synonym') and a synonyms[] array
     *
     * @return bool True when Algolia accepted the batch
     */
    public function saveSynonyms($indexName, $synonyms){
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';

        // Validate Index
        $validIndexes = ['catalog'];
        if(!in_array($indexName, $validIndexes)){
            // Invalid Index
            $errorLog->errorNumber = 286;
            $errorLog->errorMsg = 'Invalid index name for saveSynonyms.';
            $errorLog->badData = $indexName;
            $errorLog->write();
            return false;
        }

        // Build URL — replaceExistingSynonyms so stale groups can't linger
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/" . self::indexName() . "/synonyms/batch?replaceExistingSynonyms=true";

        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-algolia-application-id: " . ALGOLIA_APPLICATION_ID,
            "x-algolia-api-key: " . ALGOLIA_WRITE_API_KEY,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array_values($synonyms)));

        // Execute
        $response = curl_exec($ch);

        if(curl_errno($ch)){
            // cURL Error
            $errorLog->errorNumber = 287;
            $errorLog->errorMsg = curl_error($ch);
            $errorLog->badData = "Index: {$indexName} / " . count($synonyms) . " synonym groups";
            $errorLog->write();
            return false;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if($httpStatus < 200 || $httpStatus >= 300){
            // HTTP Error
            $errorLog->errorNumber = 288;
            $errorLog->errorMsg = "HTTP Status {$httpStatus}";
            $errorLog->badData = "Index: {$indexName} / Response: {$response}";
            $errorLog->write();
            return false;
        }

        return true;
    }

    /**
     * Partially update many objects in one request
     *
     * POSTs a batch of partialUpdateObject operations to Algolia. Used by the
     * denormalization cascades (e.g. a brewer rename touching every one of its
     * beers and locations) so N records cost one HTTP call instead of N PUTs.
     *
     * Uses 'partialUpdateObjectNoCreate' — these are patches to records that
     * should already exist. The plain 'partialUpdateObject' action would
     * resurrect a deleted record as a stub if the objectID were stale.
     *
     * NOTE: partial updates merge at the TOP level of attributes only. A nested
     * object attribute is replaced wholesale, so callers patching 'brewer' must
     * send the complete brewer object (brewerID *and* name), not just the
     * changed key — otherwise the untouched sibling keys are dropped.
     *
     * Errors are logged but do NOT set $this->error — Algolia failures should
     * not fail the API response.
     *
     * @param string $indexName The index ('catalog')
     * @param array  $objects   List of partial objects, each containing 'objectID'
     */
    public function batchPartialUpdate($indexName, $objects){
        $this->batch($indexName, 'partialUpdateObjectNoCreate', $objects, array('index' => 273, 'missing' => 274, 'curl' => 275, 'http' => 276));
    }

    /**
     * Save many objects in one request
     *
     * POSTs a batch of updateObject operations — the batched form of
     * saveObject(): a full replace of each record, creating it when absent.
     * batch-upload.php uses it so a re-index costs one call per 1,000 records
     * instead of one PUT each.
     *
     * Errors are logged but do NOT set $this->error.
     *
     * @param string $indexName The index ('catalog')
     * @param array  $objects   List of search objects, each containing 'objectID'
     */
    public function batchSave($indexName, $objects){
        $this->batch($indexName, 'updateObject', $objects, array('index' => 367, 'missing' => 368, 'curl' => 369, 'http' => 370));
    }

    /*--
    POST a list of bodies to the index's /batch endpoint as $action operations,
    1,000 per request (Algolia's cap; also 10 MB). $errors maps the failure
    kinds to this caller's error numbers: 'index' (unknown index), 'missing'
    (a body without an objectID, skipped; omit it to send bodies as given),
    'curl' and 'http'.
    --*/
    private function batch($indexName, $action, $bodies, array $errors){
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';

        // Validate Index
        $validIndexes = ['catalog'];
        if(!in_array($indexName, $validIndexes)){
            // Invalid Index
            $errorLog->errorNumber = $errors['index'];
            $errorLog->errorMsg = "Invalid index name for {$action} batch.";
            $errorLog->badData = $indexName;
            $errorLog->write();
            return;
        }

        // Drop any body missing an objectID rather than sending a malformed batch
        if(isset($errors['missing'])){
            $queue = array();
            foreach($bodies as $body){
                if(!empty($body['objectID'])){
                    $queue[] = $body;
                }else{
                    $errorLog->errorNumber = $errors['missing'];
                    $errorLog->errorMsg = "Skipped object with missing objectID in {$action} batch.";
                    $errorLog->badData = json_encode($body);
                    $errorLog->write();
                }
            }
            $bodies = $queue;
        }

        // Nothing to do
        if(empty($bodies)){
            return;
        }

        // Build URL
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/" . self::indexName() . "/batch";

        foreach(array_chunk(array_values($bodies), 1000) as $chunk){
            // Build Request Body
            $requests = array();
            foreach($chunk as $body){
                $requests[] = array(
                    'action' => $action,
                    'body'   => $body
                );
            }
            $jsonData = json_encode(array('requests'=>$requests));

            // Initialize cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "x-algolia-application-id: " . ALGOLIA_APPLICATION_ID,
                "x-algolia-api-key: " . ALGOLIA_WRITE_API_KEY,
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

            // Execute
            $response = curl_exec($ch);

            if(curl_errno($ch)){
                // cURL Error
                $errorLog->errorNumber = $errors['curl'];
                $errorLog->errorMsg = curl_error($ch);
                $errorLog->badData = "Index: {$indexName} / {$action} batch of " . count($chunk);
                $errorLog->write();
                continue;
            }

            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if($httpStatus < 200 || $httpStatus >= 300){
                // HTTP Error
                $errorLog->errorNumber = $errors['http'];
                $errorLog->errorMsg = "HTTP Status {$httpStatus}";
                $errorLog->badData = "Index: {$indexName} / {$action} batch of " . count($chunk) . " / Response: {$response}";
                $errorLog->write();
            }
        }
    }

}
?>
