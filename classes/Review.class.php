<?php
/*--
The brewer review loop's queue and record. Admin-only.

  POST  /review/claim            every brewer with an unactioned decision, plus the next N; all held
  POST  /review/{brewer_id}      record a completed review; sets brewer.reviewedAt, clears the claim
  GET   /review                  recent reviews; ?needs_decision=1 is the human check-in list
  GET   /review/{id}             one review
  PATCH /review/{id}             answer a review's question (decision)
  GET   /brewer/{brewer_id}/review   a brewer's review history (routed here by .htaccess)

The queue is three columns on brewer (reviewedAt, claimedBy, claimedAt); the
record is brewer_review, one row per completed review. Both are described in
catalog-beer-mysql/migrations/2026-09-08-brewer-review.sql — read that first.

A claim expires on the read side: claim() treats claimedAt older than
CLAIM_TTL as free. Nothing sweeps claims and nothing has to. Posting a review
clears the claim whether or not the caller held it, so a human session that
never claimed can still record what it did.

A review whose url_verdict is 'ok' also stamps the brewer's URL-health columns
the way the check-urls cron's own ok branch does, so the cron and the review
never disagree about a site the review just read.
--*/

class Review {

    const CLAIM_TTL = 14400;      // 4 hours, seconds
    const CLAIM_MAX = 50;         // brewers per claim
    const LIST_DEFAULT = 50;
    const LIST_MAX = 500;

    const OUTCOMES = array('updated', 'unchanged', 'created', 'skipped', 'defunct', 'merged', 'deferred');
    const URL_VERDICTS = array('ok', 'cleared', 'replaced', 'unchecked');
    const COUNTERS = array('beers_added' => 'beersAdded', 'beers_updated' => 'beersUpdated', 'dupes_deleted' => 'dupesDeleted', 'locations_added' => 'locationsAdded', 'locations_updated' => 'locationsUpdated', 'locations_deleted' => 'locationsDeleted');

    // Validation
    public $error = false;
    public $errorMsg = null;

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    // The admin user behind the key, once authorised
    private $userID = '';

    // ----- Authorisation -----
    // Same gate as /activity and /metrics: the key must belong to a user with
    // the admin flag. Master keys are not in api_keys and so are refused here;
    // reviews are meant to carry a real account as their reviewer.
    private function authorize($apiKey, $context){
        $apiKeys = new apiKeys();
        if($apiKeys->validate($apiKey, true)){
            $users = new Users();
            $users->validate($apiKeys->userID, true);
            if($users->admin){
                $this->userID = $apiKeys->userID;
                return true;
            }
            $this->error = true;
            $this->errorMsg = 'Unauthorized: You must be an admin to access this endpoint.';
            $this->responseCode = 401;

            $errorLog = new LogError();
            $errorLog->errorNumber = 311;
            $errorLog->errorMsg = "Unauthorized: Not admin ($context)";
            $errorLog->badData = "apiKey: $apiKey";
            $errorLog->filename = 'Review.class.php';
            $errorLog->write();
            return false;
        }
        $this->error = true;
        $this->errorMsg = 'Invalid API Key.';
        $this->responseCode = 404;

        $errorLog = new LogError();
        $errorLog->errorNumber = 310;
        $errorLog->errorMsg = "Invalid API Key ($context)";
        $errorLog->badData = $apiKey;
        $errorLog->filename = 'Review.class.php';
        $errorLog->write();
        return false;
    }

    private function dbError($db, $context){
        $this->error = true;
        $this->errorMsg = $db->errorMsg;
        $this->responseCode = $db->responseCode;

        $errorLog = new LogError();
        $errorLog->errorNumber = 312;
        $errorLog->errorMsg = "Database error ($context)";
        $errorLog->badData = $db->errorMsg;
        $errorLog->filename = 'Review.class.php';
        $errorLog->write();
    }

    private function validationError($messages, $context){
        $this->error = true;
        $this->errorMsg = implode(' ', array_values($messages));
        $this->responseCode = 400;
        $this->json['validation'] = $messages;

        $errorLog = new LogError();
        $errorLog->errorNumber = 313;
        $errorLog->errorMsg = "Validation error ($context)";
        $errorLog->badData = json_encode($messages);
        $errorLog->filename = 'Review.class.php';
        $errorLog->write();
    }

