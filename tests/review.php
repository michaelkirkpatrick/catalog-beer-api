<?php
/*--
Regression test for Review.class.php — the brewer review loop's queue and
record. Needs a LOCAL MySQL (it creates and drops a scratch database named
cb_review_test); no network, no secrets file, no Apache.

    php tests/review.php [path/to/catalog-beer-schema.sql]

The schema path defaults to ../catalog-beer-mysql/catalog-beer-schema.sql.
Connection defaults to root@127.0.0.1 with no password (Homebrew MySQL);
override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.

What it proves: claim() hands out each brewer once and in the documented
order, expired claims are free again, the admin gate holds, POST validation
rejects the shapes it should, a posted review sets reviewedAt / clears the
claim / refreshes the URL-health columns on url_verdict ok, the decisions
list and PATCH round-trip, an undecided row's notes/question can be amended
(and a decided row's cannot), and a re-claim carries the decision back.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__));
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_review_test');
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

$schemaPath = $argv[1] ?? ROOT . '/../catalog-beer-mysql/catalog-beer-schema.sql';
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
 ('aaaaaaaa-0000-4000-8000-000000000001','reviewer@example.com','x','Reviewer',1,1),
 ('aaaaaaaa-0000-4000-8000-000000000002','plain@example.com','x','Plain User',1,0);
INSERT INTO api_keys (id,userID) VALUES
 ('bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001'),
 ('bbbbbbbb-0000-4000-8000-000000000002','aaaaaaaa-0000-4000-8000-000000000002');
INSERT INTO brewer (id,name,url,domainName,lastModified,createdAt,urlStatus) VALUES
 ('cccccccc-0000-4000-8000-000000000001','Alpha Brewing','https://alpha.example','alpha.example',100,100,'ok'),
 ('cccccccc-0000-4000-8000-000000000002','Beta Brewing','https://beta.example','beta.example',200,200,'gone'),
 ('cccccccc-0000-4000-8000-000000000003','Gamma Brewing',NULL,NULL,300,300,'unverified');
SQL;
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Scenario -----
$ADMIN = 'bbbbbbbb-0000-4000-8000-000000000001'; $PLAIN = 'bbbbbbbb-0000-4000-8000-000000000002';
$ALPHA = 'cccccccc-0000-4000-8000-000000000001'; $BETA = 'cccccccc-0000-4000-8000-000000000002';
function call($method, $function, $id, $key, $data = null, $get = []) {
    $_GET = $get; $r = new Review(); $r->api($method, $function, $id, $key, $get['count'] ?? 500, $get['cursor'] ?? base64_encode('0'), $data ?? new stdClass());
    return [$r->responseCode, $r->json, $r->responseHeader];
}
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

// 1. claim: two brewers with URLs, Beta (gone) first
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 10]);
check("claim returns 200", $code === 200);
check("claim returns 2 rows (Gamma has no url)", count($j['data']) === 2);
check("non-ok cron verdict is first", $j['data'][0]['name'] === 'Beta Brewing' && $j['data'][0]['url_status'] === 'gone');
check("row carries url health + null last_review", $j['data'][0]['url_fail_count'] === 0 && $j['data'][0]['last_review'] === null);
check("claim_expires_at ~ 4h", $j['claim_expires_at'] - time() > 14000);

// 2. second claim gets nothing
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 10]);
check("second claim is empty", $code === 200 && count($j['data']) === 0);

// 3. non-admin refused
[$code, $j] = call('POST', 'claim', '', $PLAIN, (object)['count' => 10]);
check("non-admin key gets 401", $code === 401 && $j['error'] === true);
[$code, $j] = call('GET', '', '', 'not-a-key');
check("unknown key gets 404", $code === 404);

// 4. validation failure
[$code, $j] = call('POST', '', $BETA, $ADMIN, (object)['url_verdict' => 'ok']);
check("missing outcome -> 400 with validation", $code === 400 && isset($j['validation']['outcome']));
[$code, $j] = call('POST', '', $BETA, $ADMIN, (object)['outcome' => 'updated', 'needs_decision' => true]);
check("needs_decision without question -> 400", $code === 400 && isset($j['validation']['question']));
[$code, $j] = call('POST', '', $BETA, $ADMIN, (object)['outcome' => 'updated', 'needs_decision' => true, 'question' => str_repeat('x', 717)]);
check("over-long question -> 400 that says too long, not missing", $code === 400 && str_contains($j['validation']['question'] ?? '', '500 characters or fewer') && str_contains($j['validation']['question'], '717 sent'));
[$code, $j] = call('POST', '', $BETA, $ADMIN, (object)['outcome' => 'updated', 'beers_added' => -1]);
check("negative counter -> 400", $code === 400 && isset($j['validation']['beers_added']));
// The changes cap: a 490-beer first review is ~2,015 entries and must fit; 4,001 must not
$entry = fn($i) => (object)['entity' => 'beer', 'id' => sprintf('dddddddd-0000-4000-8000-%012d', $i), 'field' => 'abv', 'before' => null, 'after' => 6.5];
[$code, $j] = call('POST', '', $BETA, $ADMIN, (object)['outcome' => 'updated', 'changes' => array_map($entry, range(1, 4001))]);
check("4,001 changes -> 400 naming the cap", $code === 400 && str_contains($j['validation']['changes'] ?? '', '4,000'));
[$code, $j] = call('POST', '', 'cccccccc-0000-4000-8000-0000000000ff', $ADMIN, (object)['outcome' => 'updated']);
check("unknown brewer -> 404", $code === 404);

// 5. post a real review with url ok + a question
$body = (object)['outcome' => 'updated', 'url_verdict' => 'ok', 'brief_version' => 'abc1234', 'brewer_changed' => 'description,short_description',
    'beers_added' => 4, 'beers_updated' => 1, 'sources' => ['https://beta.example/', 'https://beta.example/beers'],
    'changes' => array_merge([(object)['entity' => 'brewer', 'id' => $BETA, 'field' => 'description', 'before' => null, 'after' => 'Beta brews lagers in Ohio.']], array_map($entry, range(1, 2014))),
    'notes' => "Site is live again.\nFour lagers added.", 'needs_decision' => true, 'question' => 'Is the Beta Taproom in Dayton a location or a franchise?'];
[$code, $j, $hdr] = call('POST', '', $BETA, $ADMIN, $body);
check("post review -> 201 with Location", $code === 201 && strpos($hdr, 'Location: https://staging.catalog.beer/review/') === 0);
check("review carries brewer_name", ($j['brewer_name'] ?? null) === 'Beta Brewing');
check("review object round-trips, 2,015 changes stored", $j['object'] === 'review' && $j['beers_added'] === 4 && count($j['sources']) === 2 && $j['changes'][0]->field === 'description' && count($j['changes']) === 2015 && $j['needs_decision'] === true && $j['reviewer'] === 'aaaaaaaa-0000-4000-8000-000000000001');
$reviewID = $j['id'];
$db = new Database(); $row = $db->query("SELECT reviewedAt, claimedBy, claimedAt, urlStatus, urlFailCount, urlLastOkAt FROM brewer WHERE id=?", [$BETA])->fetch_assoc(); $db->close();
check("brewer.reviewedAt set, claim cleared", !is_null($row['reviewedAt']) && is_null($row['claimedBy']) && is_null($row['claimedAt']));
check("url ok refreshed health columns (gone -> ok)", $row['urlStatus'] === 'ok' && intval($row['urlFailCount']) === 0 && !is_null($row['urlLastOkAt']));

// 5b. amend the open row: notes and question are replaceable while undecided
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)['notes' => 'Site is live again. Four lagers added; the fifth is a collab.']);
check("amend notes on open row -> 200, readback", $code === 200 && $j['notes'] === 'Site is live again. Four lagers added; the fifth is a collab.' && $j['question'] === 'Is the Beta Taproom in Dayton a location or a franchise?' && $j['decision'] === null);
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)['question' => 'Is the Beta Taproom in Dayton a second location, or a franchise?']);
check("amend question on open row -> 200, flag still set", $code === 200 && $j['question'] === 'Is the Beta Taproom in Dayton a second location, or a franchise?' && $j['needs_decision'] === true);
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)['question' => str_repeat('y', 600)]);
check("amend question to 600 chars -> 400 naming the cap", $code === 400 && str_contains($j['validation']['question'] ?? '', '500 characters or fewer') && str_contains($j['validation']['question'], '600 sent'));
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)['question' => '']);
check("clearing the question on a needs_decision row -> 400", $code === 400 && isset($j['validation']['question']));
[$code, $j] = call('GET', '', $reviewID, $ADMIN);
check("rejected amendments left the row untouched", $j['question'] === 'Is the Beta Taproom in Dayton a second location, or a franchise?');

// 6. decisions list, then answer
[$code, $j] = call('GET', '', '', $ADMIN, null, ['needs_decision' => '1']);
check("needs_decision list has the row", $code === 200 && count($j['data']) === 1 && $j['data'][0]['id'] === $reviewID && $j['needs_decision'] === true);
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)['decision' => 'Franchise. Not a location.']);
check("patch decision -> 200, flag cleared", $code === 200 && $j['needs_decision'] === false && $j['decision'] === 'Franchise. Not a location.' && $j['decided_by'] === 'aaaaaaaa-0000-4000-8000-000000000001');
[$code, $j] = call('GET', '', '', $ADMIN, null, ['needs_decision' => '1']);
check("needs_decision list now empty", count($j['data']) === 0);
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)[]);
check("patch without decision -> 400", $code === 400);
// 6b. once decided, notes and question are fixed
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)['notes' => 'too late']);
check("amend notes after decision -> 409 naming the field", $code === 409 && isset($j['validation']['notes']) && !isset($j['validation']['question']));
[$code, $j] = call('PATCH', '', $reviewID, $ADMIN, (object)['question' => 'too late']);
check("amend question after decision -> 409", $code === 409 && isset($j['validation']['question']));
[$code, $j] = call('GET', '', $reviewID, $ADMIN);
check("decided row untouched by rejected amendments", $j['notes'] === 'Site is live again. Four lagers added; the fifth is a collab.' && $j['decision'] === 'Franchise. Not a location.');

// 7. history and single get
[$code, $j] = call('GET', 'brewer', $BETA, $ADMIN);
check("brewer history lists 1 review", $code === 200 && count($j['data']) === 1 && $j['url'] === "/brewer/$BETA/review");
[$code, $j] = call('GET', '', $reviewID, $ADMIN);
check("get one review", $code === 200 && $j['id'] === $reviewID);
[$code, $j] = call('GET', '', 'cccccccc-0000-4000-8000-0000000000ff', $ADMIN);
check("get unknown review -> 404", $code === 404);

// 8. expire Alpha's claim, re-claim: Beta (reviewed) sorts after Alpha (never), and Beta carries last_review + decision
$db = new Database(); $db->query("UPDATE brewer SET claimedAt = claimedAt - 20000 WHERE id=?", [$ALPHA]); $db->close();
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 10]);
$names = array_map(fn($r) => $r['name'], $j['data']);
check("re-claim: expired claim is free; the decided brewer sorts first", $names === ['Beta Brewing', 'Alpha Brewing']);
check("claim reports how many carry a decision", ($j['decided'] ?? null) === 1);
// a decision is claimed regardless of count: count=1 still returns the decided brewer PLUS one more
$db = new Database(); $db->query("UPDATE brewer SET claimedAt = NULL WHERE id IN (?, ?)", [$ALPHA, $BETA]); $db->close();
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 1]);
$names = array_map(fn($r) => $r['name'], $j['data']);
check("count=1 with one decision waiting returns two rows, decided first", $names === ['Beta Brewing', 'Alpha Brewing'] && $j['decided'] === 1);
check("re-claim carries last_review with decision", $j['data'][0]['last_review']['decision'] === 'Franchise. Not a location.' && $j['data'][0]['reviewed_at'] > 0);

// 8b. once the decision is acted on (a newer review posted), the brewer drops back behind the never-reviewed row.
// The whole test runs inside one second, so backdate the first review to make "latest" unambiguous.
$db = new Database(); $db->query("UPDATE brewer_review SET reviewedAt = reviewedAt - 60 WHERE id=?", [$reviewID]); $db->close();
[$code, $j] = call('POST', '', $BETA, $ADMIN, (object)['outcome' => 'unchanged', 'notes' => 'acted on the decision']);
check("post after decision -> 201", $code === 201);
$secondReviewID = $j['id'];
// 8c. a row posted without needs_decision: question is refused, notes can be amended or cleared
[$code, $j] = call('PATCH', '', $secondReviewID, $ADMIN, (object)['question' => 'Should this be a question?']);
check("question on a needs_decision:false row -> 400", $code === 400 && isset($j['validation']['question']));
[$code, $j] = call('PATCH', '', $secondReviewID, $ADMIN, (object)['notes' => null]);
check("notes: null clears notes on an open row", $code === 200 && $j['notes'] === null && $j['question'] === null);
[$code, $j] = call('PATCH', '', $secondReviewID, $ADMIN, (object)['notes' => "Acted on the decision.\nNothing else changed."]);
check("multi-line notes amendment round-trips", $code === 200 && $j['notes'] === "Acted on the decision.\nNothing else changed.");
$db = new Database(); $db->query("UPDATE brewer SET claimedAt = NULL WHERE id IN (?, ?)", [$ALPHA, $BETA]); $db->close();
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 10]);
$names = array_map(fn($r) => $r['name'], $j['data']);
check("after acting, never-reviewed row leads again", $names === ['Alpha Brewing', 'Beta Brewing']);

// 9. method + path guards
[$code, $j, $hdr] = call('DELETE', '', $reviewID, $ADMIN);
check("DELETE -> 405 with Allow", $code === 405 && $hdr === 'Allow: GET, POST, PATCH');
[$code, $j] = call('GET', 'bogus', '', $ADMIN);
check("bad function -> 404", $code === 404);

// 10. pagination
[$code, $j] = call('GET', '', '', $ADMIN, null, ['count' => 1]);
check("count=1 pages: one row, has_more, next_cursor", $j['has_more'] === true && count($j['data']) === 1 && !empty($j['next_cursor']));
[$code, $j2] = call('GET', '', '', $ADMIN, null, ['count' => 1, 'cursor' => $j['next_cursor']]);
check("second page is a different row", count($j2['data']) === 1 && $j2['data'][0]['id'] !== $j['data'][0]['id']);

$db = new Database(); $n = $db->query("SELECT COUNT(*) c FROM error_log")->fetch_assoc()['c']; $db->close();
echo "error_log rows written by the expected-failure cases: $n\n";
echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";

// ----- Tear down -----
$admin->query("DROP DATABASE " . DB_NAME);
$admin->close();
echo "scratch database dropped\n";
exit($fails === 0 ? 0 : 1);
