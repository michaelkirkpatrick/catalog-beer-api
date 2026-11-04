<?php
/*--
GET /activity — the admin's activity report.

Two windows: ?period=month (the default, the calendar month to date) and
?period=3m (three calendar months back, the window api_logging keeps). The
report answers three questions in one request: what the API's keys are doing
(a row per key with a drill-down for the busiest), what the catalog's writers
changed (from audit_log, the Newman suite's entities excluded by name), and
what the usage is worth (committed and forecast charges, what invoices on
the 4th, what rolls forward under the floor, what the free tier refused).

Reads come from api_logging, which logs every request of a non-master key,
and from api_usage for closed months (the metering table the billing cron
reads, recounted nightly from api_logging). The current month is always read
live from api_logging, so the two never disagree about a closed month and
the open one is never a day stale. Writes come from audit_log.

Cost: every all-keys query runs over the period with `method IN (...)` so
the covering index idx_method_ts (method, timestamp, ...) serves the range;
the per-key breakdowns (endpoint patterns, hours, agents, search terms) are
only computed for the month view, where they are cheap, so the 3m view costs
about what the old three-month report did.
--*/

class Activity {

    // Validation
    public $error = false;
    public $errorMsg = null;

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    const PERIODS = array('month', '3m');
    const DETAIL_KEYS = 40;     // the busiest keys carry the per-key drill-down
    const SEARCH_TERMS = 12;    // sample search terms per key
    const METHODS = "'GET', 'POST', 'PUT', 'PATCH', 'DELETE'";
    const UUID_RE = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /* ----- Pure helpers (offline test: tests/activity.php) ----- */

    // The family a request belongs to, for the per-key mix bar. $endpoint is
    // the URI path with UUIDs collapsed to {id} and the query string cut.
    public static function family($method, $endpoint){
        if($method !== 'GET'){
            return 'write';
        }
        if(preg_match('#^/(beer|brewer)/search$#', $endpoint)){
            return 'search';
        }
        if($endpoint === '/brewer/{id}/beer'){
            return 'brewer_beer';
        }
        if(preg_match('#^/(beer|brewer|location)/\{id\}$#', $endpoint)){
            return 'detail';
        }
        if(preg_match('#^/(beer|brewer|location)$#', $endpoint)){
            return 'list';
        }
        return 'other';
    }

    // The q= of a search request's logged URI, decoded and trimmed; null
    // when the request carried none.
    public static function searchTerm($uri){
        $query = parse_url($uri, PHP_URL_QUERY);
        if(!is_string($query) || $query === ''){
            return null;
        }
        parse_str($query, $params);
        if(!isset($params['q']) || !is_string($params['q'])){
            return null;
        }
        $term = trim(preg_replace('/\s+/', ' ', $params['q']));
        return $term === '' ? null : $term;
    }

    // A month's request count projected from the pace so far: the count
    // scaled from the seconds elapsed to the seconds in the month. The first
    // hour of a month is never extrapolated as if it were the month.
    public static function projectedCount($count, $elapsedSeconds, $monthSeconds){
        if($count <= 0 || $monthSeconds <= 0){
            return 0;
        }
        $elapsed = max($elapsedSeconds, 3600);
        if($elapsed >= $monthSeconds){
            return (int)$count;
        }
        return (int)round($count * $monthSeconds / $elapsed);
    }

    // A logged URI's endpoint pattern: the path with UUIDs collapsed
    public static function endpointPattern($uri){
        $path = (string)parse_url($uri, PHP_URL_PATH);
        return preg_replace('#' . self::UUID_RE . '#', '/{id}', $path);
    }

    /* ----- The report ----- */

