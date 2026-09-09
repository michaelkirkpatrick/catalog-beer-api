<?php
/*--
The review loop's lead queue: breweries a review met that the catalog does not
hold, recorded so they can be researched properly later instead of created
thin now. Admin-only. Spec: scratch/brewer-leads-spec.md (v3).

  POST  /brewer-lead             queue one; deduped server-side, never merged
  POST  /brewer-lead/claim       every decided lead, plus the next N; all held
  GET   /brewer-lead             ?status=queued|claimed|closed, ?resolution=,
                                 ?needs_decision=1 (the human check-in list)
  GET   /brewer-lead/{id}        one lead
  PATCH /brewer-lead/{id}        resolve | defer | ask | decide (one per call)

A lead is not a record. It is a name, a place, and the page it was read on,
and it becomes a brewer only by being claimed and put through the six steps,
ending in a brewer_review row with outcome 'created'. Nothing here is
published. The table is brewer_lead -- see
catalog-beer-mysql/migrations/2026-09-09-brewer-lead.sql first.

Two stored states, open/closed. "claimed" is derived: an open row whose
claimedAt is within CLAIM_TTL, exactly as brewer.claimedAt works for the
review claim. Nothing sweeps claims and nothing has to.

Dedup on POST covers every status, so a closed lead is also the negative
cache. A URL host that is already a brewer's domainName is refused outright
(409) -- the brewery exists; review it, don't queue it. On a queue hit the
row's lastSeenAt moves and the request's source_url joins its sources; no
other field is ever merged.

The url is never fetched. A lead must be able to carry a dead, slow, or
not-yet-live URL; verifying it is step 1 of the review that researches the
lead, not a precondition of recording it.
--*/

class BrewerLead {

    const CLAIM_TTL = Review::CLAIM_TTL;   // one loop, one clock
    const CLAIM_MAX = 50;
    const LIST_DEFAULT = 50;
    const LIST_MAX = 500;
    const SOURCES_MAX = 20;

