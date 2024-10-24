<?php
class Algolia {

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
}
?>
