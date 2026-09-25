<?php

/**
 * GET /metrics — admin-only feed for the catalog health dashboard.
 *
 * Serves metrics_daily (written nightly by cron/snapshot-metrics.php, replayed
 * back to 2017 by cron/backfill-metrics.php) as chartable series. Raw counts
 * only, same as the table: ratios and health scores belong at display time.
 *
 * Two regimes, kept separate on purpose:
 *   history — total_* and created_* only, the metrics the backfill can replay
 *             from createdAt. A survivor curve: it counts only records that
 *             still exist, so it runs low wherever something was later deleted.
 *   live    — every other metric, but only from the first real snapshot. That
 *             date is detected from the data (the first users_total row), not
 *             hardcoded.
 *
 * A "since <date>" delta for a total must be differenced against the history
 * series; reading it from the live series silently returns the whole total.
 */
class MetricsReport {

    // Validation
    public $error = false;
    public $errorMsg = null;

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    public function generateReport($apiKey){
        // Admin-only
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
                $errorLog->errorNumber = 306;
                $errorLog->errorMsg = 'Unauthorized: Not admin (GET /metrics)';
                $errorLog->badData = "apiKey: $apiKey";
                $errorLog->filename = 'MetricsReport.class.php';
                $errorLog->write();
            }
        }else{
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 306;
            $errorLog->errorMsg = 'Invalid API Key (GET /metrics)';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'MetricsReport.class.php';
            $errorLog->write();
        }

        if(!$this->error){
            $db = new Database();
            $this->buildReport($db);
            $db->close();
        }
    }

    /**
     * Query metrics_daily and fill $this->json. Takes the Database as an
     * argument so an offline harness can feed it CSV-backed fixtures.
     */
    public function buildReport($db){
        $historyMetrics = array();
        foreach(Metrics::ENTITIES as $entity){
            $historyMetrics[] = "total_$entity";
            foreach(array(1, 7, 30, 365) as $window){
                $historyMetrics[] = "created_{$entity}_{$window}d";
            }
        }
        $inList = implode(', ', array_fill(0, count($historyMetrics), '?'));

        // Every snapshot date, oldest first — the history regime's x-axis
        $allDates = array();
        $result = $db->query("SELECT DISTINCT snapshotDate FROM metrics_daily ORDER BY snapshotDate");
        if($this->dbError($db, $result, 'all dates')){
            return;
        }
        while($row = $result->fetch_row()){
            $allDates[] = $row[0];
        }

        $result = $db->query("SELECT snapshotDate, metric, value FROM metrics_daily WHERE dimension = '' AND metric IN ($inList)", $historyMetrics);
        if($this->dbError($db, $result, 'history series')){
            return;
        }
        $historySeries = array();
        while($row = $result->fetch_assoc()){
            $historySeries[$row['metric']][$row['snapshotDate']] = intval($row['value']);
        }

        // The live window starts the day the snapshot cron first ran — the
        // day every current-state metric appears.
        $result = $db->query("SELECT MIN(snapshotDate) FROM metrics_daily WHERE metric = 'users_total'");
        if($this->dbError($db, $result, 'live start')){
            return;
        }
        $row = $result->fetch_row();
        $liveStart = ($row === null) ? null : $row[0];

        $liveSeries = array();
        $liveDates = array();
        if($liveStart !== null){
            $params = array_merge(array($liveStart), $historyMetrics);
            $result = $db->query("SELECT snapshotDate, metric, dimension, value FROM metrics_daily WHERE snapshotDate >= ? AND metric NOT IN ($inList)", $params);
            if($this->dbError($db, $result, 'live series')){
                return;
            }
            while($row = $result->fetch_assoc()){
                $key = $row['metric'];
                if($row['dimension'] !== ''){
                    $key .= '|' . $row['dimension'];
                }
                $liveSeries[$key][$row['snapshotDate']] = intval($row['value']);
            }

            // The live x-axis is the days the snapshot actually ran, so a
            // partial-failure day doesn't stretch every chart
            if(isset($liveSeries['users_total'])){
                $liveDates = array_keys($liveSeries['users_total']);
                sort($liveDates);
            }
        }
        ksort($liveSeries);

        // Pad each series over its regime's dates; a missing day is null
        $historyOut = array();
        foreach($historyMetrics as $metric){
            $series = array();
            foreach($allDates as $date){
                $series[] = isset($historySeries[$metric][$date]) ? $historySeries[$metric][$date] : null;
            }
            $historyOut[$metric] = $series;
        }

        $liveOut = array();
        foreach($liveSeries as $key => $byDate){
            $series = array();
            foreach($liveDates as $date){
                $series[] = isset($byDate[$date]) ? $byDate[$date] : null;
            }
            $liveOut[$key] = $series;
        }

        // Latest value of every series — history and live — on the newest
        // snapshot day
        $latest = array();
        if(!empty($liveDates)){
            $lastDate = end($liveDates);
            foreach($historySeries as $metric => $byDate){
                if(isset($byDate[$lastDate])){
                    $latest[$metric] = $byDate[$lastDate];
                }
            }
            foreach($liveSeries as $key => $byDate){
                if(isset($byDate[$lastDate])){
                    $latest[$key] = $byDate[$lastDate];
                }
            }
        }
        ksort($latest);

        $this->json['object'] = 'metrics_report';
        $this->json['url'] = '/metrics';
        $this->json['as_of'] = empty($allDates) ? null : end($allDates);
        $this->json['history'] = array('dates' => $allDates, 'series' => (object)$historyOut);
        $this->json['live'] = array('dates' => $liveDates, 'series' => (object)$liveOut);
        $this->json['latest'] = (object)$latest;
    }

    private function dbError($db, $result, $stage){
        if($db->error || $result === null){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = $db->responseCode;

            $errorLog = new LogError();
            $errorLog->errorNumber = 307;
            $errorLog->errorMsg = "Database error (GET /metrics - $stage)";
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'MetricsReport.class.php';
            $errorLog->write();
            return true;
        }
        return false;
    }

    public function api($method, $function, $id, $apiKey){
        switch($method){
            case 'GET':
                switch($function){
                    case '':
                    case null:
                        // GET /metrics
                        $this->generateReport($apiKey);
                        if($this->error){
                            $this->json = array();
                            $this->json['error'] = true;
                            $this->json['error_msg'] = $this->errorMsg;
                        }
                        break;
                    default:
                        $this->json['error'] = true;
                        $this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';
                        $this->responseCode = 404;

                        $errorLog = new LogError();
                        $errorLog->errorNumber = 308;
                        $errorLog->errorMsg = 'Invalid function (/metrics)';
                        $errorLog->badData = $function;
                        $errorLog->filename = 'MetricsReport.class.php';
                        $errorLog->write();
                }
                break;
            default:
                // Unsupported Method - Method Not Allowed
                $this->json['error'] = true;
                $this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
                $this->responseCode = 405;
                $this->responseHeader = 'Allow: GET';

                $errorLog = new LogError();
                $errorLog->errorNumber = 309;
                $errorLog->errorMsg = 'Invalid Method (/metrics)';
                $errorLog->badData = $method;
                $errorLog->filename = 'MetricsReport.class.php';
                $errorLog->write();
        }
    }
}
?>
