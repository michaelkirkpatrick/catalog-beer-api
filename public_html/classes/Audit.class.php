<?php
/*--
Audit.class.php — the append-only audit trail behind the `audit_log` table.

One table records every change to anything that matters, with the acting
user, key, IP and time (design: Claude Ideas/audit-log.md):

- Catalog records (brewer, beer, location — the address is columns on the
  location row): each row carries the complete database row as it stood AFTER
  the change, read back with SELECT * the moment the write lands. A revert
  writes the previous snapshot back; a delete is recoverable from the row
  before its tombstone. Diffs are computed at display time.
- Settings (spend cap, billing on/off, Stripe customer, email, email
  verification, password, API keys, brewery privileges): each row carries the
  field, the old value and the new value. The password is recorded as having
  changed, never as a value.

Three rules. No foreign key to any entity table (a cascading history erases
its own evidence). Never pruned — excluded by name from
cron/prune-api-logging.php and anything like it. Deletes write a row
(action='delete', snapshot NULL); the previous row holds the last content.

Who: index.php calls setContext() once a key has authenticated, so every row
written during the request carries the key's user, the key itself (master
keys included — the opposite of api_logging) and the remote address. The
Stripe webhook sets source 'webhook'; a CLI script that never sets a context
is 'cron'; cron/backfill-audit.php is 'backfill'. 'admin' and 'cleanup' are in
the enum for tooling that wants to label itself and are unused today.

Writes that change nothing: before inserting a record row the new snapshot is
compared with the previous row's for the same entity, ignoring the
timestamp-only columns (lastModified, validatedAt). Equal means no row — a PUT
that re-sends the current values is not a revision. Settings rows are never
compared: a set to the same value is still a record of someone touching it.

A failed audit insert never fails the write it describes: the entity row is
already stored, so the failure is logged (371) and the request continues.
--*/
class Audit {

    // Response state for the /audit route (api() below)
    public $error = false;
    public $errorMsg = null;
    public $responseCode = 200;
    public $responseHeader = '';
    public $json = array();

    // Error numbers: 371 row not written, 372 snapshot missing, 373 admin
    // gate on the history/feed/revert routes, 374 database error on a read,
    // 375 malformed filter or paging, 376 revert target refused, 377 method
    // on /audit.

    // Snapshot kinds and the table each is read from. The entity id is the
    // table's primary key everywhere.
    const TABLES = array('brewer' => 'brewer', 'beer' => 'beer', 'location' => 'location');
    const TYPES = array('brewer', 'beer', 'location', 'user', 'api_key', 'privilege');
    const ACTIONS = array('create', 'update', 'delete', 'revert', 'set');
    const SOURCES = array('api', 'admin', 'webhook', 'cron', 'cleanup', 'backfill');

    // Columns a no-op comparison ignores: they say WHEN, not WHAT.
    const TIMESTAMP_COLUMNS = array('lastModified', 'validatedAt');

    const HISTORY_DEFAULT_COUNT = 50;
    const HISTORY_MAX_COUNT = 200;

    private static $actorID = null;
    private static $actorKey = null;
    private static $source = null;
    private static $ipAddress = null;
    // Set by beginRevert(); the next record() for a create/update becomes the
    // 'revert' row that carries revertOf. See Brewer/Beer/Location::revert().
    private static $pendingRevert = null;

    /* ----- Context ----- */

    public static function setContext($actorID, $actorKey, $source = 'api', $ipAddress = null){
        self::$actorID = !empty($actorID) ? $actorID : null;
        self::$actorKey = !empty($actorKey) ? $actorKey : null;
        self::$source = in_array($source, self::SOURCES, true) ? $source : 'api';
        self::$ipAddress = !empty($ipAddress) ? substr($ipAddress, 0, 45) : null;
    }

    public static function context(){
        $source = self::$source;
        if($source === null){
            $source = (PHP_SAPI === 'cli') ? 'cron' : 'api';
        }
        $ip = self::$ipAddress;
        if($ip === null && isset($_SERVER['REMOTE_ADDR'])){
            $ip = substr($_SERVER['REMOTE_ADDR'], 0, 45);
        }
        return array('actorID' => self::$actorID, 'actorKey' => self::$actorKey, 'source' => $source, 'ipAddress' => $ip);
    }

