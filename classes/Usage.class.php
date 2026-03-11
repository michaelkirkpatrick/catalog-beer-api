<?php

class Usage {

    public $id = '';
    public $apiKey = '';
    public $year = 0;
    public $month = 0;
    public $count = 0;
    public $lastUpdated = 0;
    public $requestLimit = 0;
    public $requestBuffer = 0;
    public $resetsOn = '';

    // Validation
    public $error = false;
    public $errorMsg = null;

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    public function myUsage($apiKey){
        // Get usage for the authenticated API key
        // No admin check needed — returns data for whoever's authenticated

        $apiKeys = new apiKeys();
        if($apiKeys->validate($apiKey, true)){
            // Current month and year
            $year = date('Y');
            $month = date('n');

            // Query Database
            $db = new Database();
            $result = $db->query("SELECT count, lastUpdated FROM api_usage WHERE apiKey=? AND year=? AND month=?", [$apiKey, $year, $month]);
            if(!$db->error){
                // Save to Class
                $this->apiKey = $apiKey;
                $this->year = $year;
                $this->month = $month;
                $this->requestLimit = intval($apiKeys->requestLimit);
                $this->requestBuffer = intval($apiKeys->requestBuffer);
                $this->resetsOn = date('Y-m-d', strtotime('first day of next month'));

                if($result->num_rows == 1){
                    $array = $result->fetch_assoc();
                    $this->count = intval($array['count']);
                    $this->lastUpdated = intval($array['lastUpdated']);
                }else{
                    // No usage row yet — count is 0
                    $this->count = 0;
                    $this->lastUpdated = 0;
                }
            }else{
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = $db->responseCode;
            }

            // Close Database Connection
            $db->close();
        }else{
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 143;
            $errorLog->errorMsg = 'Invalid API Key';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'API / Usage.class.php';
            $errorLog->write();
        }
    }

    public function updateUsage(){
        // Counts api_logging rows per API key per month and upserts into api_usage
        // Requires UNIQUE INDEX on api_usage (apiKey, year, month)

        $db = new Database();
        if($db->error) return;

        // Determine target year and month
        $result = $db->query("SELECT MAX(lastUpdated) AS lastUpdate FROM api_usage");
        if($db->error){ $db->close(); return; }
        $row = $result->fetch_assoc();

        if(!empty($row['lastUpdate'])){
            $year = (int)date('Y', $row['lastUpdate']);
            $month = (int)date('n', $row['lastUpdate']);
        }else{
            // No existing usage data, default to current month
            $year = (int)date('Y');
            $month = (int)date('n');
        }

        // Set timestamps for the month
        $startingTimestamp = mktime(0, 0, 0, $month, 1, $year);
        $endingTimestamp = mktime(23, 59, 59, $month, (int)date('t', $startingTimestamp), $year);

        // Count all API calls per key for the period and upsert in one query
        $now = time();
        $db->query("INSERT INTO api_usage (id, apiKey, year, month, count, lastUpdated) SELECT UUID(), apiKey, ?, ?, COUNT(id), ? FROM api_logging WHERE timestamp BETWEEN ? AND ? GROUP BY apiKey ON DUPLICATE KEY UPDATE count=VALUES(count), lastUpdated=VALUES(lastUpdated)", [$year, $month, $now, $startingTimestamp, $endingTimestamp]);

        $db->close();
    }

    public function pruneApiLogging($monthsToKeep = 3){
        // Deletes api_logging rows older than $monthsToKeep months

        $db = new Database();
        if($db->error) return;

        // Calculate cutoff: midnight on the 1st, $monthsToKeep months ago
        $cutoffTimestamp = mktime(0, 0, 0, (int)date('n') - $monthsToKeep, 1, (int)date('Y'));

        $db->query("DELETE FROM api_logging WHERE timestamp < ?", [$cutoffTimestamp]);
        if($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 226;
            $errorLog->errorMsg = 'Failed to prune api_logging';
            $errorLog->badData = "cutoff timestamp: $cutoffTimestamp";
            $errorLog->filename = 'Usage.class.php';
            $errorLog->write();
        }

        $db->close();
    }

