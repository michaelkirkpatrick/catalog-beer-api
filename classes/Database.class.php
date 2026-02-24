<?php
class Database {

	// Properties
	private mysqli $mysqli;

	// Error Handling
	public bool $error = false;
	public ?string $errorMsg = null;
	public int $responseCode = 200;
	private int $affectedRows = 0;

	public function __construct(){
		// Restore pre-PHP 8.1 error handling (return false instead of throwing exceptions)
		mysqli_report(MYSQLI_REPORT_OFF);

		// Connect to Database
		$this->connect();
	}

	private function connect(): void {
		$this->mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
		if($this->mysqli->connect_error){
			$this->error = true;
			$this->errorMsg = 'Database connection error.';
			$this->responseCode = 500;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 1;
			$errorLog->errorMsg = 'Database Connection Error';
			$errorLog->badData = $this->mysqli->connect_errno;
			$errorLog->filename = 'API / Database.class.php';
			$errorLog->write();
			return;
		}
		$this->mysqli->set_charset("utf8");
	}

	public function query(string $sql, array $params = []): ?mysqli_result {
		$stmt = $this->mysqli->prepare($sql);
		if(!$stmt){
			$this->error = true;
			$this->errorMsg = 'Sorry, there was an internal error querying our database. I\'ve logged the error for our support team so they can diagnose and fix the issue.';
			$this->responseCode = 500;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 2;
			$errorLog->errorMsg = 'SQL Prepare Error';
			$errorLog->badData = 'Query: ' . $sql . ' MySQL Error: ' . $this->mysqli->error;
			$errorLog->filename = 'API / Database.class.php';
			$errorLog->write();
			return null;
		}

		if(!empty($params)){
			$types = '';
			foreach($params as $param){
				if(is_int($param)) $types .= 'i';
				elseif(is_float($param)) $types .= 'd';
				else $types .= 's';
			}
			$stmt->bind_param($types, ...$params);
		}

		if(!$stmt->execute()){
			$this->error = true;
			$this->errorMsg = 'Sorry, there was an internal error querying our database. I\'ve logged the error for our support team so they can diagnose and fix the issue.';
			$this->responseCode = 500;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 3;
			$errorLog->errorMsg = 'SQL Execution Error';
			$errorLog->badData = 'Query: ' . $sql . ' Params: ' . json_encode($params) . ' MySQL Error: ' . $stmt->error;
			$errorLog->filename = 'API / Database.class.php';
			$errorLog->write();
			$stmt->close();
			return null;
		}

		$this->affectedRows = $stmt->affected_rows;
		$result = $stmt->get_result();
		$stmt->close();
		return $result !== false ? $result : null;
	}

	public function getAffectedRows(): int {
		return $this->affectedRows;
	}

	public function getInsertId(): int {
		return $this->mysqli->insert_id;
	}

	public function getConnection(): mysqli {
		return $this->mysqli;
	}

	// ----- Close Connection -----
	public function close(): void {
		if(!$this->mysqli->close()){
			// Unsuccessful close
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 124;
			$errorLog->filename = 'API / Database.class.php';
			$errorLog->errorMsg = 'Database Error';
			$errorLog->badData = 'Unable to close database connection';
			$errorLog->write();
		}
	}
}
?>