    public static function beginRevert($auditID, $note = null){
        self::$pendingRevert = array('of' => intval($auditID), 'note' => $note);
    }

    public static function cancelRevert(){
        self::$pendingRevert = null;
    }

    /* ----- Writes ----- */

    /*--
    A record row. $action is create, update or delete (revert is applied by
    beginRevert()). For a snapshot kind the row is read back now; for user and
    api_key the row carries no snapshot and the action alone is the record
    (an account was opened, a key was issued or withdrawn).
    Returns the new audit_log id, or null when nothing was written.
    --*/
    public static function record($entityType, $entityID, $action, $note = null){
        if(!in_array($entityType, self::TYPES, true) || !in_array($action, array('create', 'update', 'delete'), true) || empty($entityID)){
            self::logError(371, 'Audit::record() refused its arguments', "$entityType / $entityID / $action");
            return null;
        }

        $snapshot = null;
        $revertOf = null;
        if($action !== 'delete' && isset(self::TABLES[$entityType])){
            $snapshot = self::snapshot($entityType, $entityID);
            if($snapshot === null){
                // The write reported success and the row is not there: worth a
                // loud log, since the history now has a hole.
                self::logError(372, 'Audit snapshot missing after write', "$entityType / $entityID / $action");
                return null;
            }
            $previous = self::latest($entityType, $entityID);
            if($previous !== null && $previous['action'] !== 'delete' && self::sameContent($previous['snapshot'], $snapshot)){
                // Not a revision: nothing but a timestamp moved.
                self::$pendingRevert = null;
                return null;
            }
        }
        if(self::$pendingRevert !== null && $action !== 'delete'){
            $action = 'revert';
            $revertOf = self::$pendingRevert['of'];
            if($note === null){
                $note = self::$pendingRevert['note'];
            }
            self::$pendingRevert = null;
        }

        return self::insert(array(
            'entityType' => $entityType,
            'entityID' => $entityID,
            'action' => $action,
            'snapshot' => $snapshot === null ? null : self::encode($snapshot),
            'revertOf' => $revertOf,
            'note' => $note
        ));
    }

    /*--
    Tombstones for rows MySQL removed by cascade — a brewer delete takes its
    beers and locations with it without passing through Beer/Location::delete().
    One row each, in chunks, so a brewer with a thousand beers is one
    statement per 200 rather than a thousand round trips.
    --*/
    public static function recordDeletes($entityType, array $entityIDs, $note = null){
        if(empty($entityIDs) || !in_array($entityType, self::TYPES, true)){
            return 0;
        }
        $ctx = self::context();
        $now = time();
        $written = 0;
        $db = new Database();
        foreach(array_chunk(array_values($entityIDs), 200) as $chunk){
            $values = array();
            $params = array();
            foreach($chunk as $id){
                $values[] = "(?, ?, 'delete', ?, ?, ?, ?, ?, ?)";
                array_push($params, $entityType, $id, $ctx['actorID'], $ctx['actorKey'], $ctx['source'], $ctx['ipAddress'], $note, $now);
            }
            $db->query("INSERT INTO audit_log (entityType, entityID, action, actorID, actorKey, source, ipAddress, note, changedAt) VALUES " . implode(', ', $values), $params);
            if($db->error){
                self::logError(371, 'Audit cascade tombstones not written', "$entityType x" . count($chunk) . ' / ' . $db->errorMsg);
                $db->error = false;
                $db->errorMsg = null;
                $db->responseCode = 200;
                continue;
            }
            $written += count($chunk);
        }
        $db->close();
        return $written;
    }