    // ----- Row -> JSON -----
    // Expects the row to have been selected with needsDecision+0, so the bit
    // arrives as an int rather than a byte.
    private function reviewObject($row){
        return array(
            'id' => $row['id'],
            'object' => 'review',
            'brewer_id' => $row['brewerID'],
            'brewer_name' => $row['brewerName'],
            'reviewed_at' => intval($row['reviewedAt']),
            'reviewer' => $row['reviewer'],
            'brief_version' => $row['briefVersion'],
            'outcome' => $row['outcome'],
            'url_verdict' => $row['urlVerdict'],
            'brewer_changed' => $row['brewerChanged'],
            'beers_added' => intval($row['beersAdded']),
            'beers_updated' => intval($row['beersUpdated']),
            'dupes_deleted' => intval($row['dupesDeleted']),
            'locations_added' => intval($row['locationsAdded']),
            'locations_updated' => intval($row['locationsUpdated']),
            'locations_deleted' => intval($row['locationsDeleted']),
            'sources' => is_null($row['sources']) ? null : json_decode($row['sources']),
            'changes' => is_null($row['changes']) ? null : json_decode($row['changes']),
            'notes' => $row['notes'],
            'needs_decision' => intval($row['needsDecision']) === 1,
            'question' => $row['question'],
            'decision' => $row['decision'],
            'decided_at' => is_null($row['decidedAt']) ? null : intval($row['decidedAt']),
            'decided_by' => $row['decidedBy']
        );
    }

    // Selected FROM brewer_review r LEFT JOIN brewer b, so every review carries
    // the brewer's current name and the check-in page needs no second call.
    const REVIEW_COLUMNS = "r.id, r.brewerID, b.name AS brewerName, r.reviewedAt, r.reviewer, r.briefVersion, r.outcome, r.urlVerdict, r.brewerChanged, r.beersAdded, r.beersUpdated, r.dupesDeleted, r.locationsAdded, r.locationsUpdated, r.locationsDeleted, r.sources, r.changes, r.notes, r.needsDecision+0 AS needsDecision, r.question, r.decision, r.decidedAt, r.decidedBy";
    const REVIEW_FROM = "brewer_review r LEFT JOIN brewer b ON b.id = r.brewerID";

