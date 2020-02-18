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
	public $errorMsg = '';
	public $responseCode = 200;
	
	
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
		/*---
		RFC 4122 Compliant Version 4 UUID Generator
		https://tools.ietf.org/html/rfc4122
		---*/
		$string = '';
		$uuid = '';
		$bitCount = 1;
		$charCount = 1;
		for($i=1;$i<=128;$i++){
			/*--
			Generate Bit
			Bits 6 & 7 are 0 and 1 respectively
			Bits 12-15 are 0100, representing a "Version 4" UUID
			See Section 4.4 of RFC 4122
			--*/
			switch($i){
				case 49:
					$string .= '0';
				break;
				case 50:
					$string .= '1';
				break;
				case 51:
					$string .= '0';
				break;
				case 52:
					$string .= '0';
				break;
				case 65:
					$string .= '1';
				break;
				case 66:
					$string .= '0';
				break;
				default:
					$string .= rand(0, 1);
			}

			if($bitCount === 4){
				// Generate Character
				$uuid .= base_convert($string, 2, 16);
				$string = '';

				// Reset Bit Count
				$bitCount = 1;

				// Add Dashes
				switch($charCount){
					case 8:
						$uuid .= '-';
						break;
					case 12:
						$uuid .= '-';
						break;
					case 16:
						$uuid .= '-';
						break;
					case 20:
						$uuid .= '-';
						break;
				}
				$charCount++;
			}else{
				$bitCount++;
			}
		}
		$this->uuid = $uuid;
		
		return $this->uuid;
	}
	
	// ----- Check Unique -----
	private function checkUnique($table){
		// Default Return
		$unique = false;
		
		// Connect to database
		$db = new Database();
		$dbTable = $db->escape($table);
		$dbUUID = $db->escape($this->uuid);
		
		// Query
		$db->query("SELECT id FROM $table WHERE id='$dbUUID'");
		if(!$db->error){
			if($db->result->num_rows == 0){
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
	
	// ----- Generate Alphanumeric String -----
	public function createAlpha($characters, $allCaps){
	
		// Setup Array
		$array = array(1, 2, 3, 4, 5, 6, 7, 8, 9, 0, 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z');

		if(!$allCaps){
			array_push($array, 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z');
		}

		$badWords = array_map('str_getcsv', file(ROOT . '/classes/resources/badwords.csv'));
		$continue = true;
		$badWordFlag = false;

		while($continue){

			// Generate Code
			$this->uuid = '';
			$arraySize = count($array);
			for($i=1;$i<=$characters;$i++){
				$rand = random_int(0,$arraySize-1);
				$this->uuid .= $array[$rand];
			}

			// Ensure no bad words
			foreach($badWords[0] as &$badWord){
				if(!empty($badWord)){
					if(strpos($this->uuid, $badWord) !== false) {
						$badWordFlag = true;
					}	
				}
			}

			// Check $badWordFlag
			if(!$badWordFlag){
				// Stop
				$continue = false;
			}
		}

		return $this->uuid;
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