    /*--
    The baseline: one action='create', source='backfill' row per snapshot,
    stamped with the row's own createdAt (lastModified when that is 0) so the
    history reads as the record's life, not as the day the log began. Written
    in one statement per call by cron/backfill-audit.php, which checks for
    existing rows first; nothing here does. Returns the number written.
    --*/
    public static function backfill($entityType, array $snapshots){
        if(empty($snapshots) || !isset(self::TABLES[$entityType])){
            return 0;
        }
        $values = array();
        $params = array();
        foreach($snapshots as $snapshot){
            $changedAt = intval($snapshot['createdAt'] ?? 0);
            if($changedAt <= 0){
                $changedAt = intval($snapshot['lastModified'] ?? time());
            }
            $values[] = "(?, ?, 'create', ?, 'backfill', 'baseline', ?)";
            array_push($params, $entityType, $snapshot['id'], self::encode($snapshot), $changedAt);
        }
        $db = new Database();
        $db->query("INSERT INTO audit_log (entityType, entityID, action, snapshot, source, note, changedAt) VALUES " . implode(', ', $values), $params);
        $written = $db->error ? 0 : count($snapshots);
        if($db->error){
            self::logError(371, 'Audit baseline batch not written', "$entityType x" . count($snapshots) . ' / ' . $db->errorMsg);
        }
        $db->close();
        return $written;
    }

    /*--
    A settings row: $field moved from $oldValue to $newValue on $entityType
    $entityID. Values are stored as text; booleans arrive as 0/1 so the two
    sides compare the way the column does. Never compared with the previous
    row — a set to the same value is still a record of someone touching it.
    --*/
    public static function set($entityType, $entityID, $field, $oldValue, $newValue, $note = null){
        if(!in_array($entityType, self::TYPES, true) || empty($entityID) || empty($field)){
            self::logError(371, 'Audit::set() refused its arguments', "$entityType / $entityID / $field");
            return null;
        }
        return self::insert(array(
            'entityType' => $entityType,
            'entityID' => $entityID,
            'action' => 'set',
            'field' => $field,
            'oldValue' => self::text($oldValue),
            'newValue' => self::text($newValue),
            'note' => $note
        ));
    }

    private static function text($value){
        if($value === null){
            return null;
        }
        if(is_bool($value)){
            return $value ? '1' : '0';
        }
        if(is_array($value) || is_object($value)){
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return strval($value);
    }

    private static function insert(array $row){
        $ctx = self::context();
        $db = new Database();
        $db->query("INSERT INTO audit_log (entityType, entityID, action, snapshot, field, oldValue, newValue, revertOf, actorID, actorKey, source, ipAddress, note, changedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $row['entityType'], $row['entityID'], $row['action'],
            $row['snapshot'] ?? null, $row['field'] ?? null, $row['oldValue'] ?? null, $row['newValue'] ?? null,
            $row['revertOf'] ?? null,
            $ctx['actorID'], $ctx['actorKey'], $ctx['source'], $ctx['ipAddress'],
            isset($row['note']) ? substr($row['note'], 0, 255) : null,
            time()
        ]);
        if($db->error){
            self::logError(371, 'Audit row not written', $row['entityType'] . ' / ' . $row['entityID'] . ' / ' . $row['action'] . ' / ' . $db->errorMsg);
            $db->close();
            return null;
        }
        $id = $db->getInsertId();
        $db->close();
        return $id;
    }

    /* ----- Snapshots ----- */

    /*--
    The row as it stands: SELECT * on the entity's table, with bit(1) columns
    as 0/1 and JSON columns decoded, so the snapshot encodes cleanly and reads
    back as the same PHP values it was taken from. Null when the row is gone.
    --*/
    public static function snapshot($entityType, $entityID){
        if(!isset(self::TABLES[$entityType])){
            return null;
        }
        $db = new Database();
        $result = $db->query("SELECT * FROM " . self::TABLES[$entityType] . " WHERE id=?", [$entityID]);
        $row = null;
        if(!$db->error && $result !== null && $result->num_rows === 1){
            $row = self::normalizeRow($result, $result->fetch_assoc());
        }
        $db->close();
        return $row;
    }

