<?php
class Algolia {

    //
    public $algolia_id = null;
    public $beer_id = null;
    public $brewer_id = null;
    public $location_id = null;

    // Error Variables
    public $error = false;
    public $errorMsg = null;

    /**
     * Add a new Algolia record
     *
     * Inserts a new record into the 'algolia' table with a unique algolia_id.
     *
     * @param string $type      The type of record ('beer', 'brewer', 'location')
     * @param string $record_id The ID of the record in the respective table
     *
     * @return string The generated algolia_id on success
     *
     * @throws InvalidArgumentException If the provided type is invalid
     * @throws Exception If the insert operation fails
     */
    public function add($type, $record_id){
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';
        $db = new Database();
        $uuid = new uuid();

        // Define valid types and corresponding column names
        $validTypes = [
            'beer'     => 'beer_id',
            'brewer'   => 'brewer_id',
            'location' => 'location_id',
        ];

        // Validate the type
        if (!array_key_exists($type, $validTypes)) {
            // Invalid Type
            $this->error = true;
            $this->errorMsg = "Invalid type provided. Must be one of: 'beer', 'brewer', 'location'.";

            // Log Error
            $errorLog->errorNumber = 205;
            $errorLog->errorMsg = $this->errorMsg;
            $errorLog->badData = $type;
            $errorLog->write();
            return;
        }

        // Prepare the data for insertion
        $foreignKeyColumn = $validTypes[$type];
        $data = [
            'beer_id'     => null,
            'brewer_id'   => null,
            'location_id' => null,
        ];
        $data[$foreignKeyColumn] = $record_id;

        // Attempt insertion with retries in case of UUID collision
        $maxRetries = 5;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Generate a unique algolia_id
            $algolia_id = $uuid->createCode();

            // Insert using prepared statement
            $db->query("INSERT INTO `algolia` (`algolia_id`, `beer_id`, `brewer_id`, `location_id`) VALUES (?, ?, ?, ?)", [$algolia_id, $data['beer_id'], $data['brewer_id'], $data['location_id']]);

            if (!$db->error) {
                // Successful insertion
                $db->close();
                return $algolia_id;
            } else {
                // Check if it might be a duplicate key error, reset error for retry
                $db->error = false;
                $db->errorMsg = null;
                $db->responseCode = 200;

                // Log the collision occurrence
                $errorLog->errorNumber = 208;
                $errorLog->errorMsg = "UUID collision detected on attempt. Retrying...";
                $errorLog->badData = $attempt + 1;
                $errorLog->write();
            }
        }

        // If all retries fail
        $db->close();

        // Error
        $this->error = true;
        $this->errorMsg = "There was an error processing your request.";

