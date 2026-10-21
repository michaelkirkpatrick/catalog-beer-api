<?php
/*--
Regression test for ?modified_since on GET /brewer, GET /beer and GET /location
-- the incremental-sync filter. Needs a LOCAL MySQL (it creates and drops a
scratch database named cb_modified_since_test); no network, no secrets file,
no Apache.

    php tests/modified-since.php [path/to/catalog-beer-schema.sql]

The schema path defaults to ../catalog-beer-mysql/catalog-beer-schema.sql.
Connection defaults to root@127.0.0.1 with no password (Homebrew MySQL);
override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.

What it proves: the filter is inclusive (a row whose last_modified equals the
value is returned); a value past every row returns an empty page with
has_more false (the nextCursor() fallback must count the FILTERED set, or an
empty page claims a next page); the page count and the cursor range follow
the filtered set, so count= pages a filtered list to completion and a cursor
past it is the usual 400; the response echoes modified_since only when the
filter is on; every malformed value -- words, a sign, a decimal, the empty
string, an array, more than ten digits -- is a 400 that names modified_since,
never the whole catalog; the master-key enriched shapes filter too; and each
of the three tables carries its lastModified index in the canonical schema.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__) . '/public_html');
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_modified_since_test');
define('MASTER_API_KEYS', serialize(array('bbbbbbbb-0000-4000-8000-000000000002')));
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
 ('aaaaaaaa-0000-4000-8000-000000000001','reader@example.com','x','Reader',1,0),
 ('aaaaaaaa-0000-4000-8000-000000000002','site@example.com','x','Site (master key)',1,1);
INSERT INTO api_keys (id,userID) VALUES
 ('bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001'),
 ('bbbbbbbb-0000-4000-8000-000000000002','aaaaaaaa-0000-4000-8000-000000000002');
-- Three of each, modified at 100, 200 and 300. Names sort in the same order
-- as the times so page boundaries are predictable.
INSERT INTO brewer (id,name,countryCode,lastModified,createdAt) VALUES
 ('cccccccc-0000-4000-8000-000000000100','Alpha Brewing','US',100,100),
 ('cccccccc-0000-4000-8000-000000000200','Bravo Brewing','US',200,200),
 ('cccccccc-0000-4000-8000-000000000300','Charlie Brewing','US',300,300);
INSERT INTO beer (id,brewerID,name,style,abv,lastModified,createdAt) VALUES
 ('dddddddd-0000-4000-8000-000000000100','cccccccc-0000-4000-8000-000000000100','Alpha Ale','Pale Ale',5.0,100,100),
 ('dddddddd-0000-4000-8000-000000000200','cccccccc-0000-4000-8000-000000000200','Bravo Bock','Bock',6.0,200,200),
 ('dddddddd-0000-4000-8000-000000000300','cccccccc-0000-4000-8000-000000000300','Charlie Cream','Cream Ale',4.5,300,300);
INSERT INTO location (id,brewerID,name,countryCode,lastModified,createdAt) VALUES
 ('eeeeeeee-0000-4000-8000-000000000100','cccccccc-0000-4000-8000-000000000100','Alpha Taproom','US',100,100),
 ('eeeeeeee-0000-4000-8000-000000000200','cccccccc-0000-4000-8000-000000000200','Bravo Taproom','US',200,200),
 ('eeeeeeee-0000-4000-8000-000000000300','cccccccc-0000-4000-8000-000000000300','Charlie Taproom','US',300,300);
SQL;
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Harness -----
$READER = 'bbbbbbbb-0000-4000-8000-000000000001'; $MASTER = 'bbbbbbbb-0000-4000-8000-000000000002';
$CLASSES = ['brewer' => 'Brewer', 'beer' => 'Beer', 'location' => 'Location'];
// GET /{endpoint} with the query string index.php would see. index.php
// nulls $count for /location (its geo lookups have their own defaults) and
// Beer::api() re-reads cursor/count from $_GET itself, so the harness sets
// $_GET exactly as the rewrite rules would and passes what index.php passes.
function listCall($endpoint, $get, $key) {
    global $CLASSES;
    $_GET = $get;
    $count = $get['count'] ?? 500;
    if($endpoint === 'location' && !isset($get['count'])){ $count = null; }
    $cursor = $get['cursor'] ?? base64_encode('0');
    $obj = new $CLASSES[$endpoint]();
    $obj->api('GET', '', '', $key, $count, $cursor, new stdClass());
    return [$obj->responseCode, $obj->json];
}
function ids($json) { return array_map(fn($r) => substr($r['id'], -3), $json['data'] ?? []); }
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

foreach(array_keys($CLASSES) as $ep){
    echo "-- /$ep\n";

    // 1. no filter: everything, and no modified_since key in the response
    [$code, $j] = listCall($ep, [], $READER);
    check("[$ep] no filter -> 200, three rows", $code === 200 && ids($j) === ['100', '200', '300']);
    check("[$ep] no filter -> no modified_since key, has_more false", !array_key_exists('modified_since', $j) && $j['has_more'] === false);
    check("[$ep] rows are id/name/last_modified", array_keys($j['data'][0]) === ['id', 'name', 'last_modified'] && $j['data'][1]['last_modified'] === 200);

    // 2. inclusive
    [$code, $j] = listCall($ep, ['modified_since' => '200'], $READER);
    check("[$ep] modified_since=200 -> the 200 and 300 rows (inclusive)", $code === 200 && ids($j) === ['200', '300']);
    check("[$ep] response echoes modified_since as an int", ($j['modified_since'] ?? null) === 200 && $j['url'] === "/$ep");
    check("[$ep] has_more false on the last page", $j['has_more'] === false && !isset($j['next_cursor']));

    // 3. zero is the same as no filter
    [$code, $j] = listCall($ep, ['modified_since' => '0'], $READER);
    check("[$ep] modified_since=0 -> everything", $code === 200 && count($j['data']) === 3 && $j['modified_since'] === 0);

    // 4. past every row: empty page, has_more false (the nextCursor fallback)
    [$code, $j] = listCall($ep, ['modified_since' => '301'], $READER);
    check("[$ep] modified_since=301 -> 200 with an empty page", $code === 200 && $j['data'] === [] && $j['modified_since'] === 301);
    check("[$ep] ... and has_more false, no next_cursor", $j['has_more'] === false && !isset($j['next_cursor']));

    // 5. paging follows the filtered set
    $seen = []; $pages = 0; $cursor = null;
    do {
        $get = ['modified_since' => '200', 'count' => '1'];
        if($cursor !== null){ $get['cursor'] = $cursor; }
        [$code, $j] = listCall($ep, $get, $READER);
        if($code !== 200){ break; }
        $seen = array_merge($seen, ids($j));
        $cursor = $j['has_more'] ? $j['next_cursor'] : null;
        $pages++;
    } while($cursor !== null && $pages < 10);
    check("[$ep] count=1 pages the filtered list to completion", $code === 200 && $seen === ['200', '300'] && $pages <= 3);
    [$code, $j] = listCall($ep, ['modified_since' => '200', 'count' => '1'], $READER);
    check("[$ep] first filtered page: one row, has_more true, next_cursor is offset 1", count($j['data']) === 1 && $j['has_more'] === true && ($j['next_cursor'] ?? '') === base64_encode('1'));
    [$code, $j] = listCall($ep, ['modified_since' => '200', 'cursor' => base64_encode('5')], $READER);
    check("[$ep] a cursor past the filtered count is a 400", $code === 400 && $j['error'] === true);
    [$code, $j] = listCall($ep, ['cursor' => base64_encode('3')], $READER);
    check("[$ep] (control) the same offset without the filter is inside the whole list", $code === 200);

    // 6. malformed values are 400s that name the parameter
    foreach(['yesterday', '-1', '+1', '1.5', '', ' 200', '1e9', '12345678901', ['1']] as $bad){
        [$code, $j] = listCall($ep, ['modified_since' => $bad], $READER);
        $label = is_array($bad) ? 'array' : "'" . $bad . "'";
        check("[$ep] modified_since=$label -> 400 naming modified_since", $code === 400 && $j['error'] === true && strpos($j['error_msg'], 'modified_since') !== false && !isset($j['data']));
    }
}

// 7. the master-key enriched shapes filter too (brewer: beer_count; beer: style/srm)
[$code, $j] = listCall('brewer', ['enriched' => '1', 'modified_since' => '300'], $MASTER);
check("[brewer] enriched + modified_since -> one row with beer_count", $code === 200 && ids($j) === ['300'] && $j['data'][0]['beer_count'] === 1);
[$code, $j] = listCall('beer', ['enriched' => '1', 'modified_since' => '300'], $MASTER);
check("[beer] enriched + modified_since -> one row with style", $code === 200 && ids($j) === ['300'] && array_key_exists('srm', $j['data'][0]));

// 8. a write moves a row into the window
$admin->query("UPDATE brewer SET lastModified=400 WHERE id='cccccccc-0000-4000-8000-000000000100'");
[$code, $j] = listCall('brewer', ['modified_since' => '350'], $READER);
check("[brewer] a bumped lastModified brings the row into modified_since=350", $code === 200 && ids($j) === ['100']);

// 9. structural: the index the filter relies on is in the canonical schema
foreach(['brewer', 'beer', 'location'] as $t){
    $r = $admin->query("SHOW INDEX FROM `$t` WHERE Column_name = 'lastModified' AND Seq_in_index = 1");
    check("[$t] schema carries an index on lastModified", $r->num_rows === 1);
}

// ----- Done -----
$admin->query("DROP DATABASE " . DB_NAME);
echo ($fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n");
exit($fails === 0 ? 0 : 1);