    public static function normalizeRow(mysqli_result $result, array $row){
        foreach($result->fetch_fields() as $field){
            if(!array_key_exists($field->name, $row) || $row[$field->name] === null){
                continue;
            }
            if($field->type === MYSQLI_TYPE_BIT){
                // bit(1) arrives as an int or a digit string from a prepared
                // statement and as a raw byte from a plain query
                $v = $row[$field->name];
                if(is_string($v) && strlen($v) === 1 && !ctype_digit($v)){
                    $row[$field->name] = ord($v) ? 1 : 0;
                }else{
                    $row[$field->name] = intval($v) ? 1 : 0;
                }
            }elseif($field->type === MYSQLI_TYPE_JSON){
                $decoded = json_decode($row[$field->name], true);
                if(json_last_error() === JSON_ERROR_NONE){
                    $row[$field->name] = $decoded;
                }
            }
        }
        return $row;
    }

    public static function encode(array $snapshot){
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // Equal content: every column the same except the timestamp-only ones.
    public static function sameContent($a, $b){
        if(!is_array($a) || !is_array($b)){
            return false;
        }
        foreach(self::TIMESTAMP_COLUMNS as $col){
            unset($a[$col], $b[$col]);
        }
        return $a == $b;
    }

    // The columns whose value differs between two snapshots (either side may
    // be null: a create or a delete differs in nothing, by definition).
    public static function diff($previous, $next){
        if(!is_array($previous) || !is_array($next)){
            return array();
        }
        $changed = array();
        foreach(array_unique(array_merge(array_keys($previous), array_keys($next))) as $col){
            $a = $previous[$col] ?? null;
            $b = $next[$col] ?? null;
            if($a != $b || (($a === null) !== ($b === null))){
                $changed[] = $col;
            }
        }
        return $changed;
    }

    /* ----- Reads ----- */

    // The most recent row for an entity, snapshot decoded; null when none.
    public static function latest($entityType, $entityID){
        $db = new Database();
        $result = $db->query("SELECT * FROM audit_log WHERE entityType=? AND entityID=? ORDER BY id DESC LIMIT 1", [$entityType, $entityID]);
        $row = null;
        if(!$db->error && $result !== null && $result->num_rows === 1){
            $row = self::decodeRow($result->fetch_assoc());
        }
        $db->close();
        return $row;
    }

    // One row by id, snapshot decoded; null when none.
    public static function row($auditID){
        $db = new Database();
        $result = $db->query("SELECT * FROM audit_log WHERE id=?", [intval($auditID)]);
        $row = null;
        if(!$db->error && $result !== null && $result->num_rows === 1){
            $row = self::decodeRow($result->fetch_assoc());
        }
        $db->close();
        return $row;
    }

    private static function decodeRow(array $row){
        $row['id'] = intval($row['id']);
        $row['revertOf'] = $row['revertOf'] === null ? null : intval($row['revertOf']);
        $row['changedAt'] = intval($row['changedAt']);
        $row['snapshot'] = $row['snapshot'] === null ? null : json_decode($row['snapshot'], true);
        return $row;
    }

    /*--
    The revisions of one entity, newest first, each with the fields that
    differ from the revision before it (computed here, on read). Returns the
    list object or null on a database error (the caller reports it).
    --*/
    public static function history($entityType, $entityID, $count, $cursor){
        $offset = self::offset($cursor);
        $limit = self::limit($count);
        if($offset === null || $limit === null){
            return false;
        }
        $db = new Database();
        // One extra row past the page tells has_more; one more past that is
        // the revision before the oldest shown, for its diff.
        $result = $db->query("SELECT al.*, u.name AS actorName, u.email AS actorEmail FROM audit_log al LEFT JOIN users u ON al.actorID = u.id WHERE al.entityType=? AND al.entityID=? ORDER BY al.id DESC LIMIT ? OFFSET ?", [$entityType, $entityID, $limit + 2, $offset]);
        if($db->error || $result === null){
            $db->close();
            return null;
        }
        $rows = array();
        while($r = $result->fetch_assoc()){
            $rows[] = self::decodeRow($r);
        }
        $db->close();

        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);
        $data = array();
        foreach($page as $i => $row){
            // The previous revision with a snapshot, for this row's diff
            $previous = null;
            for($j = $i + 1; $j < count($rows); $j++){
                if($rows[$j]['snapshot'] !== null){
                    $previous = $rows[$j]['snapshot'];
                    break;
                }
                if($rows[$j]['action'] === 'delete'){
                    break;  // a restore after a delete: everything is new
                }
            }
            $data[] = self::revisionObject($row, $previous, true);
        }

        $list = array(
            'object' => 'list',
            'url' => '/' . $entityType . '/' . $entityID . '/history',
            'entity_type' => $entityType,
            'entity_id' => $entityID,
            'has_more' => $hasMore
        );
        if($hasMore){
            $list['next_cursor'] = base64_encode(strval($offset + $limit));
        }
        $list['data'] = $data;
        return $list;
    }

