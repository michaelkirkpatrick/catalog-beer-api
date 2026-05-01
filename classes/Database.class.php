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
        // Use exception-based error handling so transient MySQL failures
        // (server gone away, connection refused, greeting packet errors)
        // surface as catchable mysqli_sql_exception instead of PHP warnings
        // or uncaught fatal errors. PHP 8.1+ default; set explicitly for clarity.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // Connect to Database
        $this->connect();
    }

    private function connect(): void {
        try {
            $this->mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $this->mysqli->set_charset("utf8mb4");
        } catch (mysqli_sql_exception $e) {
            $this->error = true;
            $this->errorMsg = 'Database connection error.';
            $this->responseCode = 500;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 1;
            $errorLog->errorMsg = 'Database Connection Error';
            $errorLog->badData = $e->getCode() . ': ' . $e->getMessage();
            $errorLog->filename = 'API / Database.class.php';
            $errorLog->write();
        }
    }

    public function query(string $sql, array $params = []): ?mysqli_result {
        if($this->error){
            return null;
        }

        try {
            $stmt = $this->mysqli->prepare($sql);
        } catch (mysqli_sql_exception $e) {
            $this->error = true;
            $this->errorMsg = 'Sorry, there was an internal error querying our database. I\'ve logged the error for our support team so they can diagnose and fix the issue.';
            $this->responseCode = 500;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 2;
            $errorLog->errorMsg = 'SQL Prepare Error';
            $errorLog->badData = 'Query: ' . $sql . ' MySQL Error: ' . $e->getMessage();
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

        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            $this->error = true;
            $this->errorMsg = 'Sorry, there was an internal error querying our database. I\'ve logged the error for our support team so they can diagnose and fix the issue.';
            $this->responseCode = 500;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 3;
            $errorLog->errorMsg = 'SQL Execution Error';
            $errorLog->badData = 'Query: ' . $sql . ' Params: ' . json_encode($params) . ' MySQL Error: ' . $e->getMessage();
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
        // Skip if connection never succeeded — $this->mysqli may be unusable
        if($this->error){
            return;
        }
        try {
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
        } catch (mysqli_sql_exception $e) {
            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 124;
            $errorLog->filename = 'API / Database.class.php';
            $errorLog->errorMsg = 'Database Error';
            $errorLog->badData = 'Unable to close database connection: ' . $e->getMessage();
            $errorLog->write();
        }
    }
}
?>
