<?php
/*--
Regression test for the spend-cap floor and the record phase's reach in
Billing.class.php. Needs a LOCAL MySQL (it creates and drops a scratch
database named cb_billing_test); no network, no secrets file, no Stripe, no
email (the test users carry no stripeCustomerID and the record step is
exercised through recordOverageCharges(), which runs before any notice).

    php tests/billing.php [path/to/catalog-beer-schema.sql]

Connection defaults to root@127.0.0.1 with no password (Homebrew MySQL);
override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.

What it proves: a cap can never be set below what the key has already
committed to pay this month (the overage, clamped to the current cap), nor
below last month's charge until billing_charges holds its row; the refusal
is a 400 that carries minimum_cap_cents and minimum_cap_until and names the
month; a cap of 0 is refused with overage and accepted without; GET /billing
reports the floor and, for a key whose billing was turned off after it was
served past the free buffer, still reports the charge; DELETE /billing says
what is still owed; the record phase writes a charge for a billing-enabled
key past its limit and for a disabled key past the buffer, but never for a
free key sitting at the buffer, and never twice; recording last month clears
its term of the floor.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__) . '/public_html');
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_billing_test');
define('MASTER_API_KEYS', serialize(array()));
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

$schemaPath = $argv[1] ?? dirname(__DIR__, 2) . '/catalog-beer-mysql/catalog-beer-schema.sql';
if(!is_readable($schemaPath)){
    echo "Schema not found: $schemaPath\n";
    exit(1);
}

// ----- Months -----
$now = time();
$Y = (int)date('Y', $now); $M = (int)date('n', $now);
$lastTs = mktime(0, 0, 0, $M - 1, 1, $Y);
$LY = (int)date('Y', $lastTs); $LM = (int)date('n', $lastTs);
$nextFirst = date('Y-m-d', mktime(0, 0, 0, $M + 1, 1, $Y));
$thisLabel = date('F Y', $now); $lastLabel = date('F Y', $lastTs);

// ----- Scratch database -----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
$admin->query("DROP DATABASE IF EXISTS " . DB_NAME);
$admin->query("CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$admin->select_db(DB_NAME);
$u = function($n){ return sprintf('aaaaaaaa-0000-4000-8000-%012d', $n); };
$k = function($n){ return sprintf('bbbbbbbb-0000-4000-8000-%012d', $n); };
$sql = "SET FOREIGN_KEY_CHECKS=0;\n" . file_get_contents($schemaPath) . "\nSET FOREIGN_KEY_CHECKS=1;\n";
for($i = 1; $i <= 9; $i++){
    $sql .= "INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES ('{$u($i)}','user$i@example.com','x','User $i',1,0);\n";
}
// key: enabled, cap, this-month count, last-month count, last month already recorded?
$keys = array(
    1 => array(1, 20000, 174713, null, false),   // the October 2026 case: $174 committed
    2 => array(0, 5000, 5000, 3000, false),      // billing turned off after being served past the buffer
    3 => array(0, 5000, 1051, 1051, false),      // a free key stopped at the buffer
    4 => array(1, 5000, 500, null, false),       // enabled, inside the free tier
    5 => array(1, 5000, null, 7503, false),      // last month unrecorded: $7 still binds
    6 => array(1, 5000, null, 7503, true),       // last month recorded: nothing binds
    7 => array(1, 5000, 2500, 7503, false),      // both terms; last month's $7 beats this month's $2
    8 => array(1, 150, 5000, null, false),       // the cap clamps the committed charge to $1.50
    9 => array(1, 5000, null, 1020, false),      // one block over, last month
);
foreach($keys as $n => [$enabled, $cap, $thisCount, $lastCount, $recorded]){
    $sql .= "INSERT INTO api_keys (id,userID,billingEnabled,monthlySpendCapCents) VALUES ('{$k($n)}','{$u($n)}',$enabled,$cap);\n";
    if($thisCount !== null){
        $sql .= "INSERT INTO api_usage (id,apiKey,year,month,count,lastUpdated) VALUES ('cccccccc-0000-4000-8000-" . sprintf('%012d', $n) . "','{$k($n)}',$Y,$M,$thisCount,$now);\n";
    }
    if($lastCount !== null){
        $sql .= "INSERT INTO api_usage (id,apiKey,year,month,count,lastUpdated) VALUES ('dddddddd-0000-4000-8000-" . sprintf('%012d', $n) . "','{$k($n)}',$LY,$LM,$lastCount,$now);\n";
    }
    if($recorded){
        $sql .= "INSERT INTO billing_charges (id,userID,apiKey,year,month,totalRequests,billableRequests,amountCents,status,createdAt,lastUpdated) VALUES ('eeeeeeee-0000-4000-8000-" . sprintf('%012d', $n) . "','{$u($n)}','{$k($n)}',$LY,$LM,$lastCount," . ($lastCount - 1000) . ",700,'pending',$now,$now);\n";
    }
}
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Helpers -----
function call($method, $key, $data = null, $function = '') {
    $b = new Billing(); $b->api($method, $function, '', $key, $data ?? new stdClass());
    return [$b->responseCode, $b->json];
}
function patch($key, $cap) { return call('PATCH', $key, (object)['monthly_spend_cap_cents' => $cap]); }
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;
$b = new Billing();

// 1. committedCents: the rule the API and the cron share
check("enabled: overage clamped to cap", $b->committedCents(174713, 1000, 50, true, 20000) === 17400);
check("enabled: one request over is one block", $b->committedCents(1001, 1000, 50, true, 5000) === 100);
check("disabled past the buffer: still owed", $b->committedCents(5000, 1000, 50, false, 5000) === 400);
check("disabled at the buffer: nothing owed", $b->committedCents(1051, 1000, 50, false, 5000) === 0);
check("disabled at the gate's last count (1,051): nothing owed; one past it: owed", $b->committedCents(1051, 1000, 50, false, 5000) === 0 && $b->committedCents(1052, 1000, 50, false, 5000) === 100);

// 2. The October case: $174 committed under a $200 cap
[$code, $j] = patch($k(1), 17399);
check("K1 cap \$173.99 -> 400", $code === 400 && $j['error'] === true);
check("K1 refusal carries minimum_cap_cents 17400", ($j['minimum_cap_cents'] ?? null) === 17400);
check("K1 refusal carries minimum_cap_until = 1st of next month", ($j['minimum_cap_until'] ?? null) === $nextFirst);
check("K1 message names the month, the amount and the count", strpos($j['error_msg'], $thisLabel) !== false && strpos($j['error_msg'], '$174.00') !== false && strpos($j['error_msg'], '174,713') !== false && strpos($j['error_msg'], date('F j, Y', strtotime($nextFirst))) !== false);
[$code, $j] = patch($k(1), 4000);
check("K1 cap \$40 (the real October lowering) -> 400", $code === 400);
[$code, $j] = patch($k(1), 0);
check("K1 cap 0 -> 400 (overage exists)", $code === 400 && $j['minimum_cap_cents'] === 17400);
[$code, $j] = patch($k(1), 17400);
check("K1 cap exactly the floor -> 200", $code === 200 && $j['monthly_spend_cap_cents'] === 17400);
check("K1 200 carries minimum_cap_cents/until", $j['minimum_cap_cents'] === 17400 && $j['minimum_cap_until'] === $nextFirst);
[$code, $j] = patch($k(1), 30000);
check("K1 raising the cap -> 200", $code === 200 && $j['monthly_spend_cap_cents'] === 30000);
[$code, $j] = patch($k(1), 17400);
check("K1 back down to the floor after a raise -> 200 (floor uses the live cap, which is above the blocks)", $code === 200);

// 3. Billing turned off after usage past the buffer: the charge still binds
[$code, $j] = call('GET', $k(2));
check("K2 status: billing off, estimated_charge_cents still \$4", $code === 200 && $j['billing_enabled'] === false && $j['estimated_charge_cents'] === 400);
check("K2 status: minimum_cap_cents 400", $j['minimum_cap_cents'] === 400 && $j['minimum_cap_until'] === $nextFirst);
[$code, $j] = patch($k(2), 300);
check("K2 cap \$3 -> 400", $code === 400 && $j['minimum_cap_cents'] === 400);
[$code, $j] = patch($k(2), 400);
check("K2 cap \$4 -> 200", $code === 200);

// 4. A free key at the buffer owes nothing
[$code, $j] = call('GET', $k(3));
check("K3 status: estimated 0, minimum 0, until null", $j['estimated_charge_cents'] === 0 && $j['minimum_cap_cents'] === 0 && $j['minimum_cap_until'] === null);
[$code, $j] = patch($k(3), 0);
check("K3 cap 0 -> 200", $code === 200 && $j['monthly_spend_cap_cents'] === 0 && $j['minimum_cap_cents'] === 0);

// 5. Inside the free tier: anything goes
[$code, $j] = patch($k(4), 0);
check("K4 cap 0 -> 200", $code === 200);
[$code, $j] = patch($k(4), 100);
check("K4 cap \$1 -> 200", $code === 200);
[$code, $j] = patch($k(4), 50);
check("K4 cap 50 cents -> 400 (shape rule, unchanged)", $code === 400 && !isset($j['minimum_cap_cents']));
[$code, $j] = patch($k(4), '2000');
check("K4 cap as a string -> 400 (shape rule, unchanged)", $code === 400);

// 6. Last month, not yet recorded
[$code, $j] = patch($k(5), 600);
check("K5 cap \$6 under last month's unrecorded \$7 -> 400", $code === 400 && $j['minimum_cap_cents'] === 700);
check("K5 refusal has no until date and names last month", $j['minimum_cap_until'] === null && strpos($j['error_msg'], $lastLabel) !== false && strpos($j['error_msg'], 'not been recorded') !== false);
[$code, $j] = patch($k(5), 700);
check("K5 cap \$7 -> 200", $code === 200);
[$code, $j] = patch($k(6), 100);
check("K6 same usage, already recorded -> \$1 accepted", $code === 200 && $j['minimum_cap_cents'] === 0);
[$code, $j] = patch($k(7), 500);
check("K7 both terms: last month's \$7 binds over this month's \$2", $code === 400 && $j['minimum_cap_cents'] === 700 && $j['minimum_cap_until'] === null);
[$code, $j] = patch($k(7), 700);
check("K7 cap \$7 -> 200", $code === 200);

// 7. The cap clamps the committed charge
[$code, $j] = patch($k(8), 100);
check("K8 \$1.50 cap clamps \$4 of usage to \$1.50; \$1 -> 400", $code === 400 && $j['minimum_cap_cents'] === 150 && strpos($j['error_msg'], '$1.50') !== false);
[$code, $j] = patch($k(8), 150);
check("K8 cap \$1.50 -> 200", $code === 200);

// 8. DELETE /billing reports what is still owed, and the floor survives it
[$code, $j] = call('DELETE', $k(8));
check("K8 DELETE -> 200 with estimated_charge_cents 150", $code === 200 && $j['billing_enabled'] === false && $j['estimated_charge_cents'] === 150);
[$code, $j] = call('GET', $k(8));
check("K8 after DELETE: billing off, charge and floor still \$1.50", $j['billing_enabled'] === false && $j['estimated_charge_cents'] === 150 && $j['minimum_cap_cents'] === 150);
[$code, $j] = call('DELETE', $k(4));
check("K4 DELETE inside the free tier -> estimated 0", $code === 200 && $j['estimated_charge_cents'] === 0);

// 9. GET /billing shape
[$code, $j] = call('GET', $k(1));
$expected = ['object', 'billing_enabled', 'monthly_spend_cap_cents', 'card', 'year', 'month', 'count', 'request_limit', 'billable_requests', 'estimated_charge_cents', 'unbilled_balance_cents', 'minimum_cap_cents', 'minimum_cap_until'];
check("GET /billing keys", array_keys($j) === $expected);
check("GET /billing never carries the key", !isset($j['api_key']));

// 10. The record phase
$db = new Database();
$recorded = $b->recordOverageCharges($LY, $LM, $now);
check("record phase: 4 new rows (K2 disabled past buffer, K5, K7, K9 enabled; K6 already there; K3 free at the buffer skipped)", $recorded === 4);
$rows = array();
$res = $db->query("SELECT apiKey, amountCents FROM billing_charges WHERE year=? AND month=? ORDER BY apiKey", [$LY, $LM]);
while($r = $res->fetch_assoc()){ $rows[$r['apiKey']] = intval($r['amountCents']); }
check("K2 (billing off, 3,000 requests) recorded at \$2", ($rows[$k(2)] ?? null) === 200);
check("K3 (free, 1,051) not recorded", !isset($rows[$k(3)]));
check("K5 recorded at \$7", ($rows[$k(5)] ?? null) === 700);
check("K6 kept its existing row", ($rows[$k(6)] ?? null) === 700);
check("K7 recorded at \$7", ($rows[$k(7)] ?? null) === 700);
check("K9 (1,020) recorded at \$1", ($rows[$k(9)] ?? null) === 100);
check("exactly K2, K5, K6, K7, K9 hold rows", count($rows) === 5);
$again = $b->recordOverageCharges($LY, $LM, $now);
check("re-run records nothing new", $again === 0);
$res = $db->query("SELECT COUNT(*) AS n FROM billing_charges", []);
check("re-run added no rows", intval($res->fetch_assoc()['n']) === 5);

// 11. Recording last month clears its term
[$code, $j] = patch($k(5), 100);
check("K5 after recording: \$1 accepted", $code === 200 && $j['minimum_cap_cents'] === 0);
[$code, $j] = patch($k(7), 200);
check("K7 after recording: this month's \$2 is the floor; \$2 accepted", $code === 200 && $j['minimum_cap_cents'] === 200 && $j['minimum_cap_until'] === $nextFirst);
[$code, $j] = patch($k(7), 100);
check("K7 after recording: \$1 refused", $code === 400 && $j['minimum_cap_cents'] === 200);

// 12. The refusal is logged
$res = $db->query("SELECT COUNT(*) AS n FROM error_log WHERE errorNumber=378", []);
$logged = intval($res->fetch_assoc()['n']);
check("refusals logged as 378 ($logged rows)", $logged >= 8);
$db->close();

// ----- Done -----
if(!getenv('CB_TEST_KEEP')){
    $admin->query("DROP DATABASE " . DB_NAME);
}
echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
