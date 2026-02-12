<?php
/* ---
String Length: 36

// Generate UUID
$uuid = new uuid();
$var = $uuid->generate('db_table_name');
if(!$uuid->error){
	// Save to Class
	$this->ID = $var;
}else{
	// UUID Generation Error
	$this->error = true;
	$this->errorMsg = $uuid->errorMsg;
}
--- */

class uuid {

	public $uuid = '';

	public $error = false;
	public $errorMsg = null;
	public $responseCode = 200;

	// Valid tables for UUID uniqueness check
	private $validTables = ['beer', 'brewer', 'location', 'users', 'error_log', 'api_keys', 'api_logging', 'privileges', 'algolia', 'api_usage'];

	// ----- Generate Unique UUID -----
	public function generate($table){
		// Default State
		$continue = true;

		// While Loop
		while($continue){
			// Create Code
			$this->createCode();

			// Check Unique
			$unique = $this->checkUnique($table);
			if($unique || $this->error){
				$continue = false;
			}
		}

		// Return UUID
		return $this->uuid;
	}

	// ----- Generate Code -----
	public function createCode(){
		// Generate 16 random bytes
		$data = random_bytes(16);

		// Set version to 0100
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		// Set bits 6-7 to 10
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		// Convert to hexadecimal representation
		$this->uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

		return $this->uuid;
	}

	// ----- Check Unique -----
	private function checkUnique($table){
		// Default Return
		$unique = false;

		// Validate table name against whitelist
		if(!in_array($table, $this->validTables)){
			$this->error = true;
			$this->errorMsg = 'Invalid table name.';
			$this->responseCode = 500;
			return false;
		}

		// Connect to database
		$db = new Database();
		$result = $db->query("SELECT id FROM $table WHERE id=?", [$this->uuid]);
		if(!$db->error){
			if($result->num_rows == 0){
				$unique = true;
			}
		}else{
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = $db->responseCode;
		}

		// Close Database Connection
		$db->close();

		// Return
		return $unique;
	}

	// ----- Validate string is UUID v4 Compliant -----
	public function validate($uuid){
		// Default
		$valid = false;
		$this->error = true;
		$this->errorMsg = 'Invalid UUID. All UUIDs must be compliant with RFC 4122 and the version 4 UUID.';
		$this->responseCode = 400;

		// Validate UUID
		if(preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/', $uuid)){
			// Check Version Number
			$time_hi_and_version_hex = substr($uuid, 14, 4);
			$time_hi_and_version_binary = sprintf("%016d", base_convert($time_hi_and_version_hex, 16, 2));

			// Check Reserved Sequence
			$clock_seq_hi_and_reserved_hex = substr($uuid, 19, 2);
			$clock_seq_hi_and_reserved_binary = sprintf("%08d", base_convert($clock_seq_hi_and_reserved_hex, 16, 2));

			// Validate that the two most significant bits (bits 6 and 7) of clock_seq_hi_and_reserved are zero and one, respectively.
			if(substr($clock_seq_hi_and_reserved_binary, 0, 2) === '10'){
				// Validate that the four most significant bits (bits 12 through 15) of the time_hi_and_version field are 0010, respectively
				if(substr($time_hi_and_version_binary, 0, 4) === '0100'){
					// Valid RFC 4122 v4 UUID
					$valid = true;
					$this->error = false;
					$this->errorMsg = '';
					$this->responseCode = 200;
				}
			}
		}

		if(!$valid){
			// Invalid UUID Submission
			$errorLog = new LogError();
			$errorLog->errorNumber = 159;
			$errorLog->errorMsg = 'Invalid UUID';
			$errorLog->badData = $uuid;
			$errorLog->filename = 'API / uuid.class.php';
			$errorLog->write();
		}

		// Return
		return $valid;
	}
}
?>
