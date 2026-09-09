<?php
/*--
Regression test for BrewerLead.class.php — the review loop's lead queue.
Needs a LOCAL MySQL (it creates and drops a scratch database named
cb_lead_test); no network, no secrets file, no Apache.

    php tests/brewer-lead.php [path/to/catalog-beer-schema.sql]

The schema path defaults to ../catalog-beer-mysql/catalog-beer-schema.sql.
Connection defaults to root@127.0.0.1 with no password (Homebrew MySQL);
override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.

What it proves: the admin gate holds; POST validation rejects the shapes it
should; a host that is already a brewer's is refused with its id; dedup by
host and by name+state returns the existing row in every status and appends
the new page to sources without merging anything else; claim hands out each
lead once, oldest first, never a row with an open question, decided rows
first regardless of count, and an expired claim is free again; resolve, defer,
ask and decide enforce their field and state rules; a closed row needs
reopen; and the list filters translate the wire vocabulary.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__));
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_lead_test');
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
 ('aaaaaaaa-0000-4000-8000-000000000002','plain@example.com','x','Plain User',1,0),
 ('aaaaaaaa-0000-4000-8000-000000000003','michael@example.com','x','Michael',1,1);
INSERT INTO api_keys (id,userID) VALUES
 ('bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001'),
 ('bbbbbbbb-0000-4000-8000-000000000002','aaaaaaaa-0000-4000-8000-000000000002'),
 ('bbbbbbbb-0000-4000-8000-000000000003','aaaaaaaa-0000-4000-8000-000000000003');
INSERT INTO subdivisions (sub_code, sub_name) VALUES ('OR','Oregon'),('WA','Washington'),('CA','California');
INSERT INTO brewer (id,name,url,domainName,lastModified,createdAt,urlStatus) VALUES
 ('cccccccc-0000-4000-8000-000000000001','Alpha Brewing','https://alpha.example','alpha.example',100,100,'ok');
SQL;
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Scenario -----
$ADMIN = 'bbbbbbbb-0000-4000-8000-000000000001'; $PLAIN = 'bbbbbbbb-0000-4000-8000-000000000002'; $HUMAN = 'bbbbbbbb-0000-4000-8000-000000000003';
$REVIEWER = 'aaaaaaaa-0000-4000-8000-000000000001'; $MICHAEL = 'aaaaaaaa-0000-4000-8000-000000000003';
$ALPHA = 'cccccccc-0000-4000-8000-000000000001';
function call($method, $function, $id, $key, $data = null, $get = []) {
    $_GET = $get; $l = new BrewerLead(); $l->api($method, $function, $id, $key, $get['count'] ?? 500, $get['cursor'] ?? base64_encode('0'), $data ?? new stdClass());
    return [$l->responseCode, $l->json, $l->responseHeader];
}
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
function lead($name, $extra = []) { return (object)array_merge(['name' => $name, 'source_url' => 'https://news.example/' . preg_replace('/\W+/', '-', strtolower($name))], $extra); }
$fails = 0;

// 1. the gate
[$code, $j] = call('POST', '', '', $PLAIN, lead('Bar Brewing', ['url' => 'https://barbrewing.example']));
check("non-admin key gets 401", $code === 401 && $j['error'] === true);
[$code, $j] = call('GET', '', '', 'not-a-key');
check("unknown key gets 404", $code === 404);

// 2. POST validation
[$code, $j] = call('POST', '', '', $ADMIN, (object)['source_url' => 'https://news.example/a', 'url' => 'https://x.example']);
check("missing name -> 400", $code === 400 && isset($j['validation']['name']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['name' => 'Bar Brewing', 'url' => 'https://x.example']);
check("missing source_url -> 400", $code === 400 && isset($j['validation']['source_url']));
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewing'));
check("bare name -> 400 naming url, city, sub_code", $code === 400 && isset($j['validation']['url']) && isset($j['validation']['city']) && isset($j['validation']['sub_code']));
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewing', ['city' => 'Bend']));
check("city without sub_code -> 400", $code === 400 && isset($j['validation']['sub_code']));
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewing', ['city' => 'Bend', 'sub_code' => 'ZZ']));
check("unknown sub_code -> 400", $code === 400 && isset($j['validation']['sub_code']) && !isset($j['validation']['url']));
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewing', ['url' => 'not a url at all']));
check("malformed url -> 400", $code === 400 && isset($j['validation']['url']));
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewing', ['url' => 'https://x.example', 'source_url' => 'nope']));
check("malformed source_url -> 400", $code === 400 && isset($j['validation']['source_url']));
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewing', ['url' => 'https://x.example', 'note' => str_repeat('n', 2001)]));
check("note over 2000 -> 400", $code === 400 && isset($j['validation']['note']));
[$code, $j] = call('POST', '', '', $ADMIN, lead("Bar\tBrewing", ['url' => 'https://x.example']));
check("tab in name -> 400 (TextInput)", $code === 400 && isset($j['validation']['name']));

// 3. already a brewer
[$code, $j] = call('POST', '', '', $ADMIN, lead('Alpha Brewing Co', ['url' => 'http://www.alpha.example/about']));
check("host that is a brewer's domainName -> 409 with brewer_id", $code === 409 && $j['brewer_id'] === $ALPHA && $j['brewer_name'] === 'Alpha Brewing');
$db = new Database(); $n = $db->query("SELECT COUNT(*) c FROM brewer_lead")->fetch_assoc()['c']; $db->close();
check("nothing was written", intval($n) === 0);

// 4. a real lead
$body = lead('The Bar Brewing Co.', ['url' => 'BarBrewing.example', 'city' => 'Bend', 'sub_code' => 'or', 'note' => "Took over Foo's space at 412 Main St.\nOpening March 2026."]);
[$code, $j, $hdr] = call('POST', '', '', $ADMIN, $body);
check("post lead -> 201 with Location", $code === 201 && strpos($hdr, 'Location: https://staging.catalog.beer/brewer-lead/') === 0);
check("lead object round-trips, url verbatim, sub_code uppercased", $j['object'] === 'brewer_lead' && $j['name'] === 'The Bar Brewing Co.' && $j['url'] === 'BarBrewing.example' && $j['sub_code'] === 'OR' && $j['city'] === 'Bend' && $j['status'] === 'queued' && $j['resolution'] === null && $j['created_by'] === $REVIEWER && $j['brewer_id'] === null);
check("sources starts as [source_url]", $j['sources'] === [$body->source_url]);
check("multi-line note kept", str_contains($j['note'], "\n"));
$bar = $j['id'];
$db = new Database(); $row = $db->query("SELECT nameKey, urlHost FROM brewer_lead WHERE id=?", [$bar])->fetch_assoc(); $db->close();
check("dedup keys derived: nameKey 'bar', urlHost 'barbrewing.example'", $row['nameKey'] === 'bar' && $row['urlHost'] === 'barbrewing.example');

// 5. dedup by host: different name, www + https + path, second page joins sources, nothing merged
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewery', ['url' => 'https://www.barbrewing.example/beers', 'city' => 'Redmond', 'sub_code' => 'OR', 'source_url' => 'https://other.example/story']));
check("same host -> 200 with the existing row", $code === 200 && $j['id'] === $bar);
check("row not merged: name, city, url untouched", $j['name'] === 'The Bar Brewing Co.' && $j['city'] === 'Bend' && $j['url'] === 'BarBrewing.example');
check("second page appended to sources", $j['sources'] === [$body->source_url, 'https://other.example/story']);
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewery', ['url' => 'https://www.barbrewing.example/beers', 'source_url' => 'https://other.example/story']));
check("same page again is not appended twice", count($j['sources']) === 2);

// 6. dedup by name + state: no url, "Bar Brewery" in OR hits; in WA does not
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewery', ['city' => 'Bend', 'sub_code' => 'OR']));
check("name+state -> 200 with the existing row", $code === 200 && $j['id'] === $bar);
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewery', ['city' => 'Spokane', 'sub_code' => 'WA']));
check("same name, other state -> 201 new row (POLICY 4.11)", $code === 201 && $j['id'] !== $bar);
$barWA = $j['id'];
// url-only request that misses on host does not fall through to a name-only match
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewery', ['url' => 'https://another-bar.example']));
check("url-only miss does not name-match -> 201", $code === 201 && $j['id'] !== $bar && $j['id'] !== $barWA);
$barOther = $j['id'];

// 7. claim: oldest first, each once; backdate so order is unambiguous
$db = new Database();
$db->query("UPDATE brewer_lead SET createdAt = 1000 WHERE id=?", [$bar]);
$db->query("UPDATE brewer_lead SET createdAt = 2000 WHERE id=?", [$barWA]);
$db->query("UPDATE brewer_lead SET createdAt = 3000 WHERE id=?", [$barOther]);
$db->close();
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 2]);
$ids = array_map(fn($r) => $r['id'], $j['data']);
check("claim count=2 -> the two oldest, in order", $code === 200 && $ids === [$bar, $barWA] && $j['decided'] === 0);
check("claimed rows say so", $j['data'][0]['status'] === 'claimed' && $j['data'][0]['claimed_by'] === $REVIEWER && $j['data'][0]['claim_expires_at'] - time() > 14000);
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 5]);
$ids = array_map(fn($r) => $r['id'], $j['data']);
check("second claim gets only the unclaimed row", $ids === [$barOther]);
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 5]);
check("third claim is empty", count($j['data']) === 0);
[$code, $j] = call('GET', '', '', $ADMIN, null, ['status' => 'claimed']);
check("list status=claimed shows all three", count($j['data']) === 3 && $j['status'] === 'claimed');
[$code, $j] = call('GET', '', '', $ADMIN, null, ['status' => 'queued']);
check("list status=queued is empty", count($j['data']) === 0);
[$code, $j] = call('GET', '', '', $ADMIN, null, ['status' => 'bogus']);
check("list status=bogus -> 400", $code === 400 && isset($j['validation']['status']));

// 8. PATCH validation
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)[]);
check("patch with no operation -> 400", $code === 400 && isset($j['validation']['resolution']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['resolution' => 'not_a_brewery', 'recheck_after' => time() + 100]);
check("resolution + recheck_after -> 400 on both", $code === 400 && isset($j['validation']['resolution']) && isset($j['validation']['recheck_after']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['resolution' => 'maybe']);
check("bad resolution -> 400", $code === 400 && isset($j['validation']['resolution']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['resolution' => 'created']);
check("created without brewer_id -> 400", $code === 400 && isset($j['validation']['brewer_id']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['resolution' => 'created', 'brewer_id' => 'cccccccc-0000-4000-8000-0000000000ff']);
check("created with unknown brewer_id -> 400", $code === 400 && isset($j['validation']['brewer_id']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['resolution' => 'not_a_brewery', 'brewer_id' => $ALPHA]);
check("not_a_brewery with brewer_id -> 400", $code === 400 && isset($j['validation']['brewer_id']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['recheck_after' => time() - 10, 'note' => 'x']);
check("recheck_after in the past -> 400", $code === 400 && isset($j['validation']['recheck_after']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['recheck_after' => time() + 100]);
check("defer without note -> 400", $code === 400 && isset($j['validation']['note']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['needs_decision' => true]);
check("ask without question -> 400", $code === 400 && isset($j['validation']['question']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['needs_decision' => false, 'question' => 'x?']);
check("needs_decision: false -> 400", $code === 400 && isset($j['validation']['needs_decision']));
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['decision' => 'Go ahead.']);
check("decide with no open question -> 409", $code === 409 && isset($j['validation']['decision']));
[$code, $j] = call('PATCH', '', 'cccccccc-0000-4000-8000-0000000000ff', $ADMIN, (object)['resolution' => 'not_a_brewery']);
check("patch unknown lead -> 404", $code === 404);
[$code, $j] = call('GET', '', $bar, $ADMIN);
check("rejected patches left the row claimed and open", $j['status'] === 'claimed' && $j['resolution'] === null);

// 9. defer: back to the queue, held; claim skips it until the date passes
$soon = time() + 3600;
[$code, $j] = call('PATCH', '', $barWA, $ADMIN, (object)['recheck_after' => $soon, 'note' => 'Checked 2026-09; still "opening soon".']);
check("defer -> 200, queued, held, claim cleared, note replaced", $code === 200 && $j['status'] === 'queued' && $j['recheck_after'] === $soon && $j['claimed_by'] === null && $j['note'] === 'Checked 2026-09; still "opening soon".');
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 5]);
check("a deferred row is not claimed before its date", count($j['data']) === 0);
$db = new Database(); $db->query("UPDATE brewer_lead SET recheckAfter = ? WHERE id=?", [time() - 1, $barWA]); $db->close();
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 5]);
check("once the date passes it is claimed again", count($j['data']) === 1 && $j['data'][0]['id'] === $barWA);

// 10. ask, then the check-in list, then decide, then the decided row leads the next claim regardless of count
[$code, $j] = call('PATCH', '', $barWA, $ADMIN, (object)['needs_decision' => true, 'question' => 'Site says "our beer" but names no brewer. Brand or brewery?', 'note' => 'Evidence: shop page lists three cans, no brewhouse, no address.']);
check("ask -> 200, queued, flag set, claim released, note replaced", $code === 200 && $j['status'] === 'queued' && $j['needs_decision'] === true && $j['claimed_by'] === null && str_starts_with($j['note'], 'Evidence:'));
[$code, $j] = call('PATCH', '', $barWA, $ADMIN, (object)['needs_decision' => true, 'question' => 'Again?']);
check("ask on a row with an open question -> 409", $code === 409 && isset($j['validation']['question']));
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 5]);
check("a row with an open question is never claimed", count($j['data']) === 0);
[$code, $j] = call('GET', '', '', $ADMIN, null, ['needs_decision' => '1']);
check("needs_decision list has the row", $code === 200 && count($j['data']) === 1 && $j['data'][0]['id'] === $barWA && $j['needs_decision'] === true);
[$code, $j] = call('PATCH', '', $barWA, $HUMAN, (object)['decision' => 'Brand only. Resolve not_a_brewery.']);
check("decide -> 200, flag cleared, decided_by is the human", $code === 200 && $j['needs_decision'] === false && $j['decision'] === 'Brand only. Resolve not_a_brewery.' && $j['decided_by'] === $MICHAEL);
[$code, $j] = call('GET', '', '', $ADMIN, null, ['needs_decision' => '1']);
check("needs_decision list now empty", count($j['data']) === 0);
// free the other two claims so count=1 has competition; decided row still comes first, plus one more
$db = new Database(); $db->query("UPDATE brewer_lead SET claimedAt = NULL WHERE id IN (?, ?)", [$bar, $barOther]); $db->close();
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 1]);
$ids = array_map(fn($r) => $r['id'], $j['data']);
check("count=1 with a decision waiting -> decided row first, plus one", $ids === [$barWA, $bar] && $j['decided'] === 1);
check("the decided row carries its question and decision", $j['data'][0]['question'] !== null && $j['data'][0]['decision'] === 'Brand only. Resolve not_a_brewery.');

// 11. resolve: acts on the decision; closed, permanent, needs reopen
[$code, $j] = call('PATCH', '', $barWA, $ADMIN, (object)['resolution' => 'not_a_brewery']);
check("resolve -> 200 closed with resolved_by/at, claim cleared", $code === 200 && $j['status'] === 'closed' && $j['resolution'] === 'not_a_brewery' && $j['resolved_by'] === $REVIEWER && $j['resolved_at'] > 0 && $j['claimed_by'] === null);
[$code, $j] = call('PATCH', '', $barWA, $ADMIN, (object)['resolution' => 'out_of_scope']);
check("patch a closed row without reopen -> 409", $code === 409 && isset($j['validation']['status']));
[$code, $j] = call('GET', '', $barWA, $ADMIN);
check("closed row untouched", $j['resolution'] === 'not_a_brewery');
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 5]);
$ids = array_map(fn($r) => $r['id'], $j['data']);
check("a closed row is never claimed; the unclaimed open one is", $ids === [$barOther]);
// negative cache: queueing it again returns the closed row
[$code, $j] = call('POST', '', '', $ADMIN, lead('Bar Brewery', ['city' => 'Spokane', 'sub_code' => 'WA', 'source_url' => 'https://third.example/p']));
check("dedup hits a closed row -> 200 with its resolution (negative cache)", $code === 200 && $j['id'] === $barWA && $j['status'] === 'closed' && $j['resolution'] === 'not_a_brewery' && count($j['sources']) === 2);
// reopen by a human: change the resolution
[$code, $j] = call('PATCH', '', $barWA, $HUMAN, (object)['resolution' => 'duplicate', 'brewer_id' => $ALPHA, 'reopen' => true]);
check("reopen + resolve rewrites the resolution", $code === 200 && $j['resolution'] === 'duplicate' && $j['brewer_id'] === $ALPHA && $j['brewer_name'] === 'Alpha Brewing' && $j['resolved_by'] === $MICHAEL);
// reopen by defer: back to the queue, resolution shed
[$code, $j] = call('PATCH', '', $barWA, $HUMAN, (object)['recheck_after' => time() + 86400, 'note' => 'Reopened: new article says they brew.', 'reopen' => true]);
check("reopen + defer returns it to the queue with no resolution", $code === 200 && $j['status'] === 'queued' && $j['resolution'] === null && $j['brewer_id'] === null && $j['resolved_by'] === null && $j['decision'] === null);

// 12. created: brewer_id required and must exist; list by resolution
[$code, $j] = call('PATCH', '', $bar, $ADMIN, (object)['resolution' => 'created', 'brewer_id' => $ALPHA]);
check("resolve created with brewer_id -> closed", $code === 200 && $j['status'] === 'closed' && $j['resolution'] === 'created' && $j['brewer_id'] === $ALPHA);
[$code, $j] = call('GET', '', '', $ADMIN, null, ['resolution' => 'created']);
check("list resolution=created", count($j['data']) === 1 && $j['data'][0]['id'] === $bar);
[$code, $j] = call('GET', '', '', $ADMIN, null, ['status' => 'closed']);
check("list status=closed", count($j['data']) === 1 && $j['status'] === 'closed');
// FK ON DELETE SET NULL: deleting the brewer keeps the lead
$db = new Database(); $db->query("DELETE FROM brewer WHERE id=?", [$ALPHA]); $db->close();
[$code, $j] = call('GET', '', $bar, $ADMIN);
check("brewer deleted -> lead survives with brewer_id null", $code === 200 && $j['resolution'] === 'created' && $j['brewer_id'] === null && $j['brewer_name'] === null);

// 13. expired claim is free again, and shows as queued
$db = new Database(); $db->query("UPDATE brewer_lead SET claimedAt = claimedAt - 20000 WHERE id=?", [$barOther]); $db->close();
[$code, $j] = call('GET', '', $barOther, $ADMIN);
check("expired claim reads as queued with null claimed_by", $j['status'] === 'queued' && $j['claimed_by'] === null);
[$code, $j] = call('POST', 'claim', '', $ADMIN, (object)['count' => 5]);
$ids = array_map(fn($r) => $r['id'], $j['data']);
check("expired claim is handed out again", $ids === [$barOther]);

// 14. method + path guards
[$code, $j, $hdr] = call('DELETE', '', $bar, $ADMIN);
check("DELETE -> 405 with Allow", $code === 405 && $hdr === 'Allow: GET, POST, PATCH');
[$code, $j] = call('GET', 'bogus', '', $ADMIN);
check("bad function -> 404", $code === 404);
[$code, $j] = call('GET', '', 'cccccccc-0000-4000-8000-0000000000ff', $ADMIN);
check("get unknown lead -> 404", $code === 404);

// 15. pagination
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
?>