    /*--
    The admin feed: every row, newest first, filtered by entity_type,
    entity_id, actor (users.id), action, source and since (unix seconds,
    inclusive). Rows carry no snapshot — the history route has those.
    Returns the list, false when a filter is malformed (the caller 400s),
    null on a database error.
    --*/
    public static function feed(array $filters, $count, $cursor){
        $offset = self::offset($cursor);
        $limit = self::limit($count);
        if($offset === null || $limit === null){
            return false;
        }
        $where = array();
        $params = array();
        $echo = array();
        foreach(array('entity_type' => array('entityType', self::TYPES), 'action' => array('action', self::ACTIONS), 'source' => array('source', self::SOURCES)) as $key => $spec){
            if(isset($filters[$key]) && $filters[$key] !== ''){
                if(!is_string($filters[$key]) || !in_array($filters[$key], $spec[1], true)){
                    return false;
                }
                $where[] = 'al.' . $spec[0] . '=?';
                $params[] = $filters[$key];
                $echo[$key] = $filters[$key];
            }
        }
        foreach(array('entity_id' => 'entityID', 'actor' => 'actorID') as $key => $col){
            if(isset($filters[$key]) && $filters[$key] !== ''){
                if(!is_string($filters[$key]) || !preg_match('/^[0-9a-f-]{36}$/', $filters[$key])){
                    return false;
                }
                $where[] = 'al.' . $col . '=?';
                $params[] = $filters[$key];
                $echo[$key] = $filters[$key];
            }
        }
        if(isset($filters['since']) && $filters['since'] !== ''){
            if(!is_string($filters['since']) || !preg_match('/^[0-9]{1,10}$/', $filters['since'])){
                return false;
            }
            $where[] = 'al.changedAt>=?';
            $params[] = intval($filters['since']);
            $echo['since'] = intval($filters['since']);
        }
        $sql = "SELECT al.*, u.name AS actorName, u.email AS actorEmail FROM audit_log al LEFT JOIN users u ON al.actorID = u.id";
        if(!empty($where)){
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY al.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit + 1;
        $params[] = $offset;

        $db = new Database();
        $result = $db->query($sql, $params);
        if($db->error || $result === null){
            $db->close();
            return null;
        }
        $rows = array();
        while($r = $result->fetch_assoc()){
            $rows[] = self::decodeRow($r);
        }
        $db->close();

        $hasMore = count($rows) > $limit;
        $list = array('object' => 'list', 'url' => '/audit', 'has_more' => $hasMore);
        foreach($echo as $k => $v){
            $list[$k] = $v;
        }
        if($hasMore){
            $list['next_cursor'] = base64_encode(strval($offset + $limit));
        }
        $data = array();
        foreach(array_slice($rows, 0, $limit) as $row){
            $data[] = self::revisionObject($row, null, false);
        }
        $list['data'] = $data;
        return $list;
    }

    /*--
    The wire shape of one audit row. An API key is never echoed: an api_key
    entity and the acting key are both reduced to their last four characters,
    the way GET /usage identifies keys.
    --*/
    public static function revisionObject(array $row, $previousSnapshot, $withSnapshot){
        $actor = null;
        if($row['actorID'] !== null || $row['actorKey'] !== null){
            $actor = array(
                'user_id' => $row['actorID'],
                'name' => $row['actorName'] ?? null,
                'api_key_last4' => $row['actorKey'] === null ? null : substr($row['actorKey'], -4)
            );
        }
        $object = array(
            'object' => 'revision',
            'id' => $row['id'],
            'entity_type' => $row['entityType'],
            'entity_id' => $row['entityType'] === 'api_key' ? '…' . substr($row['entityID'], -4) : $row['entityID'],
            'action' => $row['action'],
            'changed_at' => $row['changedAt'],
            'source' => $row['source'],
            'actor' => $actor,
            'ip_address' => $row['ipAddress'],
            'note' => $row['note'],
            'revert_of' => $row['revertOf']
        );
        if($row['action'] === 'set'){
            $object['field'] = $row['field'];
            $object['old_value'] = $row['oldValue'];
            $object['new_value'] = $row['newValue'];
        }elseif(isset(self::TABLES[$row['entityType']])){
            $object['changed_fields'] = ($row['snapshot'] !== null && $previousSnapshot !== null) ? self::diff($previousSnapshot, $row['snapshot']) : array();
            if($withSnapshot){
                $object['snapshot'] = $row['snapshot'];
            }
        }
        return $object;
    }

    private static function offset($cursor){
        $decoded = base64_decode(strval($cursor), true);
        if($decoded === false || !preg_match('/^[0-9]{1,9}$/', $decoded)){
            return null;
        }
        return intval($decoded);
    }

    private static function limit($count){
        if($count === null || $count === ''){
            return self::HISTORY_DEFAULT_COUNT;
        }
        if(!preg_match('/^[0-9]{1,3}$/', strval($count))){
            return null;
        }
        $n = intval($count);
        if($n < 1 || $n > self::HISTORY_MAX_COUNT){
            return null;
        }
        return $n;
    }

    /*--
    Admin gate shared by the history, feed and revert routes: the key must be
    valid and its account an admin (master keys included — their accounts are
    admins). Returns the apiKeys object on success, null after setting the
    error on $owner (the calling entity class) with $errorNumber.
    --*/
    public static function requireAdmin($apiKey, $owner, $errorNumber, $route){
        $apiKeys = new apiKeys();
        if(!$apiKeys->validate($apiKey, true)){
            $owner->error = true;
            $owner->errorMsg = 'Invalid API Key.';
            $owner->responseCode = 404;
            self::logError($errorNumber, "Invalid API Key ($route)", $apiKey);
            return null;
        }
        $users = new Users();
        $users->validate($apiKeys->userID, true);
        if(!$users->admin){
            $owner->error = true;
            $owner->errorMsg = 'Sorry, only an administrator may use this endpoint.';
            $owner->responseCode = 403;
            self::logError($errorNumber, "Forbidden: not an admin ($route)", 'userID: ' . $apiKeys->userID);
            return null;
        }
        return $apiKeys;
    }

    /* ----- Routes ----- */

    /*--
    GET /{brewer|beer|location}/{id}/history, admin only. Sets the owner's
    json and response code; the owner is the entity class whose api() routed
    here. An id with no rows is an empty list, not a 404: a deleted entity
    has a history, and the tombstone is the point.
    --*/
    public static function historyRoute($entityType, $entityID, $apiKey, $owner){
        if(self::requireAdmin($apiKey, $owner, 373, "GET /$entityType/{id}/history") === null){
            $owner->json['error'] = true;
            $owner->json['error_msg'] = $owner->errorMsg;
            return;
        }
        $list = self::history($entityType, $entityID, $_GET['count'] ?? null, $_GET['cursor'] ?? base64_encode('0'));
        if($list === false){
            $owner->error = true;
            $owner->errorMsg = 'count must be 1 to ' . self::HISTORY_MAX_COUNT . ' and cursor a value returned as next_cursor.';
            $owner->responseCode = 400;
            self::logError(375, "Malformed paging (GET /$entityType/{id}/history)", json_encode($_GET));
        }elseif($list === null){
            $owner->error = true;
            $owner->errorMsg = 'Sorry, there was an internal error reading the history. I\'ve logged the error for our support team.';
            $owner->responseCode = 500;
            self::logError(374, "Database error (GET /$entityType/{id}/history)", $entityID);
        }else{
            $owner->json = $list;
            return;
        }
        $owner->json['error'] = true;
        $owner->json['error_msg'] = $owner->errorMsg;
    }

    /*--
    The audit row a revert restores, or null after setting the owner's error:
    audit_id must be an integer naming a row of THIS entity that carries a
    snapshot (a tombstone has none; revert to the row before it).
    --*/
    public static function revertTarget($entityType, $entityID, $auditID, $owner){
        if(is_string($auditID) && ctype_digit($auditID)){
            $auditID = intval($auditID);   // a client that sends the id as a string
        }
        if(!is_int($auditID) || $auditID < 1){
            $owner->error = true;
            $owner->errorMsg = 'audit_id must be the integer id of one of this record\'s history rows.';
            $owner->responseCode = 400;
            self::logError(376, "Revert refused: bad audit_id (POST /$entityType/{id}/revert)", json_encode($auditID));
            return null;
        }
        $row = self::row($auditID);
        if($row === null){
            $owner->error = true;
            $owner->errorMsg = 'No history row has that audit_id.';
            $owner->responseCode = 404;
            self::logError(376, "Revert refused: unknown audit_id (POST /$entityType/{id}/revert)", strval($auditID));
            return null;
        }
        if($row['entityType'] !== $entityType || $row['entityID'] !== $entityID){
            $owner->error = true;
            $owner->errorMsg = 'That audit_id belongs to a different record.';
            $owner->responseCode = 400;
            self::logError(376, "Revert refused: audit_id of another entity (POST /$entityType/{id}/revert)", "$auditID is {$row['entityType']} {$row['entityID']}");
            return null;
        }
        if($row['snapshot'] === null){
            $owner->error = true;
            $owner->errorMsg = 'That history row is the deletion itself and holds no content; revert to the row before it.';
            $owner->responseCode = 400;
            self::logError(376, "Revert refused: tombstone (POST /$entityType/{id}/revert)", strval($auditID));
            return null;
        }
        return $row;
    }

    // The note a revert request carries, cut to the column
    public static function revertNote($note){
        if(!is_string($note)){
            return null;
        }
        $note = TextInput::trim($note);
        return $note === '' ? null : substr($note, 0, 255);
    }

    /*--
    GET /audit?entity_type=&entity_id=&actor=&action=&source=&since=&count=&cursor=
    The admin feed. Every row, newest first, without snapshots.
    --*/
    public function api($method, $function, $id, $apiKey){
        if($method !== 'GET'){
            $this->error = true;
            $this->errorMsg = 'Method Not Allowed. The audit feed is read-only.';
            $this->responseCode = 405;
            $this->responseHeader = 'Allow: GET';
            self::logError(377, 'Invalid Method (/audit)', $method);
        }elseif(self::requireAdmin($apiKey, $this, 373, 'GET /audit') !== null){
            $list = self::feed($_GET, $_GET['count'] ?? null, $_GET['cursor'] ?? base64_encode('0'));
            if($list === false){
                $this->error = true;
                $this->errorMsg = 'A filter is malformed: entity_type is one of ' . implode(', ', self::TYPES) . '; action one of ' . implode(', ', self::ACTIONS) . '; source one of ' . implode(', ', self::SOURCES) . '; entity_id and actor are UUIDs; since is unix seconds; count is 1 to ' . self::HISTORY_MAX_COUNT . '.';
                $this->responseCode = 400;
                self::logError(375, 'Malformed filter (GET /audit)', json_encode($_GET));
            }elseif($list === null){
                $this->error = true;
                $this->errorMsg = 'Sorry, there was an internal error reading the audit feed. I\'ve logged the error for our support team.';
                $this->responseCode = 500;
                self::logError(374, 'Database error (GET /audit)', json_encode($_GET));
            }else{
                $this->json = $list;
            }
        }
        if($this->error){
            $this->json['error'] = true;
            $this->json['error_msg'] = $this->errorMsg;
        }
    }

    private static function logError($number, $msg, $badData){
        $errorLog = new LogError();
        $errorLog->errorNumber = $number;
        $errorLog->errorMsg = $msg;
        $errorLog->badData = $badData;
        $errorLog->filename = 'API / Audit.class.php';
        $errorLog->write();
    }
}
?>