    public function generateReport($apiKey, $period = 'month'){
        // Admin-only
        $apiKeys = new apiKeys();
        if($apiKeys->validate($apiKey, true)){
            $users = new Users();
            $users->validate($apiKeys->userID, true);

            if(!$users->admin){
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
        if($this->error){
            return;
        }

        if(!in_array($period, self::PERIODS, true)){
            $this->error = true;
            $this->errorMsg = "Invalid period. Use 'month' (the calendar month to date) or '3m' (the last three months).";
            $this->responseCode = 400;

            $errorLog = new LogError();
            $errorLog->errorNumber = 379;
            $errorLog->errorMsg = 'Invalid period (GET /activity)';
            $errorLog->badData = is_string($period) ? $period : gettype($period);
            $errorLog->filename = 'Activity.class.php';
            $errorLog->write();
            return;
        }

        $db = new Database();

        $now = time();
        $thisYear = (int)date('Y', $now);
        $thisMonth = (int)date('n', $now);
        $monthStart = mktime(0, 0, 0, $thisMonth, 1, $thisYear);
        $monthEnd = mktime(0, 0, 0, $thisMonth + 1, 1, $thisYear);
        // Three calendar months back: the same window api_logging keeps
        $threeMonthsBack = mktime(0, 0, 0, $thisMonth - 3, 1, $thisYear);
        $since = ($period === 'month') ? $monthStart : $threeMonthsBack;
        $lastMonthStart = mktime(0, 0, 0, $thisMonth - 1, 1, $thisYear);
        $lastYear = (int)date('Y', $lastMonthStart);
        $lastMonth = (int)date('n', $lastMonthStart);

        $billing = new Billing();

        // ----- 1. Every key with its owner -----
        $keyInfo = array();
        $siteKeys = array();
        $result = $db->query("SELECT k.id, k.userID, k.requestLimit, k.requestBuffer, k.billingEnabled+0 AS billingEnabled, k.monthlySpendCapCents, u.name, u.email, u.admin+0 AS admin, u.emailVerified+0 AS emailVerified, u.createdAt, u.signupIP FROM api_keys k INNER JOIN users u ON u.id = k.userID");
        if($db->error){
            $this->dbError($db, 'keys');
            return;
        }
        while($row = $result->fetch_assoc()){
            $keyInfo[$row['id']] = $row;
            // The site's own keys: an admin's. They are reported apart so the
            // agent's imports never dwarf the table of real users.
            if((int)$row['admin'] === 1){
                $siteKeys[$row['id']] = true;
            }
        }

        // ----- 2. Hours: per key (every key, index-only), for the drill-down
        // strips, the daily series and each key's live count for the month -----
        $hours = array();         // apiKey => [hourTs => n]
        $live = array();          // apiKey => count this month
        $result = $db->query("SELECT apiKey, timestamp - (timestamp % 3600) AS h, COUNT(*) AS n FROM api_logging WHERE method IN (" . self::METHODS . ") AND timestamp >= ? GROUP BY apiKey, h", [$since]);
        if($db->error){
            $this->dbError($db, 'hours');
            return;
        }
        while($row = $result->fetch_assoc()){
            $h = (int)$row['h'];
            $n = (int)$row['n'];
            $hours[$row['apiKey']][$h] = $n;
            if($h >= $monthStart){
                $live[$row['apiKey']] = ($live[$row['apiKey']] ?? 0) + $n;
            }
        }

        // The keys that get a drill-down: the busiest non-site keys this
        // month. The queries that must read whole rows (durations, user agents,
        // addresses, search terms) are asked for these keys only and seek them
        // through idx_apiKey_ts; a whole-window read of those columns is a
        // table scan on a box whose buffer pool is smaller than the table.
        $detailKeys = array();
        if($period === 'month'){
            $ranked = array();
            foreach($live as $k => $n){
                if(!isset($siteKeys[$k]) && isset($keyInfo[$k]) && $n > 0){
                    $ranked[$k] = $n;
                }
            }
            arsort($ranked);
            $detailKeys = array_slice(array_keys($ranked), 0, self::DETAIL_KEYS);
        }
        $inDetail = empty($detailKeys) ? '' : "apiKey IN (" . implode(', ', array_fill(0, count($detailKeys), '?')) . ") AND ";

        // ----- 3. The month's drill-down keys: method, endpoint and status -----
        // Grouped on the path as logged; the ids are collapsed to {id} here,
        // since a regex per row in SQL costs seconds on the production box.
        $detail = array();        // apiKey => endpoint rows, statuses, families, first/last
        $result = empty($detailKeys) ? null : $db->query("SELECT apiKey, method, SUBSTRING_INDEX(uri, '?', 1) AS path, responseCode, COUNT(*) AS n, SUM(durationMs) AS dsum, SUM(durationMs IS NOT NULL) AS timed, MAX(durationMs) AS dmax, MIN(timestamp) AS first, MAX(timestamp) AS last FROM api_logging WHERE " . $inDetail . "timestamp >= ? GROUP BY apiKey, method, path, responseCode", array_merge($detailKeys, [$monthStart]));
        if($db->error){
            $this->dbError($db, 'month traffic');
            return;
        }
        while($result !== null && ($row = $result->fetch_assoc())){
            $k = $row['apiKey'];
            $n = (int)$row['n'];
            $pattern = self::endpointPattern($row['path']);
            if(!isset($detail[$k])){
                $detail[$k] = array('endpoints' => array(), 'statuses' => array(), 'families' => array(), 'first' => null, 'last' => null);
            }
            $d =& $detail[$k];
            $epKey = $row['method'] . ' ' . $pattern;
            if(!isset($d['endpoints'][$epKey])){
                $d['endpoints'][$epKey] = array('method' => $row['method'], 'endpoint' => $pattern, 'count' => 0, 'ok' => 0, 'dsum' => 0, 'timed' => 0, 'max_ms' => null);
            }
            $e =& $d['endpoints'][$epKey];
            $e['count'] += $n;
            $code = (int)$row['responseCode'];
            if($code >= 200 && $code < 300){
                $e['ok'] += $n;
            }
            $e['dsum'] += (int)$row['dsum'];
            $e['timed'] += (int)$row['timed'];
            if($row['dmax'] !== null){
                $e['max_ms'] = max((int)$row['dmax'], (int)$e['max_ms']);
            }
            unset($e);
            $d['statuses'][$code] = ($d['statuses'][$code] ?? 0) + $n;
            $family = self::family($row['method'], $pattern);
            $d['families'][$family] = ($d['families'][$family] ?? 0) + $n;
            $d['first'] = ($d['first'] === null) ? (int)$row['first'] : min($d['first'], (int)$row['first']);
            $d['last'] = ($d['last'] === null) ? (int)$row['last'] : max($d['last'], (int)$row['last']);
            unset($d);
        }

        // ----- 4. Closed months from the metering table (the window and last month) -----
        $usage = array();         // apiKey => ['YYYY-MM' => count], closed months only
        $usageSince = min($threeMonthsBack, $lastMonthStart);
        $result = $db->query("SELECT apiKey, year, month, count FROM api_usage WHERE (year * 100 + month) >= ? AND (year * 100 + month) < ?", [(int)date('Y', $usageSince) * 100 + (int)date('n', $usageSince), $thisYear * 100 + $thisMonth]);
        if($db->error){
            $this->dbError($db, 'usage');
            return;
        }
        while($row = $result->fetch_assoc()){
            $usage[$row['apiKey']][sprintf('%04d-%02d', $row['year'], $row['month'])] = (int)$row['count'];
        }

        // ----- 5. Refused at the free tier (402), per key and month -----
        $refused = array();       // apiKey => ['YYYY-MM' => n]
        $result = $db->query("SELECT apiKey, timestamp - (timestamp % 86400) AS d, COUNT(*) AS n FROM api_logging WHERE method IN (" . self::METHODS . ") AND timestamp >= ? AND responseCode = 402 GROUP BY apiKey, d", [$since]);
        if($db->error){
            $this->dbError($db, 'refused');
            return;
        }
        while($row = $result->fetch_assoc()){
            $ym = date('Y-m', (int)$row['d']);
            $refused[$row['apiKey']][$ym] = ($refused[$row['apiKey']][$ym] ?? 0) + (int)$row['n'];
        }

        // ----- 6. Month view only: clients, addresses and search terms -----
        $agents = array();        // apiKey => [agent => n]
        $ipCounts = array();      // apiKey => distinct addresses
        $searches = array();      // apiKey => ['count' => n, 'terms' => [term => n]]
        if($period === 'month'){
            $result = empty($detailKeys) ? null : $db->query("SELECT apiKey, userAgent, COUNT(*) AS n FROM api_logging WHERE " . $inDetail . "timestamp >= ? GROUP BY apiKey, userAgent", array_merge($detailKeys, [$monthStart]));
            if($db->error){
                $this->dbError($db, 'agents');
                return;
            }
            while($result !== null && ($row = $result->fetch_assoc())){
                $agents[$row['apiKey']][$row['userAgent'] ?? ''] = (int)$row['n'];
            }
            $result = empty($detailKeys) ? null : $db->query("SELECT apiKey, COUNT(DISTINCT ipAddress) AS ips FROM api_logging WHERE " . $inDetail . "timestamp >= ? GROUP BY apiKey", array_merge($detailKeys, [$monthStart]));
            if($db->error){
                $this->dbError($db, 'addresses');
                return;
            }
            while($result !== null && ($row = $result->fetch_assoc())){
                $ipCounts[$row['apiKey']] = (int)$row['ips'];
            }
            $result = empty($detailKeys) ? null : $db->query("SELECT apiKey, uri FROM api_logging WHERE " . $inDetail . "timestamp >= ? AND method = 'GET' AND (uri LIKE '/beer/search?%' OR uri LIKE '/brewer/search?%')", array_merge($detailKeys, [$monthStart]));
            if($db->error){
                $this->dbError($db, 'searches');
                return;
            }
            while($result !== null && ($row = $result->fetch_assoc())){
                $k = $row['apiKey'];
                if(!isset($searches[$k])){
                    $searches[$k] = array('count' => 0, 'terms' => array());
                }
                $searches[$k]['count']++;
                $term = self::searchTerm($row['uri']);
                if($term !== null){
                    $searches[$k]['terms'][$term] = ($searches[$k]['terms'][$term] ?? 0) + 1;
                }
            }
        }

        // ----- 7. Charges recorded for the window -----
        $charges = array();       // apiKey => ['YYYY-MM' => row]
        $pendingTotals = array(); // apiKey => cents still pending (across every month)
        $result = $db->query("SELECT apiKey, year, month, totalRequests, billableRequests, amountCents, status FROM billing_charges");
        if($db->error){
            $this->dbError($db, 'charges');
            return;
        }
        while($row = $result->fetch_assoc()){
            $charges[$row['apiKey']][sprintf('%04d-%02d', $row['year'], $row['month'])] = $row;
            if($row['status'] === 'pending'){
                $pendingTotals[$row['apiKey']] = ($pendingTotals[$row['apiKey']] ?? 0) + (int)$row['amountCents'];
            }
        }

        // ----- 8. Writes per user in the window, and new accounts -----
        $testIDs = $this->testEntityIDs($db, $since);
        if($db->error){
            $this->dbError($db, 'test entities');
            return;
        }
        $where = "al.entityType IN ('brewer', 'beer', 'location') AND al.action <> 'set' AND al.source <> 'backfill' AND al.changedAt >= ?";
        $params = array($since);
        if(!empty($testIDs)){
            $where .= " AND al.entityID NOT IN (" . implode(', ', array_fill(0, count($testIDs), '?')) . ")";
            $params = array_merge($params, $testIDs);
        }
        // One pass over the window's revisions (the rows carry their snapshot,
        // so every scan of audit_log is a read of the whole row): per actor,
        // entity type and action, from which the write summary, the top
        // contributors and each key's write count are all derived.
        $writesByUser = array();
        $writeCells = array();
        $result = $db->query("SELECT al.actorID, u.name, u.email, al.entityType, al.action, COUNT(*) AS n FROM audit_log al LEFT JOIN users u ON al.actorID = u.id WHERE $where GROUP BY al.actorID, u.name, u.email, al.entityType, al.action", $params);
        if($db->error){
            $this->dbError($db, 'writes');
            return;
        }
        while($row = $result->fetch_assoc()){
            $writeCells[] = $row;
            if($row['actorID'] !== null){
                $writesByUser[$row['actorID']] = ($writesByUser[$row['actorID']] ?? 0) + (int)$row['n'];
            }
        }
        $result = $db->query("SELECT COUNT(*) AS n FROM users WHERE createdAt >= ?", [$since]);
        if($db->error){
            $this->dbError($db, 'new accounts');
            return;
        }
        $newAccounts = (int)$result->fetch_assoc()['n'];

        // ----- Assemble: per-key totals for the period -----
        $thisYm = sprintf('%04d-%02d', $thisYear, $thisMonth);
        $windowMonths = array();  // the calendar months in the period, oldest first
        for($ts = $since; $ts < $monthEnd; $ts = mktime(0, 0, 0, (int)date('n', $ts) + 1, 1, (int)date('Y', $ts))){
            $windowMonths[] = date('Y-m', $ts);
        }
        $periodTotals = array();  // apiKey => requests in the period
        $monthsByKey = array();   // apiKey => ['YYYY-MM' => n] over the window
        $allKeys = array_unique(array_merge(array_keys($live), array_keys($usage)));
        foreach($allKeys as $k){
            $months = array();
            foreach($windowMonths as $ym){
                $n = ($ym === $thisYm) ? ($live[$k] ?? 0) : ($usage[$k][$ym] ?? 0);
                $months[$ym] = $n;
            }
            $monthsByKey[$k] = $months;
            $periodTotals[$k] = array_sum($months);
        }

        // ----- Summary -----
        $external = 0;
        $site = 0;
        $keysActive = 0;
        $keysDeleted = 0;     // keys whose account is gone (the Newman suite deletes its users; a closed account) — counted, not listed
        foreach($periodTotals as $k => $n){
            if($n <= 0){
                continue;
            }
            if(isset($siteKeys[$k])){
                $site += $n;
            }else{
                $external += $n;
                $keysActive++;
                if(!isset($keyInfo[$k])){
                    $keysDeleted++;
                }
            }
        }
        $daily = array();
        foreach($hours as $k => $map){
            foreach($map as $h => $n){
                $day = date('Y-m-d', $h);
                if(!isset($daily[$day])){
                    $daily[$day] = array('date' => $day, 'external' => 0, 'site' => 0);
                }
                $daily[$day][isset($siteKeys[$k]) ? 'site' : 'external'] += $n;
            }
        }
        ksort($daily);

        // ----- Keys: one row per non-site key with traffic, busiest first -----
        $keyRows = array();
        foreach($periodTotals as $k => $n){
            if($n <= 0 || isset($siteKeys[$k]) || !isset($keyInfo[$k])){
                continue;
            }
            $info = $keyInfo[$k];
            $row = array(
                'user_id' => $info['userID'],
                'name' => $info['name'],
                'email' => $info['email'],
                'api_key_last4' => substr($k, -4),
                'request_limit' => (int)$info['requestLimit'],
                'request_buffer' => (int)$info['requestBuffer'],
                'billing_enabled' => (int)$info['billingEnabled'] === 1,
                'spend_cap_cents' => (int)$info['monthlySpendCapCents'],
                'requests' => $n,
                'this_month' => $live[$k] ?? 0,
                'months' => $monthsByKey[$k],
                'created_at' => $info['createdAt'] === null ? null : (int)$info['createdAt'],
                'is_new' => $info['createdAt'] !== null && (int)$info['createdAt'] >= $since,
                'email_verified' => (int)$info['emailVerified'] === 1,
                'signup_ip' => $info['signupIP'],
                'writes' => $writesByUser[$info['userID']] ?? 0,
                'refused' => 0,
                '_key' => $k
            );
            foreach($windowMonths as $ym){
                $row['refused'] += $refused[$k][$ym] ?? 0;
            }
            if($period === 'month'){
                $d = $detail[$k] ?? null;
                $row['first_ts'] = $d['first'] ?? null;
                $row['last_ts'] = $d['last'] ?? null;
                $days = array();
                foreach($hours[$k] ?? array() as $h => $c){
                    $days[date('Y-m-d', $h)] = true;
                }
                $row['active_days'] = count($days);
                $row['ip_count'] = $ipCounts[$k] ?? 0;
                $row['detail'] = false;
            }
            $keyRows[] = $row;
        }
        usort($keyRows, function($a, $b){ return $b['requests'] <=> $a['requests']; });

        // The drill-down for the busiest keys (month view)
        if($period === 'month'){
            $detailSet = array_flip($detailKeys);
            foreach($keyRows as $i => &$row){
                $k = $row['_key'];
                if(!isset($detailSet[$k])){
                    continue;
                }
                $d = $detail[$k] ?? array('endpoints' => array(), 'statuses' => array(), 'families' => array());
                $endpoints = array();
                foreach($d['endpoints'] as $e){
                    $endpoints[] = array(
                        'method' => $e['method'],
                        'endpoint' => $e['endpoint'],
                        'count' => $e['count'],
                        'ok' => $e['ok'],
                        'avg_ms' => $e['timed'] > 0 ? (int)round($e['dsum'] / $e['timed']) : null,
                        'max_ms' => $e['max_ms']
                    );
                }
                usort($endpoints, function($a, $b){ return $b['count'] <=> $a['count']; });
                $agentList = array();
                foreach($agents[$k] ?? array() as $agent => $c){
                    $agentList[] = array('agent' => $agent === '' ? null : $agent, 'count' => $c);
                }
                usort($agentList, function($a, $b){ return $b['count'] <=> $a['count']; });
                $terms = $searches[$k]['terms'] ?? array();
                arsort($terms);
                $hourMap = $hours[$k] ?? array();
                ksort($hourMap);
                $row['detail'] = true;
                $row['endpoints'] = $endpoints;
                $row['statuses'] = (object)$d['statuses'];
                $row['mix'] = (object)$d['families'];
                $row['agents'] = $agentList;
                $row['hours'] = (object)$hourMap;
                $row['searches'] = array(
                    'count' => $searches[$k]['count'] ?? 0,
                    'distinct' => count($terms),
                    'top' => array_slice(array_keys($terms), 0, self::SEARCH_TERMS)
                );
            }
            unset($row);
        }

        // ----- Billing -----
        $elapsed = $now - $monthStart;
        $monthSeconds = $monthEnd - $monthStart;
        $committed = 0;
        $forecast = 0;
        $billingKeys = array();
        $ceiling = array();
        $billingMonths = $windowMonths;
        $lastYm = sprintf('%04d-%02d', $lastYear, $lastMonth);
        if(!in_array($lastYm, $billingMonths, true)){
            array_unshift($billingMonths, $lastYm);
        }
        foreach($keyInfo as $k => $info){
            $limit = (int)$info['requestLimit'];
            $buffer = (int)$info['requestBuffer'];
            $enabled = (int)$info['billingEnabled'] === 1;
            $cap = (int)$info['monthlySpendCapCents'];
            $count = $live[$k] ?? 0;

            $keyCommitted = $billing->committedCents($count, $limit, $buffer, $enabled, $cap);
            $committed += $keyCommitted;
            $projected = self::projectedCount($count, $elapsed, $monthSeconds);
            $keyForecast = $enabled ? $billing->overageCents($projected, $limit, $cap) : $keyCommitted;
            $forecast += $keyForecast;

            // A billing key: a card on file, or a charge recorded in the window
            $hasCharge = false;
            foreach($billingMonths as $ym){
                if(isset($charges[$k][$ym])){
                    $hasCharge = true;
                }
            }
            if($enabled || $hasCharge){
                $months = array();
                foreach($billingMonths as $ym){
                    $n = ($ym === $thisYm) ? $count : ($usage[$k][$ym] ?? 0);
                    $charge = $charges[$k][$ym] ?? null;
                    $status = null;
                    if($charge !== null){
                        $status = $charge['status'];
                        if($status === 'pending'){
                            $status = (($pendingTotals[$k] ?? 0) >= Billing::INVOICE_FLOOR_CENTS) ? 'invoicing' : 'rolling';
                        }
                    }
                    $months[] = array(
                        'month' => $ym,
                        'requests' => $n,
                        'overage_requests' => max(0, $n - $limit),
                        'charge_cents' => $charge !== null ? (int)$charge['amountCents'] : ($ym === $thisYm ? $keyCommitted : $billing->committedCents($n, $limit, $buffer, $enabled, $cap)),
                        'status' => $status
                    );
                }
                $billingKeys[] = array(
                    'user_id' => $info['userID'],
                    'name' => $info['name'],
                    'email' => $info['email'],
                    'api_key_last4' => substr($k, -4),
                    'billing_enabled' => $enabled,
                    'spend_cap_cents' => $cap,
                    'request_limit' => $limit,
                    'months' => $months,
                    'this_month' => array(
                        'requests' => $count,
                        'committed_cents' => $keyCommitted,
                        'projected_requests' => $projected,
                        'forecast_cents' => $keyForecast,
                        'refused' => $refused[$k][$thisYm] ?? 0
                    ),
                    'pending_cents' => $pendingTotals[$k] ?? 0
                );
            }

            // Free keys at or near the ceiling in any month of the window
            if(!$enabled && !isset($siteKeys[$k])){
                foreach($windowMonths as $ym){
                    $n = ($ym === $thisYm) ? $count : ($usage[$k][$ym] ?? 0);
                    $stop = $limit + $buffer + 1;
                    if($n >= $stop){
                        $status = 'hit';
                    }elseif($ym === $thisYm && $n >= (int)ceil($limit * 0.9)){
                        $status = 'near';
                    }else{
                        continue;
                    }
                    $ceiling[] = array(
                        'user_id' => $info['userID'],
                        'name' => $info['name'],
                        'api_key_last4' => substr($k, -4),
                        'month' => $ym,
                        'requests' => $n,
                        'request_limit' => $limit,
                        'refused' => $refused[$k][$ym] ?? 0,
                        'status' => $status
                    );
                }
            }
        }
        usort($billingKeys, function($a, $b){
            return ($b['this_month']['committed_cents'] + $b['pending_cents']) <=> ($a['this_month']['committed_cents'] + $a['pending_cents']) ?: $b['this_month']['requests'] <=> $a['this_month']['requests'];
        });
        usort($ceiling, function($a, $b){ return strcmp($b['month'], $a['month']) ?: $b['requests'] <=> $a['requests']; });

        // Last month: what was recorded, what the 4th invoices, what rolls
        $lastMonthCharges = array();
        $charged = 0;
        $invoicing = 0;
        $rolling = 0;
        foreach($charges as $k => $byMonth){
            if(!isset($byMonth[$lastYm]) || !isset($keyInfo[$k])){
                continue;
            }
            $c = $byMonth[$lastYm];
            $cents = (int)$c['amountCents'];
            $charged += $cents;
            $status = $c['status'];
            if($status === 'pending'){
                if(($pendingTotals[$k] ?? 0) >= Billing::INVOICE_FLOOR_CENTS){
                    $status = 'invoicing';
                    $invoicing += $cents;
                }else{
                    $status = 'rolling';
                    $rolling += $cents;
                }
            }
            $lastMonthCharges[] = array(
                'user_id' => $keyInfo[$k]['userID'],
                'name' => $keyInfo[$k]['name'],
                'api_key_last4' => substr($k, -4),
                'requests' => (int)$c['totalRequests'],
                'billable_requests' => (int)$c['billableRequests'],
                'amount_cents' => $cents,
                'status' => $status
            );
        }
        usort($lastMonthCharges, function($a, $b){ return $b['amount_cents'] <=> $a['amount_cents']; });
        $refusedTotal = 0;
        $refusedKeys = 0;
        $refusedByMonth = array();
        foreach($windowMonths as $ym){
            $refusedByMonth[$ym] = 0;
        }
        foreach($refused as $k => $byMonth){
            $any = false;
            foreach($byMonth as $ym => $n){
                if(isset($refusedByMonth[$ym])){
                    $refusedByMonth[$ym] += $n;
                    $refusedTotal += $n;
                    $any = true;
                }
            }
            if($any){
                $refusedKeys++;
            }
        }

        // ----- Write activity (audit_log), from the one grouped pass above -----
        $byResource = array();
        $byActor = array();
        $revisions = 0;
        foreach($writeCells as $row){
            $n = (int)$row['n'];
            $bucket = ($row['action'] === 'create') ? 'created' : (($row['action'] === 'delete') ? 'deleted' : 'updated');
            $r = $row['entityType'];
            if(!isset($byResource[$r])){
                $byResource[$r] = array('resource' => $r, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'total' => 0);
            }
            $byResource[$r][$bucket] += $n;
            $byResource[$r]['total'] += $n;
            $a = $row['actorID'] ?? '';
            if(!isset($byActor[$a])){
                $byActor[$a] = array(
                    'user_id' => $row['actorID'],
                    'name' => $row['name'] ?? ($row['actorID'] === null ? '(system)' : '(deleted user)'),
                    'email' => $row['email'] ?? null,
                    'created' => 0, 'updated' => 0, 'deleted' => 0, 'total' => 0
                );
            }
            $byActor[$a][$bucket] += $n;
            $byActor[$a]['total'] += $n;
            $revisions += $n;
        }
        usort($byResource, function($a, $b){ return $b['total'] <=> $a['total']; });
        $writeSummary = array();
        foreach($byResource as $r){
            unset($r['total']);
            $writeSummary[] = $r;
        }
        usort($byActor, function($a, $b){ return $b['total'] <=> $a['total']; });
        $topContributors = array_slice(array_values($byActor), 0, 10);

        // Recent revisions: the last 50. Names come from the snapshot; a
        // tombstone has none, so its entity's last snapshot is read instead;
        // a beer's or location's brewer is named from the brewer table, or
        // from the brewer's own last snapshot when it has since been deleted.
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

        // ----- Reads by endpoint, every key -----
        // durationMs is null on rows from before 6.7.0; the sums skip them.
        $result = $db->query("SELECT SUBSTRING_INDEX(uri, '?', 1) AS clean_uri, COUNT(*) AS count, SUM(durationMs) AS duration_sum, SUM(durationMs IS NOT NULL) AS timed, MAX(durationMs) AS max_ms FROM api_logging WHERE method = 'GET' AND timestamp >= ? GROUP BY clean_uri ORDER BY count DESC", [$since]);
        if($db->error){
            $this->dbError($db, 'read traffic');
            return;
        }
        $getPatterns = array();
        $getTotal = 0;
        while($row = $result->fetch_assoc()){
            $pattern = preg_replace('#' . self::UUID_RE . '#', '/{id}', $row['clean_uri']);
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

        // ----- The response -----
        $siteKeyList = array();
        foreach($siteKeys as $k => $true){
            if(($periodTotals[$k] ?? 0) > 0){
                $siteKeyList[] = array(
                    'user_id' => $keyInfo[$k]['userID'],
                    'name' => $keyInfo[$k]['name'],
                    'api_key_last4' => substr($k, -4),
                    'requests' => $periodTotals[$k]
                );
            }
        }
        usort($siteKeyList, function($a, $b){ return $b['requests'] <=> $a['requests']; });

        $this->json['object'] = 'activity_report';
        $this->json['url'] = '/activity';
        $this->json['period'] = $period;
        $this->json['period_months'] = ($period === 'month') ? 1 : 3;
        $this->json['since'] = $since;
        $this->json['until'] = $now;
        $this->json['months'] = $windowMonths;
        $this->json['source'] = 'audit_log';
        $this->json['test_entities_excluded'] = count($testIDs);
        $this->json['summary'] = array(
            'external_requests' => $external,
            'site_requests' => $site,
            'keys_active' => $keysActive,
            'keys_deleted' => $keysDeleted,
            'new_accounts' => $newAccounts,
            'revisions' => $revisions,
            'daily' => array_values($daily)
        );
        foreach($keyRows as &$row){
            unset($row['_key']);
        }
        unset($row);
        $this->json['site_keys'] = $siteKeyList;
        $this->json['keys'] = $keyRows;
        $this->json['billing'] = array(
            'block_size' => Billing::BLOCK_SIZE,
            'block_price_cents' => Billing::BLOCK_PRICE_CENTS,
            'invoice_floor_cents' => Billing::INVOICE_FLOOR_CENTS,
            'charge_day' => Billing::CHARGE_DAY,
            'this_month' => array(
                'month' => $thisYm,
                'committed_cents' => $committed,
                'forecast_cents' => $forecast,
                'elapsed_seconds' => $elapsed,
                'month_seconds' => $monthSeconds
            ),
            'last_month' => array(
                'month' => $lastYm,
                'charged_cents' => $charged,
                'invoicing_cents' => $invoicing,
                'rolling_cents' => $rolling,
                'charges' => $lastMonthCharges
            ),
            'refused' => array(
                'total' => $refusedTotal,
                'keys' => $refusedKeys,
                'by_month' => $refusedByMonth
            ),
            'keys' => $billingKeys,
            'ceiling' => $ceiling
        );
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
                        // GET /activity[?period=month|3m]
                        $period = $_GET['period'] ?? 'month';
                        $this->generateReport($apiKey, $period);
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
