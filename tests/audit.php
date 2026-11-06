<?php
/*--
Regression test for Audit.class.php — the append-only audit_log behind every
catalog write and every settings change. Needs a LOCAL MySQL (it creates and
drops a scratch database named cb_audit_test); no network, no secrets file,
no Apache.

    php tests/audit.php [path/to/catalog-beer-schema.sql]

The schema path defaults to ../catalog-beer-mysql/catalog-beer-schema.sql.
Connection defaults to root@127.0.0.1 with no password (Homebrew MySQL);
override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.

What it proves: every write path leaves a row that names the key's user, the
key and the source; the snapshot is the raw row after the write (bits as 0/1,
JSON decoded) and matches what SELECT * returns; a PUT or PATCH that re-sends
the current values leaves nothing; a delete leaves a tombstone with a NULL
snapshot and the previous row still holds the content; a brewer delete writes
a tombstone per cascaded beer and location; the settings sites (spend cap,
billing off, privileges, email, password, key issued, account deleted) write
field/old/new rows and are never de-duplicated; the webhook and CLI sources
are labelled; the history read lists newest first with the fields that
changed, the feed filters, and malformed paging is refused; a revert writes
the previous snapshot back through the entity's own PUT and records
action=revert with revertOf; and the canonical schema carries the table with
its three indexes and no foreign key, while the prune cron never names it.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__) . '/public_html');
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_audit_test');
// Algolia writes fail fast on a host that cannot be a URL; the class logs
// error 228 to the scratch error_log and carries on, which is the point.
define('ALGOLIA_APPLICATION_ID', '127.0.0.1:no');
define('ALGOLIA_SEARCH_API_KEY', 'x');
define('ALGOLIA_WRITE_API_KEY', 'x');
// Never called here: a path that reaches it would surface as a 500 (error 266).
define('GOOGLE_ADDRESS_VALIDATION_KEY', 'not-a-key');
define('MASTER_API_KEYS', serialize(array('bbbbbbbb-0000-4000-8000-000000000003')));
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

$schemaPath = $argv[1] ?? dirname(__DIR__, 2) . '/catalog-beer-mysql/catalog-beer-schema.sql';
if(!is_readable($schemaPath)){
    echo "Schema not found: $schemaPath\n";
    exit(1);
}

// ----- Scratch database: create, load schema, load fixtures -----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
$admin->query("DROP DATABASE IF EXISTS " . DB_NAME);
$admin->query("CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$admin->select_db(DB_NAME);
$sql = "SET FOREIGN_KEY_CHECKS=0;\n" . file_get_contents($schemaPath) . "\nSET FOREIGN_KEY_CHECKS=1;\n" . <<<'SQL'
INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES
 ('aaaaaaaa-0000-4000-8000-000000000001','admin@example.com','x','Admin',1,1),
 ('aaaaaaaa-0000-4000-8000-000000000002','plain@example.com','x','Plain User',1,0),
 ('aaaaaaaa-0000-4000-8000-000000000003','site@example.com','x','Site (master key)',1,1),
 ('aaaaaaaa-0000-4000-8000-000000000004','keyless@example.com','x','No Key Yet',1,0);
INSERT INTO api_keys (id,userID) VALUES
 ('bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001'),
 ('bbbbbbbb-0000-4000-8000-000000000002','aaaaaaaa-0000-4000-8000-000000000002'),
 ('bbbbbbbb-0000-4000-8000-000000000003','aaaaaaaa-0000-4000-8000-000000000003');
INSERT INTO style_class (slug,name,beverage_type) VALUES ('ale','Ale','beer');
INSERT INTO style_parent (slug,name,beverage_type,class) VALUES ('ipa','IPA','beer','ale');
INSERT INTO subdivisions (sub_code, countryCode, sub_name, sub_type) VALUES ('US-OR','US','Oregon','state');
SQL;
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Helpers -----
$ADMIN_USER = 'aaaaaaaa-0000-4000-8000-000000000001'; $PLAIN_USER = 'aaaaaaaa-0000-4000-8000-000000000002';
$ADMIN = 'bbbbbbbb-0000-4000-8000-000000000001'; $PLAIN = 'bbbbbbbb-0000-4000-8000-000000000002'; $MASTER = 'bbbbbbbb-0000-4000-8000-000000000003';
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;
function rows($type, $id){
    $r = $GLOBALS['admin']->query("SELECT * FROM audit_log WHERE entityType='$type' AND entityID='$id' ORDER BY id");
    $out = array();
    while($row = $r->fetch_assoc()){ $row['snapshot'] = $row['snapshot'] === null ? null : json_decode($row['snapshot'], true); $out[] = $row; }
    return $out;
}
function allRows(){ $r = $GLOBALS['admin']->query("SELECT * FROM audit_log ORDER BY id"); $o = array(); while($row = $r->fetch_assoc()){ $o[] = $row; } return $o; }
function last($type, $id){ $r = rows($type, $id); return end($r); }
function dbRow($table, $id){
    $r = $GLOBALS['admin']->query("SELECT * FROM $table WHERE id='$id'");
    if($r->num_rows !== 1){ return null; }
    return Audit::normalizeRow($r, $r->fetch_assoc());
}
function actsAs($key, $userID){ Audit::setContext($userID, $key, 'api', '203.0.113.7'); }

// ----- 1. Brewer create / no-op PUT / PATCH -----
actsAs($ADMIN, $ADMIN_USER);
$brewer = new Brewer();
$brewer->add('Audit Test Brewing', 'The first description.', '', '', $ADMIN_USER, 'POST', '', array(), array('country_code' => 'US'));
check("brewer POST -> 201", $brewer->responseCode === 201 && !$brewer->error);
$B = $brewer->brewerID;
$r = rows('brewer', $B);
check("brewer create leaves exactly one row, action create", count($r) === 1 && $r[0]['action'] === 'create');
check("row names the admin user, the key, source api and the IP", $r[0]['actorID'] === $ADMIN_USER && $r[0]['actorKey'] === $ADMIN && $r[0]['source'] === 'api' && $r[0]['ipAddress'] === '203.0.113.7');
check("snapshot equals the stored row, bits as 0/1", $r[0]['snapshot'] == dbRow('brewer', $B) && $r[0]['snapshot']['cbVerified'] === 1 && $r[0]['snapshot']['name'] === 'Audit Test Brewing');
check("snapshot carries every brewer column", count(array_diff(array('id','name','description','shortDescription','url','domainName','cbVerified','brewerVerified','status','foundedYear','closedYear','countryCode','lastModified','createdAt'), array_keys($r[0]['snapshot']))) === 0);

sleep(1);  // lastModified must move for the no-op PUT to be a real test
$brewer = new Brewer();
$brewer->add('Audit Test Brewing', 'The first description.', '', '', $ADMIN_USER, 'PUT', $B, array(), array('country_code' => 'US'));
check("PUT of the current values -> 200", $brewer->responseCode === 200 && !$brewer->error);
check("...and leaves no row, though lastModified moved", count(rows('brewer', $B)) === 1 && dbRow('brewer', $B)['lastModified'] !== rows('brewer', $B)[0]['snapshot']['lastModified']);

$brewer = new Brewer();
$brewer->add('', 'A second description.', '', '', $ADMIN_USER, 'PATCH', $B, array('description'), array());
check("PATCH description -> 200", $brewer->responseCode === 200 && !$brewer->error);
$r = rows('brewer', $B);
check("PATCH leaves an update row whose snapshot is the new row", count($r) === 2 && $r[1]['action'] === 'update' && $r[1]['snapshot']['description'] === 'A second description.' && $r[1]['snapshot'] == dbRow('brewer', $B));
check("diff between the two snapshots is description + lastModified", Audit::diff($r[0]['snapshot'], $r[1]['snapshot']) == array('description', 'lastModified'));

// a non-admin edit clears cbVerified: that is a change, recorded
actsAs($PLAIN, $PLAIN_USER);
$brewer = new Brewer();
$brewer->add('', 'A second description.', '', '', $PLAIN_USER, 'PATCH', $B, array('description'), array());
check("non-admin PATCH with the same description writes nothing (add() skips the UPDATE)", count(rows('brewer', $B)) === 2);
$brewer = new Brewer();
$brewer->add('', 'A third description.', '', '', $PLAIN_USER, 'PATCH', $B, array('description'), array());
$r = rows('brewer', $B);
check("non-admin PATCH records the plain user and the cleared badge", count($r) === 3 && $r[2]['actorID'] === $PLAIN_USER && $r[2]['snapshot']['cbVerified'] === 0 && in_array('cbVerified', Audit::diff($r[1]['snapshot'], $r[2]['snapshot'])));

// ----- 2. Beer create / no-op PUT / PATCH / delete -----
actsAs($ADMIN, $ADMIN_USER);
$beer = new Beer();
$beer->add($B, 'Audit IPA', 'IPA', '', '', '', '', 'Hoppy.', '6.5', '', $ADMIN_USER, 'POST', '', array());
check("beer POST -> 201", $beer->responseCode === 201 && !$beer->error);
$BEER = $beer->beerID;
$r = rows('beer', $BEER);
check("beer create row with the raw row as snapshot (abv as the column returns it)", count($r) === 1 && $r[0]['action'] === 'create' && $r[0]['snapshot'] == dbRow('beer', $BEER) && $r[0]['snapshot']['parent'] === 'ipa');
sleep(1);
$beer = new Beer();
$beer->add($B, 'Audit IPA', 'IPA', '', '', '', '', 'Hoppy.', '6.5', '', $ADMIN_USER, 'PUT', $BEER, array());
check("beer PUT of the current values -> 200 and no row", $beer->responseCode === 200 && count(rows('beer', $BEER)) === 1);
$beer = new Beer();
$beer->add('', '', '', '', '', '', '', '', '7.0', '', $ADMIN_USER, 'PATCH', $BEER, array('abv'));
$r = rows('beer', $BEER);
check("beer PATCH abv -> update row, diff abv + lastModified", $beer->responseCode === 200 && count($r) === 2 && Audit::diff($r[0]['snapshot'], $r[1]['snapshot']) == array('abv', 'lastModified'));

$beer2 = new Beer();
$beer2->add($B, 'Audit Pale', 'IPA', '', '', '', '', '', '5.0', '', $ADMIN_USER, 'POST', '', array());
$BEER2 = $beer2->beerID;
$beer2 = new Beer();
$beer2->delete($BEER2, $ADMIN_USER);
$r = rows('beer', $BEER2);
check("beer DELETE -> tombstone with NULL snapshot; the previous row holds the beer", !$beer2->error && count($r) === 2 && $r[1]['action'] === 'delete' && $r[1]['snapshot'] === null && $r[0]['snapshot']['name'] === 'Audit Pale');

// ----- 3. Location: a telephone-only PATCH (no Google) and delete -----
$L = 'dddddddd-0000-4000-8000-000000000001';
$admin->query("INSERT INTO location (id,brewerID,name,countryCode,line1,locality,sub_code,postalCode,formatted,languageCode,latitude,longitude,lastModified,createdAt) VALUES ('$L','$B','Taproom','US','5 Elm St','Portland','US-OR','97201','[\"5 Elm St\",\"Portland, OR 97201\"]','en',45.5152,-122.6784,1,1)");
$addr = array_fill_keys(Address::FIELDS, '');
$addr['telephone'] = '(503) 555-0100';
$location = new Location();
$location->add('', '', '', '', $ADMIN_USER, 'PATCH', $L, array(), $addr, array('telephone'));
check("location telephone PATCH -> 200", $location->responseCode === 200 && !$location->error);
$r = rows('location', $L);
check("first write to a pre-existing row is recorded as update with the E.164 telephone", count($r) === 1 && $r[0]['action'] === 'update' && $r[0]['snapshot']['telephone'] === '+15035550100');
check("JSON column decoded, bits as ints, floats as floats", is_array($r[0]['snapshot']['formatted']) && in_array($r[0]['snapshot']['brewerVerified'], array(0, 1), true) && is_float($r[0]['snapshot']['latitude']));
check("bits are 0/1, not the digit's byte value", dbRow('brewer', $B)['cbVerified'] === 0 || dbRow('brewer', $B)['cbVerified'] === 1);
check("snapshot equals the stored location row", $r[0]['snapshot'] == dbRow('location', $L));

// ----- 4. Brewer delete: tombstone + a tombstone per cascaded child -----
$L2 = 'dddddddd-0000-4000-8000-000000000002';
$admin->query("INSERT INTO location (id,brewerID,name,countryCode,lastModified,createdAt) VALUES ('$L2','$B','Second','US',1,1)");
$brewer = new Brewer();
$brewer->delete($B, $ADMIN_USER);
check("brewer DELETE -> ok", !$brewer->error);
$r = rows('brewer', $B);
check("brewer tombstone appended, previous row holds the brewer", count($r) === 4 && $r[3]['action'] === 'delete' && $r[3]['snapshot'] === null && $r[2]['snapshot']['name'] === 'Audit Test Brewing');
$rb = rows('beer', $BEER); $rl = rows('location', $L); $rl2 = rows('location', $L2);
check("cascaded beer gets a tombstone with the cascade note", count($rb) === 3 && $rb[2]['action'] === 'delete' && $rb[2]['note'] === 'cascade: brewer deleted' && $rb[2]['actorID'] === $ADMIN_USER);
check("cascaded locations get tombstones, even one never written through the API", count($rl) === 2 && $rl[1]['action'] === 'delete' && count($rl2) === 1 && $rl2[0]['action'] === 'delete');
check("beer deleted earlier is not tombstoned twice", count(rows('beer', $BEER2)) === 2);

// ----- 5. Settings -----
$billing = new Billing();
$billing->updateSpendCap($PLAIN, (object)array('monthly_spend_cap_cents' => 2000));
$r = rows('api_key', $PLAIN);
check("spend cap PATCH -> set row 5000 -> 2000", !$billing->error && count($r) === 1 && $r[0]['action'] === 'set' && $r[0]['field'] === 'monthlySpendCapCents' && $r[0]['oldValue'] === '5000' && $r[0]['newValue'] === '2000');
$billing = new Billing();
$billing->updateSpendCap($PLAIN, (object)array('monthly_spend_cap_cents' => 2000));
check("a set to the same value is still recorded", count(rows('api_key', $PLAIN)) === 2);
$admin->query("UPDATE api_keys SET billingEnabled=1 WHERE id='$PLAIN'");
$billing = new Billing();
$billing->disableBilling($PLAIN);
$r = last('api_key', $PLAIN);
check("DELETE /billing -> set billingEnabled 1 -> 0", $r['field'] === 'billingEnabled' && $r['oldValue'] === '1' && $r['newValue'] === '0');

$admin->query("INSERT INTO brewer (id,name,lastModified,createdAt) VALUES ('cccccccc-0000-4000-8000-000000000009','Privilege Brewing',1,1)");
$priv = new Privileges();
$priv->add($PLAIN_USER, 'cccccccc-0000-4000-8000-000000000009', false);
$r = rows('privilege', $PLAIN_USER);
check("privilege granted -> set row keyed by the user, brewerID null -> id", count($r) === 1 && $r[0]['field'] === 'brewerID' && $r[0]['oldValue'] === null && $r[0]['newValue'] === 'cccccccc-0000-4000-8000-000000000009');
$priv->add($PLAIN_USER, 'cccccccc-0000-4000-8000-000000000009', false);
check("granting again (already held) writes nothing", count(rows('privilege', $PLAIN_USER)) === 1);
$priv->remove($PLAIN_USER, 'cccccccc-0000-4000-8000-000000000009');
$r = rows('privilege', $PLAIN_USER);
check("privilege removed -> set row id -> null", count($r) === 2 && $r[1]['oldValue'] === 'cccccccc-0000-4000-8000-000000000009' && $r[1]['newValue'] === null);
$priv->remove($PLAIN_USER, 'cccccccc-0000-4000-8000-000000000009');
check("removing a privilege not held writes nothing", count(rows('privilege', $PLAIN_USER)) === 2);

$users = new Users();
$users->validate($ADMIN_USER, true);
// Since 6.10.0 a verified account's new address is held until its inbox
// confirms it (tests/email-change.php), so the PATCH records the request,
// not a change of email. The mail goes to the test outbox.
SendEmail::$outbox = array();
$users->createAccount('', 'plain.renamed@example.com', '', '', $ADMIN, 'PATCH', $PLAIN_USER, array('email'));
$r = rows('user', $PLAIN_USER);
check("email PATCH (verified account) -> set pendingEmail null -> new, nothing else", !$users->error && count($r) === 1 && $r[0]['field'] === 'pendingEmail' && $r[0]['oldValue'] === null && $r[0]['newValue'] === 'plain.renamed@example.com' && count(SendEmail::$outbox) === 1);
$users = new Users();
$users->validate($ADMIN_USER, true);
$users->createAccount('', '', 'Correct-Horse-Battery-9', '', $ADMIN, 'PATCH', $PLAIN_USER, array('password'));
$r = last('user', $PLAIN_USER);
check("password PATCH -> passwordHash row with no values, note says changed", !$users->error && $r['field'] === 'passwordHash' && $r['oldValue'] === null && $r['newValue'] === null && $r['note'] === 'password changed');
check("the hash itself is nowhere in the log", $admin->query("SELECT COUNT(*) AS n FROM audit_log WHERE oldValue LIKE '$%' OR newValue LIKE '$%'")->fetch_assoc()['n'] === '0');

$keys = new apiKeys();
$keys->add('aaaaaaaa-0000-4000-8000-000000000004');
$r = rows('api_key', $keys->apiKey);
check("key issued -> api_key create row", !$keys->error && count($r) === 1 && $r[0]['action'] === 'create' && $r[0]['snapshot'] === null);
$NEWKEY = $keys->apiKey;

$priv->add('aaaaaaaa-0000-4000-8000-000000000004', 'cccccccc-0000-4000-8000-000000000009', false);
$users = new Users();
$users->validate($ADMIN_USER, true);
$users->delete('aaaaaaaa-0000-4000-8000-000000000004', $ADMIN);
check("account DELETE -> user tombstone noting the email", !$users->error && last('user', 'aaaaaaaa-0000-4000-8000-000000000004')['action'] === 'delete' && last('user', 'aaaaaaaa-0000-4000-8000-000000000004')['note'] === 'keyless@example.com');
check("...a tombstone for its key and its privilege cleared", last('api_key', $NEWKEY)['action'] === 'delete' && last('privilege', 'aaaaaaaa-0000-4000-8000-000000000004')['newValue'] === null && last('privilege', 'aaaaaaaa-0000-4000-8000-000000000004')['note'] === 'cascade: account deleted');

// ----- 6. Sources and the master key -----
actsAs($MASTER, 'aaaaaaaa-0000-4000-8000-000000000003');
$brewer = new Brewer();
$brewer->add('Master Key Brewing', '', '', '', 'aaaaaaaa-0000-4000-8000-000000000003', 'POST', '', array(), array());
$r = last('brewer', $brewer->brewerID);
check("a master-key write is recorded with its key (api_logging skips these)", $r['actorKey'] === $MASTER && $r['actorID'] === 'aaaaaaaa-0000-4000-8000-000000000003');
$MB = $brewer->brewerID;
Audit::setContext(null, null, 'webhook', '54.187.174.169');
Audit::set('api_key', $PLAIN, 'billingEnabled', 0, 1, 'checkout.session.completed');
$r = last('api_key', $PLAIN);
check("webhook rows have no actor and say source webhook", $r['actorID'] === null && $r['actorKey'] === null && $r['source'] === 'webhook' && $r['note'] === 'checkout.session.completed');
$ctx = Audit::context();
check("a context never set under the CLI defaults to source cron", (function(){ $rp = new ReflectionProperty('Audit', 'source'); $rp->setValue(null, null); return Audit::context()['source']; })() === 'cron');

// ----- 7. Reads: history and feed -----
actsAs($ADMIN, $ADMIN_USER);
$h = Audit::history('brewer', $B, null, base64_encode('0'));
check("history lists the deleted brewer's four revisions newest first", is_array($h) && count($h['data']) === 4 && $h['data'][0]['action'] === 'delete' && $h['data'][3]['action'] === 'create' && $h['has_more'] === false);
check("each revision names its actor and the fields that changed", $h['data'][1]['actor']['name'] === 'Plain User' && $h['data'][1]['actor']['api_key_last4'] === '0002' && in_array('cbVerified', $h['data'][1]['changed_fields']) && $h['data'][2]['changed_fields'] == array('description', 'lastModified') && $h['data'][3]['changed_fields'] === array());
check("a tombstone has no changed fields and no snapshot", $h['data'][0]['changed_fields'] === array() && $h['data'][0]['snapshot'] === null && $h['data'][1]['snapshot']['name'] === 'Audit Test Brewing');
$h = Audit::history('brewer', $B, 2, base64_encode('0'));
check("count=2 pages with has_more and a cursor; the diff still reaches past the page", $h['has_more'] === true && count($h['data']) === 2 && $h['data'][1]['changed_fields'] !== array());
$h2 = Audit::history('brewer', $B, 2, $h['next_cursor']);
check("second page -> the two oldest, has_more false", count($h2['data']) === 2 && $h2['has_more'] === false && $h2['data'][1]['action'] === 'create');
check("bad cursor and bad count are refused as false", Audit::history('brewer', $B, 2, 'not-base64!') === false && Audit::history('brewer', $B, 500, base64_encode('0')) === false && Audit::history('brewer', $B, 'x', base64_encode('0')) === false);

$f = Audit::feed(array('entity_type' => 'api_key'), null, base64_encode('0'));
check("feed filters by entity_type and masks the key to its last four", is_array($f) && count($f['data']) > 0 && $f['entity_type'] === 'api_key' && $f['data'][0]['entity_id'] === '…0002' && !isset($f['data'][0]['snapshot']));
$f = Audit::feed(array('actor' => $PLAIN_USER), null, base64_encode('0'));
check("feed filters by actor", count($f['data']) === 1 && $f['data'][0]['entity_id'] === $B);
$f = Audit::feed(array('source' => 'webhook', 'since' => strval(time() - 60)), null, base64_encode('0'));
check("feed filters by source and since", count($f['data']) === 1 && $f['data'][0]['field'] === 'billingEnabled');
check("feed refuses an unknown entity_type, a non-UUID actor and a signed since", Audit::feed(array('entity_type' => 'style'), null, base64_encode('0')) === false && Audit::feed(array('actor' => 'me'), null, base64_encode('0')) === false && Audit::feed(array('since' => '-1'), null, base64_encode('0')) === false);
$f = Audit::feed(array(), 5, base64_encode('0'));
check("feed pages", count($f['data']) === 5 && $f['has_more'] === true && isset($f['next_cursor']));

// ----- 8. Revert -----
$mb = rows('brewer', $MB);
$brewer = new Brewer();
$brewer->add('', 'Renamed by mistake', '', '', $ADMIN_USER, 'PATCH', $MB, array('description'), array());
$mb = rows('brewer', $MB);
check("setup: master-key brewer has create + update", count($mb) === 2);
$brewer = new Brewer();
$brewer->revert($MB, $mb[0]['id'], 'undo the mistaken description', $ADMIN);
$mb = rows('brewer', $MB);
check("brewer revert -> 200 and a revert row carrying revertOf and the note", !$brewer->error && $brewer->responseCode === 200 && count($mb) === 3 && $mb[2]['action'] === 'revert' && intval($mb[2]['revertOf']) === intval($mb[0]['id']) && $mb[2]['note'] === 'undo the mistaken description');
check("the row is back to the first snapshot's content", dbRow('brewer', $MB)['description'] === null && $mb[2]['snapshot']['description'] === null);
$brewer = new Brewer();
$brewer->revert($MB, $mb[0]['id'], null, $ADMIN);
check("reverting to the state already held -> 200 and no new row", !$brewer->error && count(rows('brewer', $MB)) === 3);
$brewer = new Brewer();
$brewer->revert($MB, $mb[0]['id'], null, $PLAIN);
check("revert by a non-admin -> 403", $brewer->error && $brewer->responseCode === 403);
$brewer = new Brewer();
$brewer->revert($MB, 999999, null, $ADMIN);
check("revert to an unknown audit id -> 404", $brewer->error && $brewer->responseCode === 404);
$brewer = new Brewer();
$brewer->revert($MB, rows('beer', $BEER)[0]['id'], null, $ADMIN);
check("revert to another entity's row -> 400", $brewer->error && $brewer->responseCode === 400);
$brewer = new Brewer();
$brewer->revert($B, rows('brewer', $B)[3]['id'], null, $ADMIN);
check("revert to a tombstone -> 400 naming the deletion", $brewer->error && $brewer->responseCode === 400 && str_contains($brewer->errorMsg, 'deletion'));
// restore the deleted brewer from its last snapshot: the UUID comes back
$brewer = new Brewer();
$brewer->revert($B, rows('brewer', $B)[2]['id'], 'restore', $ADMIN);
$rb = rows('brewer', $B);
check("reverting a deleted brewer recreates it under its own id as a revert row", !$brewer->error && $brewer->responseCode === 201 && dbRow('brewer', $B)['name'] === 'Audit Test Brewing' && count($rb) === 5 && $rb[4]['action'] === 'revert' && intval($rb[4]['revertOf']) === intval($rb[2]['id']));
check("the restored row keeps the content, not the old badges (admin revert verifies it)", $rb[4]['snapshot']['description'] === 'A third description.' && $rb[4]['snapshot']['cbVerified'] === 1);
// beer revert through the beer route
$bb = rows('beer', $BEER);
$beer = new Beer();
$beer->revert($BEER, $bb[0]['id'], 'back to 6.5', $ADMIN);
check("beer revert recreates the cascaded-away beer at its first snapshot", !$beer->error && $beer->responseCode === 201 && dbRow('beer', $BEER)['abv'] === '6.5' && last('beer', $BEER)['action'] === 'revert');

// ----- 9. Baseline backfill (cron/backfill-audit.php, included with the scratch constants) -----
$admin->query("INSERT INTO brewer (id,name,cbVerified,lastModified,createdAt) VALUES ('cccccccc-0000-4000-8000-000000000101','Old One',1,1700000100,1600000100),('cccccccc-0000-4000-8000-000000000102','Old Two',0,1700000200,0)");
$admin->query("INSERT INTO beer (id,brewerID,name,style,abv,lastModified,createdAt) VALUES ('eeeeeeee-0000-4000-8000-000000000101','cccccccc-0000-4000-8000-000000000101','Old Beer','IPA',5.5,1700000300,1600000300)");
$before = count(allRows());
$argv = array('backfill-audit.php', 'staging', 'all');
ob_start(); include dirname(__DIR__) . '/cron/backfill-audit.php'; $out = ob_get_clean();
$r1 = rows('brewer', 'cccccccc-0000-4000-8000-000000000101'); $r2 = rows('brewer', 'cccccccc-0000-4000-8000-000000000102'); $r3 = rows('beer', 'eeeeeeee-0000-4000-8000-000000000101');
// four: the three above plus Privilege Brewing, inserted by SQL in section 5
check("backfill writes one baseline row per row without history", count($r1) === 1 && count($r2) === 1 && count($r3) === 1 && count(allRows()) === $before + 4);
check("baseline rows: create / backfill / note baseline / no actor", $r1[0]['action'] === 'create' && $r1[0]['source'] === 'backfill' && $r1[0]['note'] === 'baseline' && $r1[0]['actorID'] === null);
check("changedAt is the row's createdAt, or lastModified when that is 0", intval($r1[0]['changedAt']) === 1600000100 && intval($r2[0]['changedAt']) === 1700000200);
check("baseline snapshot is the normalised row", $r1[0]['snapshot'] == dbRow('brewer', 'cccccccc-0000-4000-8000-000000000101') && $r1[0]['snapshot']['cbVerified'] === 1);
check("entities with history are left alone", count(rows('brewer', $B)) === 5 && str_contains($out, 'already had history'));
$before = count(allRows());
ob_start(); include dirname(__DIR__) . '/cron/backfill-audit.php'; $out = ob_get_clean();
check("a second run writes nothing", count(allRows()) === $before && str_contains($out, '0 baseline rows written'));
// a live edit after the baseline diffs against it (the script left the
// context on source backfill, as index.php never would)
actsAs($ADMIN, $ADMIN_USER);
$brewer = new Brewer();
$brewer->add('', 'Now described.', '', '', $ADMIN_USER, 'PATCH', 'cccccccc-0000-4000-8000-000000000101', array('description'), array());
$h = Audit::history('brewer', 'cccccccc-0000-4000-8000-000000000101', null, base64_encode('0'));
check("the first live edit after the baseline shows its diff against it", count($h['data']) === 2 && $h['data'][0]['changed_fields'] == array('description', 'lastModified') && $h['data'][0]['source'] === 'api' && $h['data'][1]['source'] === 'backfill');

// ----- 11. /activity reads audit_log, with the Newman suite's entities filtered out -----
actsAs($ADMIN, $ADMIN_USER);
$suite = new Brewer();
$suite->add('[Postman API Test] Suite Brewing', 'Created by the test suite.', '', '', $ADMIN_USER, 'POST', '', array(), array('country_code' => 'US'));
$SUITE = $suite->brewerID;
$suiteBeer = new Beer();
$suiteBeer->add($SUITE, 'Suite Lager', 'IPA', '', '', '', '', '', '4.5', '', $ADMIN_USER, 'POST', '', array());   // not prefixed itself: excluded through its brewer
$SUITE_BEER = $suiteBeer->beerID;
$suiteBeer = new Beer();
$suiteBeer->delete($SUITE_BEER, $ADMIN_USER);   // its tombstone shares the id, so it goes too
check("suite fixtures written", !$suite->error && !$suiteBeer->error && count(rows('beer', $SUITE_BEER)) === 2);

$activity = new Activity();
$activity->api('GET', '', '', $PLAIN);
check("non-admin GET /activity -> 401", $activity->responseCode === 401 && $activity->json['error'] === true);
$activity = new Activity();
$activity->api('GET', '', '', $ADMIN);
$j = $activity->json;
check("admin GET /activity -> 200, sourced from audit_log", $activity->responseCode === 200 && ($j['source'] ?? '') === 'audit_log' && $j['period_months'] === 3);
check("the suite's brewer and its beer are the two excluded entities", $j['test_entities_excluded'] === 2);

// Expected counts straight from the table: catalog rows, no settings, no
// baseline, none of the suite's
$expected = array();
foreach(allRows() as $row){
    if(!in_array($row['entityType'], array('brewer', 'beer', 'location'), true) || $row['action'] === 'set' || $row['source'] === 'backfill'){ continue; }
    if(in_array($row['entityID'], array($SUITE, $SUITE_BEER), true)){ continue; }
    $expected[$row['entityType']][$row['action']] = ($expected[$row['entityType']][$row['action']] ?? 0) + 1;
}
$summary = array();
foreach($j['write_activity']['summary'] as $line){ $summary[$line['resource']] = $line; }
foreach(array('brewer', 'beer', 'location') as $t){
    $e = $expected[$t] ?? array();
    $got = $summary[$t] ?? array('created' => 0, 'updated' => 0, 'deleted' => 0);
    check("summary matches the table for $t (created " . ($e['create'] ?? 0) . ", updated " . (($e['update'] ?? 0) + ($e['revert'] ?? 0)) . ", deleted " . ($e['delete'] ?? 0) . ")", $got['created'] === ($e['create'] ?? 0) && $got['updated'] === (($e['update'] ?? 0) + ($e['revert'] ?? 0)) && $got['deleted'] === ($e['delete'] ?? 0));
}
$names = array_column($j['write_activity']['top_contributors'], 'name');
check("top contributors name the admin and the plain user, never the suite's rows alone", in_array('Admin', $names, true) && in_array('Plain User', $names, true));
$recent = $j['write_activity']['recent'];
$recentIDs = array_column($recent, 'entity_id');
check("recent: newest first, the suite's entities absent", count($recent) >= 2 && $recent[0]['audit_id'] > $recent[1]['audit_id'] && !in_array($SUITE, $recentIDs, true) && !in_array($SUITE_BEER, $recentIDs, true));
$tomb = null;
foreach($recent as $entry){ if($entry['action'] === 'delete' && $entry['entity_type'] === 'beer' && $entry['entity_id'] === $BEER2){ $tomb = $entry; break; } }
check("a beer tombstone still names the beer (from its last snapshot) and its brewer (from the brewer table)", $tomb !== null && $tomb['resource_name'] === 'Audit Pale' && ($tomb['brewer_id'] ?? '') === $B && ($tomb['brewer_name'] ?? '') === dbRow('brewer', $B)['name']);
$rev = null;
foreach($recent as $entry){ if($entry['action'] === 'revert'){ $rev = $entry; break; } }
check("a restore shows as action revert with revert_of and the admin's name", $rev !== null && $rev['revert_of'] > 0 && $rev['user_name'] === 'Admin');
check("read traffic still comes from api_logging (empty here)", isset($j['read_traffic']['total']) && $j['read_traffic']['total'] === 0);

// ----- 10. Structural -----
$create = $admin->query("SHOW CREATE TABLE audit_log")->fetch_assoc()['Create Table'];
check("canonical schema: audit_log has no foreign key", stripos($create, 'FOREIGN KEY') === false);
check("canonical schema: the three indexes", str_contains($create, 'idx_audit_entity') && str_contains($create, 'idx_audit_actor') && str_contains($create, 'idx_audit_time'));
$prune = file_get_contents(dirname(__DIR__) . '/cron/prune-api-logging.php') . file_get_contents(ROOT . '/classes/Usage.class.php');
check("nothing deletes from audit_log", !preg_match('/DELETE\s+FROM\s+audit_log/i', $prune) && !preg_match('/DELETE\s+FROM\s+audit_log/i', file_get_contents(ROOT . '/classes/Audit.class.php')));

if(!getenv('CB_TEST_KEEP')){ $admin->query("DROP DATABASE " . DB_NAME); }   // CB_TEST_KEEP=1 leaves the scratch database for inspection
echo $fails === 0 ? "\nall checks passed\n" : "\n$fails check(s) FAILED\n";
exit($fails === 0 ? 0 : 1);