        // Log Error
        $errorLog->errorNumber = 210;
        $errorLog->errorMsg = "Failed to generate a unique algolia_id after {$maxRetries} attempts.";
        $errorLog->badData = null;
        $errorLog->write();
    }

    /**
     * Lookup Algolia ID by Record
     *
     * Retrieves the algolia_id associated with a given beer_id, brewer_id, or location_id.
     *
     * @param string $type      The type of record ('beer', 'brewer', 'location')
     * @param string $record_id The ID of the record in the respective table
     *
     * @return string|null The algolia_id if found, or null if not found
     *
     * @throws InvalidArgumentException If the provided type is invalid
     * @throws Exception If the query fails
     */
    public function getAlgoliaIdByRecord($type, $record_id){
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';
        $db = new Database();

        // Define valid types and corresponding column names
        $validTypes = [
            'beer'     => 'beer_id',
            'brewer'   => 'brewer_id',
            'location' => 'location_id',
        ];

        // Validate the type
        if (!array_key_exists($type, $validTypes)) {
            // Invalid Type
            $this->error = true;
            $this->errorMsg = "Invalid type provided. Must be one of: 'beer', 'brewer', 'location'.";

            // Log Error
            $errorLog->errorNumber = 211;
            $errorLog->errorMsg = $this->errorMsg;
            $errorLog->badData = $type;
            $errorLog->write();
            return null;
        }

        // Determine the column based on type
        $column = $validTypes[$type];

        // Query using prepared statement (column name is from whitelist, safe to interpolate)
        $result = $db->query("SELECT `algolia_id` FROM `algolia` WHERE `{$column}` = ? LIMIT 1", [$record_id]);
        if($db->error){
            // Query Error
            $this->error = true;
            $this->errorMsg = "There was an error processing your request.";

            // Log Error
            $errorLog->errorNumber = 214;
            $errorLog->errorMsg = "Execution error.";
            $errorLog->badData = $db->errorMsg;
            $errorLog->write();
            $db->close();
            return null;
        }

        $record = $result->fetch_assoc();
        $db->close();

        // Return the algolia_id or null if not found
        return $record ? $record['algolia_id'] : null;
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
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/{$indexName}/{$objectID}";

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
            curl_close($ch);
            return;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
     * DELETEs the object from Algolia and removes the local algolia table row.
     * Errors are logged but do NOT set $this->error.
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
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/{$indexName}/{$objectID}";

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
            curl_close($ch);
            return;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($httpStatus < 200 || $httpStatus >= 300){
            // HTTP Error
            $errorLog->errorNumber = 231;
            $errorLog->errorMsg = "HTTP Status {$httpStatus}";
            $errorLog->badData = "Index: {$indexName} / objectID: {$objectID} / Response: {$response}";
            $errorLog->write();
            return;
        }

        // Delete local algolia table row
        $db = new Database();
        $db->query("DELETE FROM algolia WHERE algolia_id=?", [$objectID]);
        if($db->error){
            // DB Cleanup Error
            $errorLog->errorNumber = 232;
            $errorLog->errorMsg = 'Failed to delete algolia table row.';
            $errorLog->badData = "objectID: {$objectID} / DB Error: {$db->errorMsg}";
            $errorLog->write();
        }
        $db->close();
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
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/{$indexName}/settings";

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
            curl_close($ch);
            return false;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/{$indexName}/synonyms/batch?replaceExistingSynonyms=true";

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
            curl_close($ch);
            return false;
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
        // Required Classes
        $errorLog = new LogError();
        $errorLog->filename = 'Algolia.class.php';

        // Validate Index
        $validIndexes = ['catalog'];
        if(!in_array($indexName, $validIndexes)){
            // Invalid Index
            $errorLog->errorNumber = 273;
            $errorLog->errorMsg = 'Invalid index name for batchPartialUpdate.';
            $errorLog->badData = $indexName;
            $errorLog->write();
            return;
        }

        // Nothing to do
        if(empty($objects)){
            return;
        }

        // Drop any object missing an objectID rather than sending a malformed batch
        $queue = array();
        foreach($objects as $object){
            if(!empty($object['objectID'])){
                $queue[] = $object;
            }else{
                $errorLog->errorNumber = 274;
                $errorLog->errorMsg = 'Skipped object with missing objectID in batchPartialUpdate.';
                $errorLog->badData = json_encode($object);
                $errorLog->write();
            }
        }
        if(empty($queue)){
            return;
        }

        // Build URL
        $url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/{$indexName}/batch";

        // Chunk — Algolia caps a batch at 1,000 operations / 10MB
        $chunks = array_chunk($queue, 1000);
        foreach($chunks as $chunk){
            // Build Request Body
            $requests = array();
            foreach($chunk as $object){
                $requests[] = array(
                    'action' => 'partialUpdateObjectNoCreate',
                    'body'   => $object
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
                $errorLog->errorNumber = 275;
                $errorLog->errorMsg = curl_error($ch);
                $errorLog->badData = "Index: {$indexName} / batch of " . count($chunk);
                $errorLog->write();
                curl_close($ch);
                continue;
            }

            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if($httpStatus < 200 || $httpStatus >= 300){
                // HTTP Error
                $errorLog->errorNumber = 276;
                $errorLog->errorMsg = "HTTP Status {$httpStatus}";
                $errorLog->badData = "Index: {$indexName} / batch of " . count($chunk) . " / Response: {$response}";
                $errorLog->write();
            }
        }
    }

}
?>
