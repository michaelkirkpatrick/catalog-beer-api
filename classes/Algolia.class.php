<?php
class Algolia {

	//
	public $algolia_id = null;
	public $beer_id = null;
	public $brewer_id = null;
	public $location_id = null;

	// Catalog.beer API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();

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
		}

		// Prepare the data for insertion
		$foreignKeyColumn = $validTypes[$type];
		$data = [
			'beer_id'     => null,
			'brewer_id'   => null,
			'location_id' => null,
		];
		$data[$foreignKeyColumn] = $record_id;

		// Build the SQL statement with placeholders
		$sql = "INSERT INTO `algolia` (`algolia_id`, `beer_id`, `brewer_id`, `location_id`)
				VALUES (?, ?, ?, ?)";

		// Prepare the statement
		$stmt = $db->mysqli->prepare($sql);
		if (!$stmt) {
			// DB Prepare Failed
			$this->error = true;
			$this->errorMsg = "There was an error processing your request.";

			// Log Error
			$errorLog->errorNumber = 206;
			$errorLog->errorMsg = "Prepare failed";
			$errorLog->badData = $db->mysqli->error;
			$errorLog->write();
		}

		// Attempt insertion with retries in case of UUID collision
		$maxRetries = 5;
		for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
			// Generate a unique algolia_id
			$algolia_id = $uuid->createCode();

			// Bind parameters (s = string)
			if (!$stmt->bind_param(
				"ssss",
				$algolia_id,
				$data['beer_id'],
				$data['brewer_id'],
				$data['location_id']
			)) {
				// Unable to Bind Parameters
				$this->error = true;
				$this->errorMsg = "There was an error processing your request.";

				// Log Error
				$errorLog->errorNumber = 207;
				$errorLog->errorMsg = "Binding parameters failed";
				$errorLog->badData = $stmt->error;
				$errorLog->write();
			}

			// Execute the statement
			if ($stmt->execute()) {
				// Successful insertion
				$stmt->close();
				return $algolia_id;
			} else {
				// Check if the error is due to duplicate entry (UUID collision)
				if ($stmt->errno === 1062) { // 1062 = Duplicate entry
					// Log the collision occurrence (optional)
					$errorLog->errorNumber = 208;
					$errorLog->errorMsg = "UUID collision detected on attempt. Retrying...";
					$errorLog->badData = $attempt + 1;
					$errorLog->write();
					// Continue to retry
				} else {
					// For other errors, close the statement and throw an exception
					$this->error = true;
					$this->errorMsg = "There was an error processing your request.";

					// Log Error
					$errorLog->errorNumber = 209;
					$errorLog->errorMsg = "Error processing $stmt";
					$errorLog->badData = $stmt->error;
					$errorLog->write();

					// Close $stmt
					$stmt->close();
				}
			}
		}

		// If all retries fail, close the statement and throw an exception
		$stmt->close();

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
		}

		// Determine the column based on type
		$column = $validTypes[$type];

		// Build the SQL statement
		$sql = "SELECT `algolia_id` FROM `algolia` WHERE `{$column}` = ? LIMIT 1";

		// Prepare the statement
		$stmt = $db->mysqli->prepare($sql);
		if (!$stmt) {
			// DB Prepare Failed
			$this->error = true;
			$this->errorMsg = "There was an error processing your request.";

			// Log Error
			$errorLog->errorNumber = 212;
			$errorLog->errorMsg = "Prepare failed";
			$errorLog->badData = $db->mysqli->error;
			$errorLog->write();
		}

		// Bind the record_id parameter
		if (!$stmt->bind_param("s", $record_id)) {
			// Unable to Bind Parameters
			$this->error = true;
			$this->errorMsg = "There was an error processing your request.";

			// Log Error
			$errorLog->errorNumber = 213;
			$errorLog->errorMsg = "Binding parameters failed";
			$errorLog->badData = $stmt->error;
			$errorLog->write();
		}

		// Execute the statement
		if (!$stmt->execute()) {
			// Execution Failed
			$this->error = true;
			$this->errorMsg = "There was an error processing your request.";

			// Log Error
			$errorLog->errorNumber = 214;
			$errorLog->errorMsg = "Execution error.";
			$errorLog->badData = $stmt->error;
			$errorLog->write();
		}

		// Get the result
		$result = $stmt->get_result();
		$record = $result->fetch_assoc();

		// Close the statement
		$stmt->close();

		// Return the algolia_id or null if not found
		return $record ? $record['algolia_id'] : null;
	}

	/**
	 * Searches the Algolia index with the given query string.
	 *
	 * @param string $query The search query string.
	 * @return array|null The decoded JSON response from Algolia as an associative array, or null on failure.
	 */
	function searchAlgolia(string $query, string $indexName): ?array {
		// Required Classes
		$errorLog = new LogError();
		$errorLog->filename = 'Algolia.class.php';

		// Check Index
		$validIndex = array('beer', 'brewer', 'location');
		if(!in_array($indexName, $validIndex)){
			// Invalid Index
			$this->error = true;
			$this->errorMsg = "Invalid index. Must be one of: 'beer', 'brewer', or 'location'.";
			$this->responseCode = 400;

			// Log Error
			$errorLog->errorNumber = 219;
			$errorLog->errorMsg = $this->errorMsg;
			$errorLog->badData = $type;
			$errorLog->write();
		}

		if(!$this->error){
			// Algolia API Endpoint
			$url = "https://" . ALGOLIA_APPLICATION_ID . ".algolia.net/1/indexes/{$indexName}/query";

			// URL-encode the query string
			$encodedQuery = urlencode($query);

			// Prepare the POST data as per the provided cURL request
			$postData = json_encode([
				'params' => "query={$encodedQuery}"
			]);

			// Initialize cURL
			$ch = curl_init();

			// Set cURL options
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
			curl_setopt($ch, CURLOPT_POST, true);           // Use POST method
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				"x-algolia-application-id: " . ALGOLIA_APPLICATION_ID,
				"x-algolia-api-key: " . ALGOLIA_SEARCH_API_KEY,
				"Accept: application/json",
				"Content-Type: application/json"
			]);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

			// Execute the cURL request
			$response = curl_exec($ch);

			// Check for cURL errors
			if (curl_errno($ch)) {
				// cURL Error
				$this->error = true;
				$this->errorMsg = "There was an error processing your request.";
				$this->responseCode = 500;

				// Log Error
				$errorLog->errorNumber = 215;
				$errorLog->errorMsg = curl_error($ch);
				$errorLog->badData = '';
				$errorLog->write();

				curl_close($ch);

				// Return null
				return null;
			}

			// Get the HTTP status code
			$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			// Close the cURL session
			curl_close($ch);

			// Check if the request was successful
			if ($httpStatus >= 200 && $httpStatus < 300) {
				// Return decoded JSON as array
				return json_decode($response);
			} else {
				// Handle non-successful HTTP status codes as needed
				$this->error = true;
				$this->errorMsg = "There was an error processing your request.";
				$this->responseCode = 500;

				// Log Error
				$errorLog->errorNumber = 216;
				$errorLog->errorMsg = "HTTP Status {$httpStatus}";
				$errorLog->badData = $decodedResponse;
				$errorLog->write();

				// Return null
				return null;
			}
		}else{
			// Error Triggered; Return null
			return null;
		}
	}

	public function api($method, $function, $data){
		echo "Got to API Function\n";
		/*
		// Required Classes
		$errorLog = new LogError();
		$errorLog->filename = 'Algolia.class.php';

		/*---
		{METHOD} https://api.catalog.beer/query/{type}

		POST https://api.catalog.beer/{type}
		---
		switch($method){
			case 'POST':
				// POST https://api.catalog.beer/{type}/{query}
				// Check for Query
				if(empty($data->query)){
					// Missing Query
					$this->error = true;
					$this->json['error'] = true;
					$this->json['error_msg'] = "Missing query. Send your query in a JSON array formatted {\"query\":\"Your query here...\"}";
					$this->responseCode = 400;

					// Log Error
					$errorLog->errorNumber = 218;
					$errorLog->errorMsg = "No query submitted.";
					$errorLog->badData = '';
					$errorLog->write();
				}

				// Process Query
				$response = $this->searchAlgolia($data->query, $function);
				if(!$this->error){
					// Return Response
					$this->json = $response;
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
				$this->responseHeader = 'Allow: POST';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 217;
				$errorLog->errorMsg = 'Invalid Method (/query)';
				$errorLog->badData = $method;
				$errorLog->filename = $this->filename;
				$errorLog->write();
		}*/
	}
}
?>
