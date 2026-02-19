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

}
?>