    // ----- POST /review/claim -----
    // Selects the next N brewers and holds them, in one transaction, so two
    // agents claiming at once cannot be handed the same row. Two selections:
    //
    //   1. Every brewer whose LATEST review carries a decision -- a human
    //      answered and nobody has posted a review since, so nobody has acted
    //      on it. These are claimed regardless of count: an answer must never
    //      wait on the size of tonight's run. (The acting review becomes the
    //      latest row and has no decision, which drops the brewer out.)
    //   2. Then `count` more, ordered never-reviewed first, then a non-ok cron
    //      verdict (step 1 has work to do), then oldest review.
    //
    // SKIP LOCKED lets concurrent claimers pass each other instead of queueing
    // on the same rows.
    public function claim($count){
        $count = intval($count);
        if($count < 1){$count = 10;}
        if($count > self::CLAIM_MAX){$count = self::CLAIM_MAX;}

        $now = time();
        $free = $now - self::CLAIM_TTL;

        $db = new Database();
        $conn = $db->getConnection();
        $conn->begin_transaction();

        $latest = "LEFT JOIN brewer_review r ON r.id = (SELECT id FROM brewer_review WHERE brewerID = b.id ORDER BY reviewedAt DESC LIMIT 1)";
        $eligible = "b.url IS NOT NULL AND b.url <> '' AND (b.claimedAt IS NULL OR b.claimedAt < ?)";

        // 1. Unactioned decisions, all of them
        $result = $db->query("SELECT b.id FROM brewer b $latest WHERE $eligible AND r.decidedAt IS NOT NULL ORDER BY r.decidedAt ASC LIMIT ? FOR UPDATE SKIP LOCKED", [$free, self::CLAIM_MAX]);
        if($db->error){
            $conn->rollback();
            $this->dbError($db, 'POST /review/claim - decided');
            $db->close();
            return;
        }
        $ids = array();
        while($row = $result->fetch_assoc()){
            $ids[] = $row['id'];
        }
        $decided = count($ids);

        // 2. Then count more, excluding what step 1 took
        $exclude = '';
        $params = [$free];
        if(!empty($ids)){
            $exclude = ' AND b.id NOT IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $params[] = $count;
        $result = $db->query("SELECT b.id FROM brewer b $latest WHERE $eligible$exclude ORDER BY (b.reviewedAt IS NULL) DESC, (b.urlStatus <> 'ok') DESC, b.reviewedAt ASC, b.lastModified ASC LIMIT ? FOR UPDATE SKIP LOCKED", $params);
        if($db->error){
            $conn->rollback();
            $this->dbError($db, 'POST /review/claim - select');
            $db->close();
            return;
        }
        while($row = $result->fetch_assoc()){
            $ids[] = $row['id'];
        }

        if(!empty($ids)){
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $db->query("UPDATE brewer SET claimedBy=?, claimedAt=? WHERE id IN ($placeholders)", array_merge([$this->userID, $now], $ids));
            if($db->error){
                $conn->rollback();
                $this->dbError($db, 'POST /review/claim - update');
                $db->close();
                return;
            }
        }
        $conn->commit();

        $data = array();
        if(!empty($ids)){
            // Full rows, in the order they were selected, each with the most
            // recent review's outcome and any decision a human left for it.
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $result = $db->query("SELECT b.id, b.name, b.url, b.domainName, b.cbVerified+0 AS cbVerified, b.brewerVerified+0 AS brewerVerified, b.lastModified, b.createdAt, b.urlStatus, b.urlCheckedAt, b.urlLastOkAt, b.urlFailCount, b.urlFinal, b.urlLastKnown, b.urlDomainRegistered, b.reviewedAt, r.id AS reviewID, r.outcome, r.notes, r.question, r.decision, r.decidedAt FROM brewer b LEFT JOIN brewer_review r ON r.id = (SELECT id FROM brewer_review WHERE brewerID = b.id ORDER BY reviewedAt DESC LIMIT 1) WHERE b.id IN ($placeholders) ORDER BY FIELD(b.id, $placeholders)", array_merge($ids, $ids));
            if($db->error){
                $this->dbError($db, 'POST /review/claim - rows');
                $db->close();
                return;
            }
            while($row = $result->fetch_assoc()){
                $lastReview = null;
                if(!is_null($row['reviewID'])){
                    $lastReview = array(
                        'id' => $row['reviewID'],
                        'outcome' => $row['outcome'],
                        'notes' => $row['notes'],
                        'question' => $row['question'],
                        'decision' => $row['decision'],
                        'decided_at' => is_null($row['decidedAt']) ? null : intval($row['decidedAt'])
                    );
                }
                $data[] = array(
                    'brewer_id' => $row['id'],
                    'name' => $row['name'],
                    'url' => $row['url'],
                    'domain_name' => $row['domainName'],
                    'cb_verified' => intval($row['cbVerified']) === 1,
                    'brewer_verified' => intval($row['brewerVerified']) === 1,
                    'last_modified' => intval($row['lastModified']),
                    'created_at' => intval($row['createdAt']),
                    'url_status' => $row['urlStatus'],
                    'url_checked_at' => is_null($row['urlCheckedAt']) ? null : intval($row['urlCheckedAt']),
                    'url_last_ok_at' => is_null($row['urlLastOkAt']) ? null : intval($row['urlLastOkAt']),
                    'url_fail_count' => intval($row['urlFailCount']),
                    'url_final' => $row['urlFinal'],
                    'url_last_known' => $row['urlLastKnown'],
                    'url_domain_registered' => $row['urlDomainRegistered'],
                    'reviewed_at' => is_null($row['reviewedAt']) ? null : intval($row['reviewedAt']),
                    'last_review' => $lastReview
                );
            }
        }
        $db->close();

        $this->responseCode = 200;
        $this->json['object'] = 'list';
        $this->json['url'] = '/review/claim';
        $this->json['claimed_by'] = $this->userID;
        $this->json['claim_expires_at'] = $now + self::CLAIM_TTL;
        $this->json['decided'] = $decided;   // how many of these carry a decision to act on first
        $this->json['data'] = $data;
    }

    // ----- POST /review/{brewer_id} -----
    public function add($brewerID, $data){
        $brewerID = trim($brewerID ?? '');
        $uuid = new uuid();
        if(empty($brewerID) || !$uuid->validate($brewerID)){
            $this->error = true;
            $this->errorMsg = 'Invalid brewer_id.';
            $this->responseCode = 400;
            return;
        }
        $brewer = new Brewer();
        if(!$brewer->validate($brewerID, false)){
            $this->error = true;
            $this->errorMsg = 'Brewer not found.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 316;
            $errorLog->errorMsg = 'Brewer not found (POST /review/{brewer_id})';
            $errorLog->badData = $brewerID;
            $errorLog->filename = 'Review.class.php';
            $errorLog->write();
            return;
        }

        $messages = array();

        // outcome — required
        $outcome = isset($data->outcome) ? TextInput::trim(strval($data->outcome)) : '';
        if(!in_array($outcome, self::OUTCOMES, true)){
            $messages['outcome'] = 'outcome is required and must be one of: ' . implode(', ', self::OUTCOMES) . '.';
        }

        // url_verdict — optional, default unchecked
        $urlVerdict = isset($data->url_verdict) ? TextInput::trim(strval($data->url_verdict)) : 'unchecked';
        if(!in_array($urlVerdict, self::URL_VERDICTS, true)){
            $messages['url_verdict'] = 'url_verdict must be one of: ' . implode(', ', self::URL_VERDICTS) . '.';
        }

        // Single-line strings with a cap
        $briefVersion = $this->singleLine($data, 'brief_version', 40, $messages);
        $brewerChanged = $this->singleLine($data, 'brewer_changed', 255, $messages);
        $question = $this->singleLine($data, 'question', 500, $messages);

        // notes — free text, newlines allowed
        $notes = null;
        if(isset($data->notes) && $data->notes !== ''){
            $notes = TextInput::trim(strval($data->notes));
            $msg = TextInput::check($notes, true);
            if($msg !== ''){
                $messages['notes'] = $msg;
            }elseif(mb_strlen($notes) > 20000){
                $messages['notes'] = 'notes must be 20,000 characters or fewer.';
            }
        }

        // Counters
        $counters = array();
        foreach(self::COUNTERS as $field => $column){
            $value = 0;
            if(isset($data->$field)){
                if(!is_int($data->$field) || $data->$field < 0 || $data->$field > 65535){
                    $messages[$field] = "$field must be a whole number from 0 to 65535.";
                }else{
                    $value = $data->$field;
                }
            }
            $counters[$column] = $value;
        }

        // sources — array of URL strings
        $sources = null;
        if(isset($data->sources) && !is_null($data->sources)){
            if(!is_array($data->sources) || count($data->sources) > 200){
                $messages['sources'] = 'sources must be an array of at most 200 URLs.';
            }else{
                $clean = array();
                foreach($data->sources as $source){
                    if(!is_string($source) || mb_strlen($source) > 2048 || TextInput::check($source) !== ''){
                        $messages['sources'] = 'Every entry in sources must be a single-line string of 2,048 characters or fewer.';
                        break;
                    }
                    $clean[] = TextInput::trim($source);
                }
                $sources = json_encode($clean);
            }
        }

        // changes — array of {entity, id, field, before, after}
        $changes = null;
        if(isset($data->changes) && !is_null($data->changes)){
            if(!is_array($data->changes) || count($data->changes) > 2000){
                $messages['changes'] = 'changes must be an array of at most 2,000 entries.';
            }else{
                foreach($data->changes as $change){
                    if(!is_object($change) || !isset($change->entity) || !isset($change->id) || !isset($change->field)){
                        $messages['changes'] = 'Every entry in changes must be an object with entity, id, field, before and after.';
                        break;
                    }
                }
                if(!isset($messages['changes'])){
                    $changes = json_encode($data->changes);
                    if(strlen($changes) > 1000000){
                        $messages['changes'] = 'changes is too large to store (1 MB limit).';
                        $changes = null;
                    }
                }
            }
        }

        // needs_decision — a question is required with it
        $needsDecision = 0;
        if(isset($data->needs_decision)){
            if(!is_bool($data->needs_decision)){
                $messages['needs_decision'] = 'needs_decision must be true or false.';
            }elseif($data->needs_decision){
                $needsDecision = 1;
                // Only "required" when nothing was sent. A question that was
                // sent but too long already has its own message; overwriting
                // it here told an agent its 717-character question was absent.
                if(is_null($question) && !isset($messages['question'])){
                    $messages['question'] = 'question is required when needs_decision is true.';
                }
            }
        }

        if(!empty($messages)){
            $this->validationError($messages, 'POST /review/{brewer_id}');
            return;
        }

        // Write
        $reviewID = $uuid->generate('brewer_review');
        $now = time();
        $db = new Database();
        $db->query("INSERT INTO brewer_review (id, brewerID, reviewedAt, reviewer, briefVersion, outcome, urlVerdict, brewerChanged, beersAdded, beersUpdated, dupesDeleted, locationsAdded, locationsUpdated, locationsDeleted, sources, changes, notes, needsDecision, question) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$reviewID, $brewerID, $now, $this->userID, $briefVersion, $outcome, $urlVerdict, $brewerChanged, $counters['beersAdded'], $counters['beersUpdated'], $counters['dupesDeleted'], $counters['locationsAdded'], $counters['locationsUpdated'], $counters['locationsDeleted'], $sources, $changes, $notes, $needsDecision, $question]);
        if($db->error){
            $this->dbError($db, 'POST /review/{brewer_id} - insert');
            $db->close();
            return;
        }

        // The brewer row: reviewed now, claim released. A confirmed URL also
        // refreshes the health columns exactly as the cron's ok branch does.
        if($urlVerdict === 'ok'){
            $db->query("UPDATE brewer SET reviewedAt=?, claimedBy=NULL, claimedAt=NULL, urlStatus='ok', urlCheckedAt=?, urlLastOkAt=?, urlFailCount=0 WHERE id=?", [$now, $now, $now, $brewerID]);
        }else{
            $db->query("UPDATE brewer SET reviewedAt=?, claimedBy=NULL, claimedAt=NULL WHERE id=?", [$now, $brewerID]);
        }
        if($db->error){
            $this->dbError($db, 'POST /review/{brewer_id} - brewer update');
            $db->close();
            return;
        }
        $db->close();

        $this->get($reviewID);
        if(!$this->error){
            $this->responseCode = 201;
            $responseHeaderString = 'Location: https://';
            if(ENVIRONMENT == 'staging'){
                $responseHeaderString .= 'staging.';
            }
            $this->responseHeader = $responseHeaderString . 'catalog.beer/review/' . $reviewID;
        }
    }

    private function singleLine($data, $field, $max, &$messages){
        if(!isset($data->$field) || $data->$field === '' || is_null($data->$field)){
            return null;
        }
        $value = TextInput::trim(strval($data->$field));
        if($value === ''){
            return null;
        }
        $msg = TextInput::check($value);
        if($msg !== ''){
            $messages[$field] = $msg;
            return null;
        }
        if(mb_strlen($value) > $max){
            $messages[$field] = "$field must be $max characters or fewer (" . mb_strlen($value) . " sent). Shorten it; the detail belongs in notes.";
            return null;
        }
        return $value;
    }

    // ----- GET /review/{id} -----
    public function get($reviewID){
        $reviewID = trim($reviewID ?? '');
        $db = new Database();
        $result = $db->query("SELECT " . self::REVIEW_COLUMNS . " FROM " . self::REVIEW_FROM . " WHERE r.id=?", [$reviewID]);
        if($db->error){
            $this->dbError($db, 'GET /review/{id}');
            $db->close();
            return;
        }
        if($result->num_rows !== 1){
            $this->error = true;
            $this->errorMsg = 'Review not found.';
            $this->responseCode = 404;
            $db->close();

            $errorLog = new LogError();
            $errorLog->errorNumber = 317;
            $errorLog->errorMsg = 'Review not found (GET /review/{id})';
            $errorLog->badData = $reviewID;
            $errorLog->filename = 'Review.class.php';
            $errorLog->write();
            return;
        }
        $this->json = $this->reviewObject($result->fetch_assoc());
        $db->close();
    }

    // ----- GET /review, GET /brewer/{id}/review -----
    // needs_decision=1 lists what is waiting on a human, oldest first; the
    // default lists recent reviews, newest first. LIMIT count+1 decides
    // has_more without a second COUNT query.
    public function listReviews($brewerID, $needsDecisionOnly, $count, $cursor){
        $count = intval($count);
        if($count < 1){$count = self::LIST_DEFAULT;}
        if($count > self::LIST_MAX){$count = self::LIST_MAX;}
        $offset = intval(base64_decode($cursor ?? ''));
        if($offset < 0){$offset = 0;}

        $where = array();
        $params = array();
        if(!is_null($brewerID)){
            $where[] = 'r.brewerID=?';
            $params[] = $brewerID;
        }
        if($needsDecisionOnly){
            $where[] = 'r.needsDecision=1';
        }
        $sql = "SELECT " . self::REVIEW_COLUMNS . " FROM " . self::REVIEW_FROM;
        if(!empty($where)){
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= $needsDecisionOnly ? ' ORDER BY r.reviewedAt ASC' : ' ORDER BY r.reviewedAt DESC';
        $sql .= ' LIMIT ? OFFSET ?';
        $params[] = $count + 1;
        $params[] = $offset;

        $db = new Database();
        $result = $db->query($sql, $params);
        if($db->error){
            $this->dbError($db, 'GET /review');
            $db->close();
            return;
        }
        $data = array();
        while($row = $result->fetch_assoc()){
            $data[] = $this->reviewObject($row);
        }
        $db->close();

        $hasMore = count($data) > $count;
        if($hasMore){
            array_pop($data);
        }
        $this->json['object'] = 'list';
        $this->json['url'] = is_null($brewerID) ? '/review' : "/brewer/$brewerID/review";
        if($needsDecisionOnly){
            $this->json['needs_decision'] = true;
        }
        $this->json['has_more'] = $hasMore;
        if($hasMore){
            $this->json['next_cursor'] = base64_encode($offset + $count);
        }
        $this->json['data'] = $data;
    }

    // ----- PATCH /review/{id} -----
    // Records a human's answer. The next claim of that brewer carries it.
    public function decide($reviewID, $data){
        $messages = array();
        $decision = $this->singleLine($data, 'decision', 500, $messages);
        if(is_null($decision) && !isset($messages['decision'])){
            $messages['decision'] = 'decision is required.';
        }
        if(!empty($messages)){
            $this->validationError($messages, 'PATCH /review/{id}');
            return;
        }

        $this->get($reviewID);
        if($this->error){
            return;
        }
        $this->json = array();

        $db = new Database();
        $db->query("UPDATE brewer_review SET decision=?, decidedAt=?, decidedBy=?, needsDecision=0 WHERE id=?", [$decision, time(), $this->userID, $reviewID]);
        if($db->error){
            $this->dbError($db, 'PATCH /review/{id}');
            $db->close();
            return;
        }
        $db->close();
        $this->get($reviewID);
    }

    // ----- Router -----
    public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
        /*---
        POST  https://api.catalog.beer/review/claim
        POST  https://api.catalog.beer/review/{brewer_id}
        GET   https://api.catalog.beer/review?needs_decision=1&count=&cursor=
        GET   https://api.catalog.beer/review/{review_id}
        PATCH https://api.catalog.beer/review/{review_id}
        GET   https://api.catalog.beer/brewer/{brewer_id}/review   (function=brewer)
        ---*/
        if(!$this->authorize($apiKey, "$method /review")){
            $this->json['error'] = true;
            $this->json['error_msg'] = $this->errorMsg;
            return;
        }

        switch($method){
            case 'GET':
                if($function === 'brewer' && !empty($id)){
                    $this->listReviews($id, false, $count, $cursor);
                }elseif(empty($function) && !empty($id)){
                    $this->get($id);
                }elseif(empty($function) && empty($id)){
                    $needsDecisionOnly = isset($_GET['needs_decision']) && in_array($_GET['needs_decision'], array('1', 'true'), true);
                    $this->listReviews(null, $needsDecisionOnly, $count, $cursor);
                }else{
                    $this->invalidPath($function);
                }
                break;
            case 'POST':
                if($function === 'claim'){
                    $this->claim(isset($data->count) ? $data->count : 10);
                }elseif(empty($function) && !empty($id)){
                    $this->add($id, $data);
                }else{
                    $this->invalidPath($function);
                }
                break;
            case 'PATCH':
                if(empty($function) && !empty($id)){
                    $this->decide($id, $data);
                }else{
                    $this->invalidPath($function);
                }
                break;
            default:
                $this->error = true;
                $this->errorMsg = 'Invalid HTTP method for this endpoint.';
                $this->responseCode = 405;
                $this->responseHeader = 'Allow: GET, POST, PATCH';

                $errorLog = new LogError();
                $errorLog->errorNumber = 315;
                $errorLog->errorMsg = 'Invalid Method (/review)';
                $errorLog->badData = $method;
                $errorLog->filename = 'Review.class.php';
                $errorLog->write();
        }

        if($this->error){
            $this->json['error'] = true;
            $this->json['error_msg'] = $this->errorMsg;
        }
    }

    private function invalidPath($function){
        $this->error = true;
        $this->errorMsg = 'Invalid path. The URI you requested does not exist.';
        $this->responseCode = 404;

        $errorLog = new LogError();
        $errorLog->errorNumber = 314;
        $errorLog->errorMsg = 'Invalid function (/review)';
        $errorLog->badData = $function;
        $errorLog->filename = 'Review.class.php';
        $errorLog->write();
    }
}
?>
