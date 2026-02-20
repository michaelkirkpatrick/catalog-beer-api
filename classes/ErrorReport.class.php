<?php

class ErrorReport {

	// Validation
	public $error = false;
	public $errorMsg = null;

	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();

	public function generateReport($apiKey){
		// Admin-only: Get error log summary report
		$apiKeys = new apiKeys();
		if($apiKeys->validate($apiKey, true)){
			$users = new Users();
			$users->validate($apiKeys->userID, true);

			if(!$users->admin){
				// Not an admin
				$this->responseCode = 401;
				$this->error = true;
				$this->errorMsg = 'Unauthorized: You must be an admin to access this endpoint.';

				$errorLog = new LogError();
				$errorLog->errorNumber = 251;
				$errorLog->errorMsg = 'Unauthorized: Not admin (GET /error-log)';
				$errorLog->badData = "apiKey: $apiKey";
				$errorLog->filename = 'ErrorReport.class.php';
				$errorLog->write();
			}
		}else{
			// Invalid API Key
			$this->error = true;
			$this->errorMsg = 'Invalid API Key.';
			$this->responseCode = 404;

			$errorLog = new LogError();
			$errorLog->errorNumber = 251;
			$errorLog->errorMsg = 'Invalid API Key (GET /error-log)';
			$errorLog->badData = $apiKey;
			$errorLog->filename = 'ErrorReport.class.php';
			$errorLog->write();
		}

		if(!$this->error){
			$db = new Database();
			$now = time();
			$last24h = $now - 86400;
			$last7d = $now - 604800;

			// 1. Summary counts
			$result = $db->query("SELECT COUNT(*) AS total_unresolved, SUM(CASE WHEN timestamp >= ? THEN 1 ELSE 0 END) AS last_24h, SUM(CASE WHEN timestamp >= ? THEN 1 ELSE 0 END) AS last_7d FROM error_log WHERE resolved = 0", [$last24h, $last7d]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 252;
				$errorLog->errorMsg = 'Database error (GET /error-log)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'ErrorReport.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$row = $result->fetch_assoc();
			$summary = array(
				'total_unresolved' => intval($row['total_unresolved']),
				'last_24h' => intval($row['last_24h']),
				'last_7d' => intval($row['last_7d'])
			);

			// 2. By error number (last 7d)
			$result = $db->query("SELECT errorNumber, errorMessage, COUNT(*) AS count FROM error_log WHERE resolved = 0 AND timestamp >= ? GROUP BY errorNumber, errorMessage ORDER BY count DESC LIMIT 20", [$last7d]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 252;
				$errorLog->errorMsg = 'Database error (GET /error-log)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'ErrorReport.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$byErrorNumber = array();
			while($row = $result->fetch_assoc()){
				$byErrorNumber[] = array(
					'error_number' => $row['errorNumber'],
					'error_message' => $row['errorMessage'],
					'count' => intval($row['count'])
				);
			}

			// 3. By day (last 7d)
			$result = $db->query("SELECT DATE(FROM_UNIXTIME(timestamp)) AS date, COUNT(*) AS count FROM error_log WHERE resolved = 0 AND timestamp >= ? GROUP BY DATE(FROM_UNIXTIME(timestamp)) ORDER BY date DESC", [$last7d]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 252;
				$errorLog->errorMsg = 'Database error (GET /error-log)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'ErrorReport.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$byDay = array();
			while($row = $result->fetch_assoc()){
				$byDay[] = array(
					'date' => $row['date'],
					'count' => intval($row['count'])
				);
			}

			// 4. Top IPs (last 7d, excluding 127.0.0.1)
			$result = $db->query("SELECT ipAddress, COUNT(*) AS count FROM error_log WHERE resolved = 0 AND timestamp >= ? AND ipAddress != '127.0.0.1' GROUP BY ipAddress ORDER BY count DESC LIMIT 10", [$last7d]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 252;
				$errorLog->errorMsg = 'Database error (GET /error-log)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'ErrorReport.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$topIPs = array();
			while($row = $result->fetch_assoc()){
				$topIPs[] = array(
					'ip_address' => $row['ipAddress'],
					'count' => intval($row['count'])
				);
			}

			// 5. Recent errors (last 50 unresolved)
			$result = $db->query("SELECT id, errorNumber, errorMessage, URI, ipAddress, timestamp, filename FROM error_log WHERE resolved = 0 ORDER BY timestamp DESC LIMIT 50");
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 252;
				$errorLog->errorMsg = 'Database error (GET /error-log)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'ErrorReport.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$recentErrors = array();
			while($row = $result->fetch_assoc()){
				$recentErrors[] = array(
					'id' => $row['id'],
					'error_number' => $row['errorNumber'],
					'error_message' => $row['errorMessage'],
					'uri' => $row['URI'],
					'ip_address' => $row['ipAddress'],
					'timestamp' => intval($row['timestamp']),
					'filename' => $row['filename']
				);
			}

			$db->close();

			// Build response
			$this->json['object'] = 'error_report';
			$this->json['url'] = '/error-log';
			$this->json['summary'] = $summary;
			$this->json['by_error_number'] = $byErrorNumber;
			$this->json['by_day'] = $byDay;
			$this->json['top_ips'] = $topIPs;
			$this->json['recent_errors'] = $recentErrors;
		}
	}

	public function api($method, $function, $id, $apiKey){
		switch($method){
			case 'GET':
				switch($function){
					case '':
					case null:
						// GET /error-log
						$this->generateReport($apiKey);
						if($this->error){
							$this->json['error'] = true;
							$this->json['error_msg'] = $this->errorMsg;
						}
						break;
					default:
						$this->json['error'] = true;
						$this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';
						$this->responseCode = 404;

						$errorLog = new LogError();
						$errorLog->errorNumber = 151;
						$errorLog->errorMsg = 'Invalid function (/error-log)';
						$errorLog->badData = $function;
						$errorLog->filename = 'ErrorReport.class.php';
						$errorLog->write();
				}
				break;
			default:
				// Unsupported Method - Method Not Allowed
				$this->json['error'] = true;
				$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
				$this->responseCode = 405;
				$this->responseHeader = 'Allow: GET';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 141;
				$errorLog->errorMsg = 'Invalid Method (/error-log)';
				$errorLog->badData = $method;
				$errorLog->filename = 'ErrorReport.class.php';
				$errorLog->write();
		}
	}
}
?>
