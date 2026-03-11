<?php

class Activity {

	// Validation
	public $error = false;
	public $errorMsg = null;

	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();

	public function generateReport($apiKey){
		// Admin-only: Get activity report from api_logging
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
				$errorLog->errorNumber = 253;
				$errorLog->errorMsg = 'Unauthorized: Not admin (GET /activity)';
				$errorLog->badData = "apiKey: $apiKey";
				$errorLog->filename = 'Activity.class.php';
				$errorLog->write();
			}
		}else{
			// Invalid API Key
			$this->error = true;
			$this->errorMsg = 'Invalid API Key.';
			$this->responseCode = 404;

			$errorLog = new LogError();
			$errorLog->errorNumber = 253;
			$errorLog->errorMsg = 'Invalid API Key (GET /activity)';
			$errorLog->badData = $apiKey;
			$errorLog->filename = 'Activity.class.php';
			$errorLog->write();
		}

		if(!$this->error){
			$db = new Database();

			// 3 calendar months back (matches pruneApiLogging retention)
			$cutoffTimestamp = mktime(0, 0, 0, (int)date('n') - 3, 1, (int)date('Y'));

			// 1. Write Activity Summary — by resource and action (successful only)
			$result = $db->query("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(uri, '?', 1), '/', 2), '/', -1) AS resource, SUM(CASE WHEN method = 'POST' THEN 1 ELSE 0 END) AS created, SUM(CASE WHEN method IN ('PUT', 'PATCH') THEN 1 ELSE 0 END) AS updated, SUM(CASE WHEN method = 'DELETE' THEN 1 ELSE 0 END) AS deleted FROM api_logging WHERE method IN ('POST', 'PUT', 'PATCH', 'DELETE') AND responseCode >= 200 AND responseCode < 300 AND timestamp >= ? GROUP BY resource ORDER BY COUNT(*) DESC", [$cutoffTimestamp]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 254;
				$errorLog->errorMsg = 'Database error (GET /activity - write summary)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'Activity.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$writeSummary = array();
			while($row = $result->fetch_assoc()){
				$writeSummary[] = array(
					'resource' => $row['resource'],
					'created' => intval($row['created']),
					'updated' => intval($row['updated']),
					'deleted' => intval($row['deleted'])
				);
			}

			// 2. Top Contributors — successful writes, grouped by user
			$result = $db->query("SELECT u.name, u.email, SUM(CASE WHEN al.method = 'POST' THEN 1 ELSE 0 END) AS created, SUM(CASE WHEN al.method IN ('PUT', 'PATCH') THEN 1 ELSE 0 END) AS updated, COUNT(*) AS total FROM api_logging al LEFT JOIN api_keys ak ON al.apiKey = ak.id LEFT JOIN users u ON ak.userID = u.id WHERE al.method IN ('POST', 'PUT', 'PATCH', 'DELETE') AND al.responseCode >= 200 AND al.responseCode < 300 AND al.timestamp >= ? GROUP BY al.apiKey, u.name, u.email ORDER BY total DESC LIMIT 10", [$cutoffTimestamp]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 254;
				$errorLog->errorMsg = 'Database error (GET /activity - top contributors)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'Activity.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$topContributors = array();
			while($row = $result->fetch_assoc()){
				$topContributors[] = array(
					'name' => $row['name'] ?? '(deleted user)',
					'email' => $row['email'] ?? null,
					'created' => intval($row['created']),
					'updated' => intval($row['updated']),
					'total' => intval($row['total'])
				);
			}

			// 3. Recent Activity — last 50 write operations (all response codes)
			$result = $db->query("SELECT al.timestamp, u.name AS user_name, al.method, al.uri, al.responseCode FROM api_logging al LEFT JOIN api_keys ak ON al.apiKey = ak.id LEFT JOIN users u ON ak.userID = u.id WHERE al.method IN ('POST', 'PUT', 'PATCH', 'DELETE') AND al.timestamp >= ? ORDER BY al.timestamp DESC LIMIT 50", [$cutoffTimestamp]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 254;
				$errorLog->errorMsg = 'Database error (GET /activity - recent activity)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'Activity.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			$recentActivity = array();
			while($row = $result->fetch_assoc()){
				$recentActivity[] = array(
					'timestamp' => intval($row['timestamp']),
					'user_name' => $row['user_name'] ?? '(deleted user)',
					'method' => $row['method'],
					'uri' => $row['uri'],
					'response_code' => intval($row['responseCode'])
				);
			}

			// 4. GET Traffic — grouped by endpoint pattern
			$result = $db->query("SELECT SUBSTRING_INDEX(uri, '?', 1) AS clean_uri, COUNT(*) AS count FROM api_logging WHERE method = 'GET' AND timestamp >= ? GROUP BY clean_uri ORDER BY count DESC", [$cutoffTimestamp]);
			if($db->error){
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;

				$errorLog = new LogError();
				$errorLog->errorNumber = 254;
				$errorLog->errorMsg = 'Database error (GET /activity - read traffic)';
				$errorLog->badData = $db->errorMsg;
				$errorLog->filename = 'Activity.class.php';
				$errorLog->write();
				$db->close();
				return;
			}
			// Collapse UUIDs and aggregate
			$getPatterns = array();
			$getTotal = 0;
			while($row = $result->fetch_assoc()){
				$pattern = preg_replace('#/[-0-9a-f]{36}#', '/{id}', $row['clean_uri']);
				if(!isset($getPatterns[$pattern])){
					$getPatterns[$pattern] = 0;
				}
				$getPatterns[$pattern] += intval($row['count']);
				$getTotal += intval($row['count']);
			}
			arsort($getPatterns);
			$getTraffic = array();
			foreach($getPatterns as $endpoint => $count){
				$getTraffic[] = array(
					'endpoint' => $endpoint,
					'count' => $count
				);
			}

			$db->close();

			// Build response
			$this->json['object'] = 'activity_report';
			$this->json['url'] = '/activity';
			$this->json['period_months'] = 3;
			$this->json['write_activity'] = array(
				'summary' => $writeSummary,
				'top_contributors' => $topContributors,
				'recent' => $recentActivity
			);
			$this->json['read_traffic'] = array(
				'total' => $getTotal,
				'by_endpoint' => $getTraffic
			);
		}
	}

	public function api($method, $function, $id, $apiKey){
		switch($method){
			case 'GET':
				switch($function){
					case '':
					case null:
						// GET /activity
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
						$errorLog->errorMsg = 'Invalid function (/activity)';
						$errorLog->badData = $function;
						$errorLog->filename = 'Activity.class.php';
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
				$errorLog->errorMsg = 'Invalid Method (/activity)';
				$errorLog->badData = $method;
				$errorLog->filename = 'Activity.class.php';
				$errorLog->write();
		}
	}
}
?>