    const RESOLUTIONS = array('created', 'duplicate', 'not_a_brewery', 'out_of_scope');
    const WITH_BREWER = array('created', 'duplicate');
    const STATUSES = array('queued', 'claimed', 'closed');

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
    // Same gate as /review: the key must belong to a user with the admin flag.
    // Master keys are not in api_keys and so are refused; a lead carries a
    // real account as the reviewer who read it.
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
            $errorLog->errorNumber = 319;
            $errorLog->errorMsg = "Unauthorized: Not admin ($context)";
            $errorLog->badData = "apiKey: $apiKey";
            $errorLog->filename = 'BrewerLead.class.php';
            $errorLog->write();
            return false;
        }
        $this->error = true;
        $this->errorMsg = 'Invalid API Key.';
        $this->responseCode = 404;

        $errorLog = new LogError();
        $errorLog->errorNumber = 318;
        $errorLog->errorMsg = "Invalid API Key ($context)";
        $errorLog->badData = $apiKey;
        $errorLog->filename = 'BrewerLead.class.php';
        $errorLog->write();
        return false;
    }

    private function dbError($db, $context){
        $this->error = true;
        $this->errorMsg = $db->errorMsg;
        $this->responseCode = $db->responseCode;

        $errorLog = new LogError();
        $errorLog->errorNumber = 320;
        $errorLog->errorMsg = "Database error ($context)";
        $errorLog->badData = $db->errorMsg;
        $errorLog->filename = 'BrewerLead.class.php';
        $errorLog->write();
    }

    private function validationError($messages, $context, $code = 400){
        $this->error = true;
        $this->errorMsg = implode(' ', array_values($messages));
        $this->responseCode = $code;
        $this->json['validation'] = $messages;

        $errorLog = new LogError();
        $errorLog->errorNumber = 321;
        $errorLog->errorMsg = "Validation error ($context)";
        $errorLog->badData = json_encode($messages);
        $errorLog->filename = 'BrewerLead.class.php';
        $errorLog->write();
    }

    private function notFound($leadID, $context){
        $this->error = true;
        $this->errorMsg = 'Lead not found.';
        $this->responseCode = 404;

        $errorLog = new LogError();
        $errorLog->errorNumber = 324;
        $errorLog->errorMsg = "Lead not found ($context)";
        $errorLog->badData = $leadID;
        $errorLog->filename = 'BrewerLead.class.php';
        $errorLog->write();
    }

    // ----- Row -> JSON -----
    // Selected with needsDecision+0 so the bit arrives as an int. status on
    // the wire is derived: closed, or claimed while the claim is live, else
    // queued. An expired claim's claimedBy/claimedAt are shown as null so the
    // object never says "queued, claimed by X".
    private function leadObject($row){
        $now = time();
        $claimLive = !is_null($row['claimedAt']) && intval($row['claimedAt']) >= $now - self::CLAIM_TTL;
        if($row['status'] === 'closed'){
            $status = 'closed';
        }elseif($claimLive){
            $status = 'claimed';
        }else{
            $status = 'queued';
        }
        return array(
            'id' => $row['id'],
            'object' => 'brewer_lead',
            'name' => $row['name'],
            'url' => $row['url'],
            'city' => $row['city'],
            'sub_code' => $row['sub_code'],
            'state_short' => is_null($row['sub_code']) ? null : substr($row['sub_code'], 3, 2),
            'source_url' => $row['sourceUrl'],
            'sources' => json_decode($row['sources']),
            'note' => $row['note'],
            'status' => $status,
            'resolution' => $row['resolution'],
            'brewer_id' => $row['brewerID'],
            'brewer_name' => $row['brewerName'],
            'recheck_after' => is_null($row['recheckAfter']) ? null : intval($row['recheckAfter']),
            'created_by' => $row['createdBy'],
            'created_at' => intval($row['createdAt']),
            'last_seen_at' => intval($row['lastSeenAt']),
            'claimed_by' => $claimLive ? $row['claimedBy'] : null,
            'claimed_at' => $claimLive ? intval($row['claimedAt']) : null,
            'claim_expires_at' => $claimLive ? intval($row['claimedAt']) + self::CLAIM_TTL : null,
            'resolved_by' => $row['resolvedBy'],
            'resolved_at' => is_null($row['resolvedAt']) ? null : intval($row['resolvedAt']),
            'needs_decision' => intval($row['needsDecision']) === 1,
            'question' => $row['question'],
            'decision' => $row['decision'],
            'decided_at' => is_null($row['decidedAt']) ? null : intval($row['decidedAt']),
            'decided_by' => $row['decidedBy']
        );
    }

    const LEAD_COLUMNS = "l.id, l.name, l.url, l.city, l.sub_code, l.sourceUrl, l.sources, l.note, l.status, l.resolution, l.brewerID, b.name AS brewerName, l.recheckAfter, l.createdBy, l.createdAt, l.lastSeenAt, l.claimedBy, l.claimedAt, l.resolvedBy, l.resolvedAt, l.needsDecision+0 AS needsDecision, l.question, l.decision, l.decidedAt, l.decidedBy";
    const LEAD_FROM = "brewer_lead l LEFT JOIN brewer b ON b.id = l.brewerID";

    // ----- Field helpers -----
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
            $messages[$field] = "$field must be $max characters or fewer (" . mb_strlen($value) . " sent).";
            return null;
        }
        return $value;
    }

    private function multiLine($data, $field, $max, &$messages){
        if(!isset($data->$field) || $data->$field === '' || is_null($data->$field)){
            return null;
        }
        $value = TextInput::trim(strval($data->$field));
        if($value === ''){
            return null;
        }
        $msg = TextInput::check($value, true);
        if($msg !== ''){
            $messages[$field] = $msg;
            return null;
        }
        if(mb_strlen($value) > $max){
            $messages[$field] = "$field must be " . number_format($max) . " characters or fewer (" . number_format(mb_strlen($value)) . " sent).";
            return null;
        }
        return $value;
    }

    /* Syntax only -- the first half of Brewer::validateURL(), without the
       probe, plus a dot in the host: filter_var() is happy with "http://nope",
       and no brewery site or news page lives on a dotless host. Returns the
       value as sent (trimmed); null when absent; sets a message and returns
       null when malformed. */
    private function urlField($data, $field, &$messages){
        $value = $this->singleLine($data, $field, 255, $messages);
        if(is_null($value)){
            return null;
        }
        $probe = preg_match('/^https?:\/\//i', $value) ? $value : 'http://' . $value;
        $host = filter_var($probe, FILTER_VALIDATE_URL) ? parse_url($probe, PHP_URL_HOST) : null;
        if(empty($host) || strpos($host, '.') === false){
            $messages[$field] = "$field must be a valid URL.";
            return null;
        }
        return $value;
    }

    /* brewer.domainName's rule: the host, lowercased, www. stripped. */
    public static function urlHost($url){
        $url = trim($url ?? '');
        if($url === ''){
            return null;
        }
        if(!preg_match('/^https?:\/\//i', $url)){
            $url = 'http://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if(empty($host) || !preg_match('/[a-zA-Z0-9.-]+/', $host, $m)){
            return null;
        }
        $host = strtolower($m[0]);
        if(substr($host, 0, 4) === 'www.'){
            $host = substr($host, 4);
        }
        return $host === '' ? null : $host;
    }

    // ----- POST /brewer-lead -----
    public function add($data){
        $messages = array();

        $name = $this->singleLine($data, 'name', 255, $messages);
        if(is_null($name) && !isset($messages['name'])){
            $messages['name'] = 'name is required.';
        }
        $sourceUrl = $this->urlField($data, 'source_url', $messages);
        if(is_null($sourceUrl) && !isset($messages['source_url'])){
            $messages['source_url'] = 'source_url is required: the page this lead was read on.';
        }
        $url = $this->urlField($data, 'url', $messages);
        $city = $this->singleLine($data, 'city', 100, $messages);
        $note = $this->multiLine($data, 'note', 2000, $messages);

        // ISO 3166-2, the form every address object carries (US-OR). A bare
        // two-letter state is accepted and upgraded, since that is what a news
        // article prints and what the agent is likeliest to send.
        $subCode = null;
        if(isset($data->sub_code) && !is_null($data->sub_code) && $data->sub_code !== ''){
            $candidate = strtoupper(TextInput::trim(strval($data->sub_code)));
            if(preg_match('/^[A-Z]{2}$/', $candidate)){
                $candidate = 'US-' . $candidate;
            }
            $subdivisions = new Subdivisions();
            if(preg_match('/^US-[A-Z]{2}$/', $candidate) && $subdivisions->validate($candidate, false)){
                $subCode = $candidate;
            }else{
                $messages['sub_code'] = 'sub_code must be a US state or territory code (US-OR, or OR).';
            }
        }

        // A bare name is not researchable: a URL, or a city and a state
        $placed = !is_null($city) && !is_null($subCode);
        if(is_null($url) && !$placed && !isset($messages['url']) && !isset($messages['sub_code'])){
            $msg = 'A lead needs a url, or a city and a sub_code.';
            $messages['url'] = $msg;
            $messages['city'] = $msg;
            $messages['sub_code'] = $msg;
        }

        if(!empty($messages)){
            $this->validationError($messages, 'POST /brewer-lead');
            return;
        }

        $urlHost = is_null($url) ? null : self::urlHost($url);
        $nameKey = SearchQuery::brewerNameKey($name);
        $now = time();
        $db = new Database();

        // 0. Already a brewer? Then it is a review, not a lead.
        if(!is_null($urlHost)){
            $result = $db->query("SELECT id, name FROM brewer WHERE domainName=?", [$urlHost]);
            if($db->error){
                $this->dbError($db, 'POST /brewer-lead - brewer check');
                $db->close();
                return;
            }
            if($result->num_rows > 0){
                $row = $result->fetch_assoc();
                $db->close();
                $this->error = true;
                $this->errorMsg = 'That website already belongs to a brewer in the catalog. Review it rather than queueing it.';
                $this->responseCode = 409;
                $this->json['brewer_id'] = $row['id'];
                $this->json['brewer_name'] = $row['name'];

                $errorLog = new LogError();
                $errorLog->errorNumber = 325;
                $errorLog->errorMsg = 'Lead refused: host is already a brewer (POST /brewer-lead)';
                $errorLog->badData = "$urlHost -> " . $row['id'];
                $errorLog->filename = 'BrewerLead.class.php';
                $errorLog->write();
                return;
            }
        }

        // 1. By host, then 2. by name + state, in any status; most recently
        // seen row wins a collision.
        $existing = null;
        if(!is_null($urlHost)){
            $result = $db->query("SELECT id, sources FROM brewer_lead WHERE urlHost=? ORDER BY lastSeenAt DESC LIMIT 1", [$urlHost]);
            if($db->error){
                $this->dbError($db, 'POST /brewer-lead - dedup host');
                $db->close();
                return;
            }
            $existing = $result->fetch_assoc();
        }
        if(is_null($existing) && !is_null($subCode)){
            $result = $db->query("SELECT id, sources FROM brewer_lead WHERE nameKey=? AND sub_code=? ORDER BY lastSeenAt DESC LIMIT 1", [$nameKey, $subCode]);
            if($db->error){
                $this->dbError($db, 'POST /brewer-lead - dedup name');
                $db->close();
                return;
            }
            $existing = $result->fetch_assoc();
        }

        if(!is_null($existing)){
            // Seen again: the page joins the row's sources, nothing else moves.
            $sources = json_decode($existing['sources'], true);
            if(!is_array($sources)){
                $sources = array();
            }
            if(!in_array($sourceUrl, $sources, true) && count($sources) < self::SOURCES_MAX){
                $sources[] = $sourceUrl;
            }
            $db->query("UPDATE brewer_lead SET lastSeenAt=?, sources=? WHERE id=?", [$now, json_encode($sources), $existing['id']]);
            if($db->error){
                $this->dbError($db, 'POST /brewer-lead - seen again');
                $db->close();
                return;
            }
            $db->close();
            $this->get($existing['id']);
            if(!$this->error){
                $this->responseCode = 200;
            }
            return;
        }

        $uuid = new uuid();
        $leadID = $uuid->generate('brewer_lead');
        $db->query("INSERT INTO brewer_lead (id, name, nameKey, url, urlHost, city, sub_code, sourceUrl, sources, note, status, createdBy, createdAt, lastSeenAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?)",
            [$leadID, $name, $nameKey, $url, $urlHost, $city, $subCode, $sourceUrl, json_encode(array($sourceUrl)), $note, $this->userID, $now, $now]);
        if($db->error){
            $this->dbError($db, 'POST /brewer-lead - insert');
            $db->close();
            return;
        }
        $db->close();

        $this->get($leadID);
        if(!$this->error){
            $this->responseCode = 201;
            $responseHeaderString = 'Location: https://';
            if(ENVIRONMENT == 'staging'){
                $responseHeaderString .= 'staging.';
            }
            $this->responseHeader = $responseHeaderString . 'catalog.beer/brewer-lead/' . $leadID;
        }
    }

    // ----- POST /brewer-lead/claim -----
    // Two selections in one transaction, like Review::claim():
    //   1. every open lead carrying a decision -- a human answered, nobody has
    //      acted (acting resolves or defers, and a defer clears the decision).
    //      All of them, regardless of count, first in data.
    //   2. then `count` more: open, no open question, not deferred into the
    //      future, claim free, oldest first.
    // A row with needsDecision=1 is never handed out.
    public function claim($count){
        $count = intval($count);
        if($count < 1){$count = 10;}
        if($count > self::CLAIM_MAX){$count = self::CLAIM_MAX;}

        $now = time();
        $free = $now - self::CLAIM_TTL;

        $db = new Database();
        $conn = $db->getConnection();
        $conn->begin_transaction();

        $eligible = "status='open' AND needsDecision=0 AND (claimedAt IS NULL OR claimedAt < ?)";

        $result = $db->query("SELECT id FROM brewer_lead WHERE $eligible AND decision IS NOT NULL ORDER BY decidedAt ASC LIMIT ? FOR UPDATE SKIP LOCKED", [$free, self::CLAIM_MAX]);
        if($db->error){
            $conn->rollback();
            $this->dbError($db, 'POST /brewer-lead/claim - decided');
            $db->close();
            return;
        }
        $ids = array();
        while($row = $result->fetch_assoc()){
            $ids[] = $row['id'];
        }
        $decided = count($ids);

        $exclude = '';
        $params = [$free, $now];
        if(!empty($ids)){
            $exclude = ' AND id NOT IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $params[] = $count;
        $result = $db->query("SELECT id FROM brewer_lead WHERE $eligible AND decision IS NULL AND (recheckAfter IS NULL OR recheckAfter <= ?)$exclude ORDER BY createdAt ASC LIMIT ? FOR UPDATE SKIP LOCKED", $params);
        if($db->error){
            $conn->rollback();
            $this->dbError($db, 'POST /brewer-lead/claim - select');
            $db->close();
            return;
        }
        while($row = $result->fetch_assoc()){
            $ids[] = $row['id'];
        }

        if(!empty($ids)){
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $db->query("UPDATE brewer_lead SET claimedBy=?, claimedAt=? WHERE id IN ($placeholders)", array_merge([$this->userID, $now], $ids));
            if($db->error){
                $conn->rollback();
                $this->dbError($db, 'POST /brewer-lead/claim - update');
                $db->close();
                return;
            }
        }
        $conn->commit();

        $data = array();
        if(!empty($ids)){
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $result = $db->query("SELECT " . self::LEAD_COLUMNS . " FROM " . self::LEAD_FROM . " WHERE l.id IN ($placeholders) ORDER BY FIELD(l.id, $placeholders)", array_merge($ids, $ids));
            if($db->error){
                $this->dbError($db, 'POST /brewer-lead/claim - rows');
                $db->close();
                return;
            }
            while($row = $result->fetch_assoc()){
                $data[] = $this->leadObject($row);
            }
        }
        $db->close();

        $this->responseCode = 200;
        $this->json['object'] = 'list';
        $this->json['url'] = '/brewer-lead/claim';
        $this->json['claimed_by'] = $this->userID;
        $this->json['claim_expires_at'] = $now + self::CLAIM_TTL;
        $this->json['decided'] = $decided;
        $this->json['data'] = $data;
    }

    // ----- GET /brewer-lead/{id} -----
    public function get($leadID){
        $leadID = trim($leadID ?? '');
        $db = new Database();
        $result = $db->query("SELECT " . self::LEAD_COLUMNS . " FROM " . self::LEAD_FROM . " WHERE l.id=?", [$leadID]);
        if($db->error){
            $this->dbError($db, 'GET /brewer-lead/{id}');
            $db->close();
            return;
        }
        if($result->num_rows !== 1){
            $db->close();
            $this->notFound($leadID, 'GET /brewer-lead/{id}');
            return;
        }
        $this->json = $this->leadObject($result->fetch_assoc());
        $db->close();
    }

    // ----- GET /brewer-lead -----
    // status translates the wire vocabulary to the stored state plus the
    // claim window. Open rows list oldest first (the claim's order, so the
    // list is the queue); closed rows most recently resolved first.
    public function listLeads($status, $resolution, $needsDecisionOnly, $count, $cursor){
        $count = intval($count);
        if($count < 1){$count = self::LIST_DEFAULT;}
        if($count > self::LIST_MAX){$count = self::LIST_MAX;}
        $offset = intval(base64_decode($cursor ?? ''));
        if($offset < 0){$offset = 0;}

        $messages = array();
        if(!is_null($status) && !in_array($status, self::STATUSES, true)){
            $messages['status'] = 'status must be one of: ' . implode(', ', self::STATUSES) . '.';
        }
        if(!is_null($resolution) && !in_array($resolution, self::RESOLUTIONS, true)){
            $messages['resolution'] = 'resolution must be one of: ' . implode(', ', self::RESOLUTIONS) . '.';
        }
        if(!empty($messages)){
            $this->validationError($messages, 'GET /brewer-lead');
            return;
        }

        $free = time() - self::CLAIM_TTL;
        $where = array();
        $params = array();
        $order = 'l.createdAt ASC';
        if($status === 'queued'){
            $where[] = "l.status='open' AND (l.claimedAt IS NULL OR l.claimedAt < ?)";
            $params[] = $free;
        }elseif($status === 'claimed'){
            $where[] = "l.status='open' AND l.claimedAt >= ?";
            $params[] = $free;
        }elseif($status === 'closed'){
            $where[] = "l.status='closed'";
            $order = 'l.resolvedAt DESC';
        }
        if(!is_null($resolution)){
            $where[] = 'l.resolution=?';
            $params[] = $resolution;
        }
        if($needsDecisionOnly){
            $where[] = 'l.needsDecision=1';
            $order = 'l.createdAt ASC';
        }
        $sql = "SELECT " . self::LEAD_COLUMNS . " FROM " . self::LEAD_FROM;
        if(!empty($where)){
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY $order LIMIT ? OFFSET ?";
        $params[] = $count + 1;
        $params[] = $offset;

        $db = new Database();
        $result = $db->query($sql, $params);
        if($db->error){
            $this->dbError($db, 'GET /brewer-lead');
            $db->close();
            return;
        }
        $data = array();
        while($row = $result->fetch_assoc()){
            $data[] = $this->leadObject($row);
        }
        $db->close();

        $hasMore = count($data) > $count;
        if($hasMore){
            array_pop($data);
        }
        $this->json['object'] = 'list';
        $this->json['url'] = '/brewer-lead';
        if(!is_null($status)){
            $this->json['status'] = $status;
        }
        if(!is_null($resolution)){
            $this->json['resolution'] = $resolution;
        }
        if($needsDecisionOnly){
            $this->json['needs_decision'] = true;
        }
        $this->json['has_more'] = $hasMore;
        if($hasMore){
            $this->json['next_cursor'] = base64_encode($offset + $count);
        }
        $this->json['data'] = $data;
    }

    // ----- PATCH /brewer-lead/{id} -----
    // Four operations, one per call, told apart by the field that names it:
    //
    //   resolution     resolve: close the row, permanently
    //   recheck_after  defer: back to the queue, held until then; note required
    //   needs_decision ask: hold the row out of the claim until a human answers
    //   decision       decide: the human's answer; the next claim leads with it
    //
    // Any open row accepts a resolve, defer or ask, held by the caller or not
    // (a human session that never claimed can still record what it did). A
    // closed row is 409 unless the body carries reopen: true -- the whole
    // correction mechanism, since every key here is admin and the server
    // cannot tell one from another.
    public function update($leadID, $data){
        $messages = array();
        $ops = array();
        foreach(array('resolution', 'recheck_after', 'needs_decision', 'decision') as $field){
            if(property_exists($data, $field)){
                $ops[] = $field;
            }
        }
        if(empty($ops)){
            $messages['resolution'] = 'Send one of: resolution (resolve), recheck_after (defer), needs_decision + question (ask), or decision (decide).';
            $this->validationError($messages, 'PATCH /brewer-lead/{id}');
            return;
        }
        if(count($ops) > 1){
            foreach($ops as $field){
                $messages[$field] = 'Send one operation per request: ' . implode(' and ', $ops) . ' cannot be combined.';
            }
            $this->validationError($messages, 'PATCH /brewer-lead/{id} - combined');
            return;
        }
        $op = $ops[0];
        $reopen = isset($data->reopen) && $data->reopen === true;

        // Validate the operation's own fields before touching the row
        $resolution = null; $brewerID = null; $recheckAfter = null; $note = null; $question = null; $decision = null;
        $noteSent = property_exists($data, 'note');
        switch($op){
            case 'resolution':
                $resolution = isset($data->resolution) ? TextInput::trim(strval($data->resolution)) : '';
                if(!in_array($resolution, self::RESOLUTIONS, true)){
                    $messages['resolution'] = 'resolution must be one of: ' . implode(', ', self::RESOLUTIONS) . '.';
                    break;
                }
                $brewerSent = isset($data->brewer_id) && !is_null($data->brewer_id) && $data->brewer_id !== '';
                if(in_array($resolution, self::WITH_BREWER, true)){
                    if(!$brewerSent){
                        $messages['brewer_id'] = "brewer_id is required with resolution $resolution.";
                    }else{
                        $brewerID = trim(strval($data->brewer_id));
                        $uuid = new uuid();
                        $brewer = new Brewer();
                        if(!$uuid->validate($brewerID) || !$brewer->validate($brewerID, false)){
                            $messages['brewer_id'] = 'brewer_id must be an existing brewer.';
                        }
                    }
                }elseif($brewerSent){
                    $messages['brewer_id'] = "brewer_id is not accepted with resolution $resolution.";
                }
                break;
            case 'recheck_after':
                if(!isset($data->recheck_after) || !is_int($data->recheck_after) || $data->recheck_after <= time()){
                    $messages['recheck_after'] = 'recheck_after must be a Unix timestamp in the future.';
                }else{
                    $recheckAfter = $data->recheck_after;
                }
                $note = $this->multiLine($data, 'note', 2000, $messages);
                if(is_null($note) && !isset($messages['note'])){
                    $messages['note'] = 'note is required when deferring: say what was checked and what was missing.';
                }
                break;
            case 'needs_decision':
                if(!isset($data->needs_decision) || $data->needs_decision !== true){
                    $messages['needs_decision'] = 'needs_decision must be true, with a question.';
                }
                $question = $this->singleLine($data, 'question', 500, $messages);
                if(is_null($question) && !isset($messages['question'])){
                    $messages['question'] = 'question is required when needs_decision is true.';
                }
                if($noteSent){
                    $note = $this->multiLine($data, 'note', 2000, $messages);
                }
                break;
            case 'decision':
                $decision = $this->singleLine($data, 'decision', 500, $messages);
                if(is_null($decision) && !isset($messages['decision'])){
                    $messages['decision'] = 'decision is required.';
                }
                break;
        }
        if(!empty($messages)){
            $this->validationError($messages, "PATCH /brewer-lead/{id} - $op");
            return;
        }

        // The row as it stands
        $leadID = trim($leadID ?? '');
        $db = new Database();
        $result = $db->query("SELECT status, needsDecision+0 AS needsDecision FROM brewer_lead WHERE id=?", [$leadID]);
        if($db->error){
            $this->dbError($db, 'PATCH /brewer-lead/{id} - read');
            $db->close();
            return;
        }
        if($result->num_rows !== 1){
            $db->close();
            $this->notFound($leadID, 'PATCH /brewer-lead/{id}');
            return;
        }
        $row = $result->fetch_assoc();
        $closed = $row['status'] === 'closed';
        $asked = intval($row['needsDecision']) === 1;

        if($op === 'decision'){
            if(!$asked){
                $db->close();
                $messages['decision'] = 'This lead has no open question to decide.';
                $this->validationError($messages, 'PATCH /brewer-lead/{id} - no question', 409);
                return;
            }
        }elseif($closed && !$reopen){
            $db->close();
            $messages['status'] = 'This lead is closed. Send reopen: true to change its resolution or return it to the queue.';
            $this->validationError($messages, 'PATCH /brewer-lead/{id} - closed', 409);
            return;
        }elseif($op === 'needs_decision' && $asked){
            $db->close();
            $messages['question'] = 'This lead already has an open question; it must be decided first.';
            $this->validationError($messages, 'PATCH /brewer-lead/{id} - already asked', 409);
            return;
        }

        $now = time();
        switch($op){
            case 'resolution':
                $db->query("UPDATE brewer_lead SET status='closed', resolution=?, brewerID=?, resolvedBy=?, resolvedAt=?, recheckAfter=NULL, claimedBy=NULL, claimedAt=NULL WHERE id=?", [$resolution, $brewerID, $this->userID, $now, $leadID]);
                break;
            case 'recheck_after':
                // Back to the queue. A carried decision has been acted on, and a
                // reopened row sheds its resolution.
                $db->query("UPDATE brewer_lead SET status='open', recheckAfter=?, note=?, claimedBy=NULL, claimedAt=NULL, decision=NULL, decidedAt=NULL, decidedBy=NULL, resolution=NULL, brewerID=NULL, resolvedBy=NULL, resolvedAt=NULL WHERE id=?", [$recheckAfter, $note, $leadID]);
                break;
            case 'needs_decision':
                $set = "needsDecision=1, question=?, claimedBy=NULL, claimedAt=NULL, decision=NULL, decidedAt=NULL, decidedBy=NULL";
                $params = [$question];
                if($noteSent){
                    $set .= ', note=?';
                    $params[] = $note;
                }
                if($closed){
                    $set .= ", status='open', resolution=NULL, brewerID=NULL, resolvedBy=NULL, resolvedAt=NULL";
                }
                $params[] = $leadID;
                $db->query("UPDATE brewer_lead SET $set WHERE id=?", $params);
                break;
            case 'decision':
                $db->query("UPDATE brewer_lead SET decision=?, decidedAt=?, decidedBy=?, needsDecision=0 WHERE id=?", [$decision, $now, $this->userID, $leadID]);
                break;
        }
        if($db->error){
            $this->dbError($db, "PATCH /brewer-lead/{id} - $op");
            $db->close();
            return;
        }
        $db->close();
        $this->get($leadID);
    }

    // ----- Router -----
    public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
        /*---
        POST  https://api.catalog.beer/brewer-lead
        POST  https://api.catalog.beer/brewer-lead/claim
        GET   https://api.catalog.beer/brewer-lead?status=&resolution=&needs_decision=1&count=&cursor=
        GET   https://api.catalog.beer/brewer-lead/{lead_id}
        PATCH https://api.catalog.beer/brewer-lead/{lead_id}
        ---*/
        if(!$this->authorize($apiKey, "$method /brewer-lead")){
            $this->json['error'] = true;
            $this->json['error_msg'] = $this->errorMsg;
            return;
        }

        switch($method){
            case 'GET':
                if(empty($function) && !empty($id)){
                    $this->get($id);
                }elseif(empty($function) && empty($id)){
                    $status = isset($_GET['status']) && $_GET['status'] !== '' ? strval($_GET['status']) : null;
                    $resolution = isset($_GET['resolution']) && $_GET['resolution'] !== '' ? strval($_GET['resolution']) : null;
                    $needsDecisionOnly = isset($_GET['needs_decision']) && in_array($_GET['needs_decision'], array('1', 'true'), true);
                    $this->listLeads($status, $resolution, $needsDecisionOnly, $count, $cursor);
                }else{
                    $this->invalidPath($function);
                }
                break;
            case 'POST':
                if($function === 'claim'){
                    $this->claim(isset($data->count) ? $data->count : 10);
                }elseif(empty($function) && empty($id)){
                    $this->add($data);
                }else{
                    $this->invalidPath($function);
                }
                break;
            case 'PATCH':
                if(empty($function) && !empty($id)){
                    $this->update($id, $data);
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
                $errorLog->errorNumber = 323;
                $errorLog->errorMsg = 'Invalid Method (/brewer-lead)';
                $errorLog->badData = $method;
                $errorLog->filename = 'BrewerLead.class.php';
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
        $errorLog->errorNumber = 322;
        $errorLog->errorMsg = 'Invalid function (/brewer-lead)';
        $errorLog->badData = $function;
        $errorLog->filename = 'BrewerLead.class.php';
        $errorLog->write();
    }
}
?>
