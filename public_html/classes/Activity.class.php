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
        // Admin-only: the activity report. Writes come from audit_log (every
        // revision of a brewer, beer or location, with its actor — master keys
        // included), reads from api_logging, which still logs every GET.
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

            // 3 calendar months back (the same window api_logging keeps, so
            // the read-traffic half below can match it)
            $cutoffTimestamp = mktime(0, 0, 0, (int)date('n') - 3, 1, (int)date('Y'));

            // The Newman suite's entities are catalog writes like any other
            // and would be the top contributor on staging every run. Found by
            // name: a brewer called "[Postman API Test] ...", and every beer
            // and location of one (plus a beer so named under any brewer).
            $testIDs = $this->testEntityIDs($db, $cutoffTimestamp);
            if($db->error){
                $this->dbError($db, 'test entities');
                return;
            }
            $where = "al.entityType IN ('brewer', 'beer', 'location') AND al.action <> 'set' AND al.source <> 'backfill' AND al.changedAt >= ?";
            $params = array($cutoffTimestamp);
            if(!empty($testIDs)){
                $where .= " AND al.entityID NOT IN (" . implode(', ', array_fill(0, count($testIDs), '?')) . ")";
                $params = array_merge($params, $testIDs);
            }

            // 1. Write Activity Summary — by resource and action. A revert is
            // an update that happened to come from the history.
            $result = $db->query("SELECT al.entityType AS resource, SUM(al.action = 'create') AS created, SUM(al.action IN ('update', 'revert')) AS updated, SUM(al.action = 'delete') AS deleted, COUNT(*) AS total FROM audit_log al WHERE $where GROUP BY al.entityType ORDER BY total DESC", $params);
            if($db->error){
                $this->dbError($db, 'write summary');
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

            // 2. Top Contributors — by acting user. Master keys are recorded in
            // the audit log (they are not in api_logging), so the site's own
            // writes show under the master-key account.
            $result = $db->query("SELECT al.actorID, u.name, u.email, SUM(al.action = 'create') AS created, SUM(al.action IN ('update', 'revert')) AS updated, SUM(al.action = 'delete') AS deleted, COUNT(*) AS total FROM audit_log al LEFT JOIN users u ON al.actorID = u.id WHERE $where GROUP BY al.actorID, u.name, u.email ORDER BY total DESC LIMIT 10", $params);
            if($db->error){
                $this->dbError($db, 'top contributors');
                return;
            }
            $topContributors = array();
            while($row = $result->fetch_assoc()){
                $topContributors[] = array(
                    'user_id' => $row['actorID'],
                    'name' => $row['name'] ?? ($row['actorID'] === null ? '(system)' : '(deleted user)'),
                    'email' => $row['email'] ?? null,
                    'created' => intval($row['created']),
                    'updated' => intval($row['updated']),
                    'deleted' => intval($row['deleted']),
                    'total' => intval($row['total'])
                );
            }

            // 3. Recent Activity — the last 50 revisions. Names come from the
            // snapshot; a tombstone has none, so its entity's last snapshot is
            // read instead; a beer's or location's brewer is named from the
            // brewer table, or from the brewer's own last snapshot when it has
            // since been deleted.
            $result = $db->query("SELECT al.id, al.entityType, al.entityID, al.action, al.source, al.note, al.changedAt, al.revertOf, al.actorID, al.snapshot, u.name AS actorName FROM audit_log al LEFT JOIN users u ON al.actorID = u.id WHERE $where ORDER BY al.id DESC LIMIT 50", $params);
            if($db->error){
                $this->dbError($db, 'recent activity');
                return;
            }
            $recent = array();
            while($row = $result->fetch_assoc()){
                $recent[] = $row;
            }
            $recentActivity = array();
            $brewerIDs = array();
            foreach($recent as $row){
                $snapshot = $row['snapshot'] === null ? null : json_decode($row['snapshot'], true);
                if(!is_array($snapshot)){
                    $snapshot = $this->lastSnapshot($db, $row['entityType'], $row['entityID']);
                }
                $entry = array(
                    'audit_id' => intval($row['id']),
                    'timestamp' => intval($row['changedAt']),
                    'user_name' => $row['actorName'] ?? ($row['actorID'] === null ? ucfirst($row['source']) : '(deleted user)'),
                    'action' => $row['action'],
                    'source' => $row['source'],
                    'entity_type' => $row['entityType'],
                    'entity_id' => $row['entityID'],
                    'note' => $row['note'],
                    'revert_of' => $row['revertOf'] === null ? null : intval($row['revertOf'])
                );
                if(is_array($snapshot)){
                    $name = $snapshot['name'] ?? null;
                    if(($name === null || $name === '') && $row['entityType'] === 'location'){
                        $name = $snapshot['locality'] ?? null;
                    }
                    if($name !== null && $name !== ''){
                        $entry['resource_name'] = $name;
                    }
                    if($row['entityType'] !== 'brewer' && !empty($snapshot['brewerID'])){
                        $entry['brewer_id'] = $snapshot['brewerID'];
                        $brewerIDs[$snapshot['brewerID']] = true;
                    }
                }
                $recentActivity[] = $entry;
            }
            if(!empty($brewerIDs)){
                $names = $this->brewerNames($db, array_keys($brewerIDs));
                foreach($recentActivity as &$entry){
                    if(isset($entry['brewer_id']) && isset($names[$entry['brewer_id']])){
                        $entry['brewer_name'] = $names[$entry['brewer_id']];
                    }
                }
                unset($entry);
            }
            if($db->error){
                $this->dbError($db, 'recent activity names');
                return;
            }

            // 4. GET Traffic — grouped by endpoint pattern, still from
            // api_logging: reads are not audited.
            // durationMs is null on rows from before 6.7.0; the sums skip them.
            $result = $db->query("SELECT SUBSTRING_INDEX(uri, '?', 1) AS clean_uri, COUNT(*) AS count, SUM(durationMs) AS duration_sum, SUM(durationMs IS NOT NULL) AS timed, MAX(durationMs) AS max_ms FROM api_logging WHERE method = 'GET' AND timestamp >= ? GROUP BY clean_uri ORDER BY count DESC", [$cutoffTimestamp]);
            if($db->error){
                $this->dbError($db, 'read traffic');
                return;
            }
            // Collapse UUIDs and aggregate; the average is weighted by the
            // rows that carry a duration
            $getPatterns = array();
            $getTotal = 0;
            while($row = $result->fetch_assoc()){
                $pattern = preg_replace('#/[-0-9a-f]{36}#', '/{id}', $row['clean_uri']);
                if(!isset($getPatterns[$pattern])){
                    $getPatterns[$pattern] = array('count' => 0, 'duration_sum' => 0, 'timed' => 0, 'max_ms' => null);
                }
                $getPatterns[$pattern]['count'] += intval($row['count']);
                $getPatterns[$pattern]['duration_sum'] += intval($row['duration_sum']);
                $getPatterns[$pattern]['timed'] += intval($row['timed']);
                if($row['max_ms'] !== null){
                    $getPatterns[$pattern]['max_ms'] = max(intval($row['max_ms']), intval($getPatterns[$pattern]['max_ms']));
                }
                $getTotal += intval($row['count']);
            }
            uasort($getPatterns, function($a, $b){ return $b['count'] <=> $a['count']; });
            $getTraffic = array();
            foreach($getPatterns as $endpoint => $agg){
                $getTraffic[] = array(
                    'endpoint' => $endpoint,
                    'count' => $agg['count'],
                    'avg_ms' => $agg['timed'] > 0 ? intval(round($agg['duration_sum'] / $agg['timed'])) : null,
                    'max_ms' => $agg['max_ms']
                );
            }

            $db->close();

            // Build response
            $this->json['object'] = 'activity_report';
            $this->json['url'] = '/activity';
            $this->json['period_months'] = 3;
            $this->json['source'] = 'audit_log';
            $this->json['test_entities_excluded'] = count($testIDs);
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

    // A database error on one of the report's queries: 500 and a log row
    private function dbError($db, $step){
        $this->error = true;
        $this->errorMsg = $db->errorMsg;
        $this->responseCode = $db->responseCode;

        $errorLog = new LogError();
        $errorLog->errorNumber = 254;
        $errorLog->errorMsg = 'Database error (GET /activity - ' . $step . ')';
        $errorLog->badData = $db->errorMsg;
        $errorLog->filename = 'Activity.class.php';
        $errorLog->write();
        $db->close();
    }

    // The ids of every entity the Newman suite wrote in the window: brewers
    // named with the test prefix, their beers and locations, and any beer so
    // named. Found from the snapshots, so the suite's end-of-run deletes (the
    // tombstones share the entity id) are excluded with the rest.
    const TEST_PREFIX = '[Postman API Test]';
    private function testEntityIDs($db, $cutoffTimestamp){
        $ids = array();
        $result = $db->query("SELECT DISTINCT entityID FROM audit_log WHERE entityType = 'brewer' AND changedAt >= ? AND snapshot IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.name')) LIKE ?", [$cutoffTimestamp, self::TEST_PREFIX . '%']);
        if($db->error){
            return array();
        }
        $brewerIDs = array();
        while($row = $result->fetch_assoc()){
            $brewerIDs[] = $row['entityID'];
            $ids[$row['entityID']] = true;
        }
        $sql = "SELECT DISTINCT entityID FROM audit_log WHERE entityType IN ('beer', 'location') AND changedAt >= ? AND snapshot IS NOT NULL AND (JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.name')) LIKE ?";
        $params = array($cutoffTimestamp, self::TEST_PREFIX . '%');
        if(!empty($brewerIDs)){
            $sql .= " OR JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.brewerID')) IN (" . implode(', ', array_fill(0, count($brewerIDs), '?')) . ")";
            $params = array_merge($params, $brewerIDs);
        }
        $result = $db->query($sql . ")", $params);
        if($db->error){
            return array();
        }
        while($row = $result->fetch_assoc()){
            $ids[$row['entityID']] = true;
        }
        return array_keys($ids);
    }

    // The newest snapshot an entity ever had (for a tombstone's name)
    private function lastSnapshot($db, $entityType, $entityID){
        $result = $db->query("SELECT snapshot FROM audit_log WHERE entityType = ? AND entityID = ? AND snapshot IS NOT NULL ORDER BY id DESC LIMIT 1", [$entityType, $entityID]);
        if($db->error || $result->num_rows !== 1){
            return null;
        }
        $decoded = json_decode($result->fetch_assoc()['snapshot'], true);
        return is_array($decoded) ? $decoded : null;
    }

    // id => name for a set of brewers: the live row, else the last snapshot
    private function brewerNames($db, array $brewerIDs){
        $names = array();
        $placeholders = implode(', ', array_fill(0, count($brewerIDs), '?'));
        $result = $db->query("SELECT id, name FROM brewer WHERE id IN ($placeholders)", $brewerIDs);
        if($db->error){
            return $names;
        }
        while($row = $result->fetch_assoc()){
            $names[$row['id']] = $row['name'];
        }
        foreach($brewerIDs as $brewerID){
            if(!isset($names[$brewerID])){
                $snapshot = $this->lastSnapshot($db, 'brewer', $brewerID);
                if(is_array($snapshot) && !empty($snapshot['name'])){
                    $names[$brewerID] = $snapshot['name'];
                }
            }
        }
        return $names;
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