    public function listAllUsage($apiKey){
        // Admin-only: Get all users' API usage for the last 13 months
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
                $errorLog->errorNumber = 249;
                $errorLog->errorMsg = 'Unauthorized: Not admin (GET /usage)';
                $errorLog->badData = "apiKey: $apiKey";
                $errorLog->filename = 'Usage.class.php';
                $errorLog->write();
            }
        }else{
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 249;
            $errorLog->errorMsg = 'Invalid API Key (GET /usage)';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'Usage.class.php';
            $errorLog->write();
        }

        if(!$this->error){
            // Calculate 13 months ago
            $currentMonth = (int)date('n');
            $currentYear = (int)date('Y');
            $startMonth = $currentMonth - 12;
            $startYear = $currentYear;
            if($startMonth <= 0){
                $startMonth += 12;
                $startYear--;
            }

            $db = new Database();
            $result = $db->query("SELECT u.name, u.email, au.apiKey, au.year, au.month, au.count FROM api_usage au LEFT JOIN api_keys ak ON au.apiKey = ak.id LEFT JOIN users u ON ak.userID = u.id WHERE (au.year > ? OR (au.year = ? AND au.month >= ?)) ORDER BY u.name ASC, au.year DESC, au.month DESC", [$startYear, $startYear, $startMonth]);
            if(!$db->error){
                $data = array();
                while($row = $result->fetch_assoc()){
                    $data[] = array(
                        'name' => $row['name'] ?? '(deleted user)',
                        'email' => $row['email'] ?? null,
                        'api_key' => $row['apiKey'],
                        'year' => intval($row['year']),
                        'month' => intval($row['month']),
                        'count' => intval($row['count'])
                    );
                }
                $this->json['object'] = 'list';
                $this->json['url'] = '/usage';
                $this->json['data'] = $data;
            }else{
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = $db->responseCode;

                $errorLog = new LogError();
                $errorLog->errorNumber = 250;
                $errorLog->errorMsg = 'Database error (GET /usage)';
                $errorLog->badData = $db->errorMsg;
                $errorLog->filename = 'Usage.class.php';
                $errorLog->write();
            }
            $db->close();
        }
    }

    public function api($method, $function, $id, $apiKey){
        switch($method){
            case 'GET':
                switch($function){
                    case '':
                    case null:
                        // GET /usage — Admin-only: list all usage
                        $this->listAllUsage($apiKey);
                        if($this->error){
                            $this->json['error'] = true;
                            $this->json['error_msg'] = $this->errorMsg;
                        }
                        break;
                    case 'my-usage':
                        // GET /usage/my-usage — Authenticated user's own usage + limits
                        $this->myUsage($apiKey);
                        if(!$this->error){
                            $this->json['object'] = 'usage';
                            $this->json['api_key'] = $this->apiKey;
                            $this->json['year'] = intval($this->year);
                            $this->json['month'] = intval($this->month);
                            $this->json['count'] = intval($this->count);
                            $this->json['request_limit'] = $this->requestLimit;
                            $this->json['request_buffer'] = $this->requestBuffer;
                            $this->json['resets_on'] = $this->resetsOn;
                            $this->json['last_updated'] = $this->lastUpdated;
                        }else{
                            $this->json['error'] = true;
                            $this->json['error_msg'] = $this->errorMsg;
                        }
                        break;
                    default:
                        $this->json['error'] = true;
                        $this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';
                        $this->responseCode = 404;

                        $errorLog = new LogError();
                        $errorLog->errorNumber = 130;
                        $errorLog->errorMsg = 'Invalid function (/usage)';
                        $errorLog->badData = $function;
                        $errorLog->filename = 'API / Usage.class.php';
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
                $errorLog->errorMsg = 'Invalid Method (/usage)';
                $errorLog->badData = $method;
                $errorLog->filename = 'API / Usage.class.php';
                $errorLog->write();
        }
    }
}
?>
