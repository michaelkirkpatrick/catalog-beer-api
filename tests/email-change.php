<?php
/*--
Regression test for the change-of-email flow in Users.class.php (API 6.10.0).
Needs a LOCAL MySQL (it creates and drops a scratch database named
cb_email_change_test); no network, no secrets file, no Stripe and no Postmark:
mail lands in SendEmail::$outbox and the Stripe customer update is a method
the test stands in for.

    php tests/email-change.php [path/to/catalog-beer-schema.sql]

Connection defaults to root@127.0.0.1 with no password (Homebrew MySQL);
override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.

What it proves: a verified account's email changes only when the new inbox
confirms it, and until then (and after) its API key, billingEnabled, spend
cap, request limit and the month's usage are exactly what they were; brewery
privileges end only at the confirmation of a NEW domain and survive a
same-domain change; the confirmation needs an admin caller, the right code,
a code no older than 24 hours and the user_id of the account itself; a code
is single-use and a newer request kills the older code; an address taken in
the meantime is a 409 that changes nothing; an unverified account's address
is corrected in place and always re-mailed; one verification mail a minute
and five a day, then 429 with Retry-After; a password change or reset clears
a pending change; neither code is ever returned to a non-admin key; the
Stripe customer follows the account and its failure never fails the change;
the old address is told; GET /users/{id}/privileges lists row-backed and
domain-matched brewers and refuses other accounts.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__) . '/public_html');
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_email_change_test');
define('MASTER_API_KEYS', serialize(array()));
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

$schemaPath = $argv[1] ?? dirname(__DIR__, 2) . '/catalog-beer-mysql/catalog-beer-schema.sql';
if(!is_readable($schemaPath)){
    echo "Schema not found: $schemaPath\n";
    exit(1);
}

// Users with the Stripe call replaced: records what it was asked, answers
// what the test tells it to.
class TestUsers extends Users {
    public static $stripeCalls = array();
    public static $stripeAnswer = true;
    protected function updateStripeCustomerEmail($stripeCustomerID, $email){
        self::$stripeCalls[] = array($stripeCustomerID, $email);
        return self::$stripeAnswer;
    }
}

// ----- Scratch database -----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
$admin->query("DROP DATABASE IF EXISTS " . DB_NAME);
$admin->query("CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$admin->select_db(DB_NAME);
$u = function($n){ return sprintf('aaaaaaaa-0000-4000-8000-%012d', $n); };
$k = function($n){ return sprintf('bbbbbbbb-0000-4000-8000-%012d', $n); };
$b = function($n){ return sprintf('cccccccc-0000-4000-8000-%012d', $n); };
$now = time();
$Y = (int)date('Y', $now); $M = (int)date('n', $now);
$hash = password_hash('correct horse battery', PASSWORD_DEFAULT);
$signupCode = 'dddddddd-0000-4000-8000-000000000003';

$sql = "SET FOREIGN_KEY_CHECKS=0;\n" . file_get_contents($schemaPath) . "\nSET FOREIGN_KEY_CHECKS=1;\n";
// 0 the site's admin; 1 brewery staff with billing; 2 plain; 3 unverified; 4 and 6 plain; 5 arrives later
$sql .= "INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES ('{$u(0)}','site@catalog.example','x','Site',1,1);\n";
$sql .= "INSERT INTO users (id,email,passwordHash,name,emailVerified,admin,stripeCustomerID) VALUES ('{$u(1)}','alice@brewery-a.example','$hash','Alice',1,0,'cus_test1');\n";
$sql .= "INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES ('{$u(2)}','user2@example.com','$hash','User 2',1,0);\n";
$sql .= "INSERT INTO users (id,email,passwordHash,name,emailVerified,admin,emailAuth,emailAuthSent) VALUES ('{$u(3)}','uesr3@example.com','$hash','User 3',0,0,'$signupCode'," . ($now - 3600) . ");\n";
$sql .= "INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES ('{$u(4)}','user4@example.com','$hash','User 4',1,0);\n";
$sql .= "INSERT INTO users (id,email,passwordHash,name,emailVerified,admin,stripeCustomerID) VALUES ('{$u(6)}','user6@example.com','$hash','User 6',1,0,'cus_test6');\n";
$sql .= "INSERT INTO api_keys (id,userID) VALUES ('{$k(0)}','{$u(0)}');\n";
$sql .= "INSERT INTO api_keys (id,userID,requestLimit,billingEnabled,monthlySpendCapCents) VALUES ('{$k(1)}','{$u(1)}',5000,1,2000);\n";
foreach(array(2, 4, 6) as $n){
    $sql .= "INSERT INTO api_keys (id,userID) VALUES ('{$k($n)}','{$u($n)}');\n";
}
$sql .= "INSERT INTO api_usage (id,apiKey,year,month,count,lastUpdated) VALUES ('eeeeeeee-0000-4000-8000-000000000001','{$k(1)}',$Y,$M,1234,$now);\n";
// Two brewers on Alice's domain (a sub-brand on a page of its owner's site),
// one on example.com, one elsewhere. Alice has a row for the first only.
$sql .= "INSERT INTO brewer (id,name,url,domainName,lastModified) VALUES ('{$b(1)}','Brewery A','https://brewery-a.example/','brewery-a.example',$now);\n";
$sql .= "INSERT INTO brewer (id,name,url,domainName,lastModified) VALUES ('{$b(2)}','Brewery A Blendery','https://brewery-a.example/blendery','brewery-a.example',$now);\n";
$sql .= "INSERT INTO brewer (id,name,url,domainName,lastModified) VALUES ('{$b(3)}','Example Ales','https://example.com/','example.com',$now);\n";
$sql .= "INSERT INTO brewer (id,name,url,domainName,lastModified) VALUES ('{$b(4)}','Elsewhere Beer','https://elsewhere.example/','elsewhere.example',$now);\n";
$sql .= "INSERT INTO privileges (id,userID,brewerID) VALUES ('ffffffff-0000-4000-8000-000000000001','{$u(1)}','{$b(1)}');\n";
$sql .= "INSERT INTO privileges (id,userID,brewerID) VALUES ('ffffffff-0000-4000-8000-000000000002','{$u(2)}','{$b(3)}');\n";
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Helpers -----
SendEmail::$outbox = array();
function call($method, $function, $id, $key, $data = null) {
    $users = new TestUsers();
    $users->usersAPI($method, $function, $id, $key, (object)($data ?? array()));
    return [$users->responseCode, $users->json, $users->responseHeader];
}
function patch($id, $key, $data) { return call('PATCH', '', $id, $key, $data); }
function confirm($code, $key, $userID = null) { return call('POST', 'confirm-email', $code, $key, $userID === null ? array() : array('user_id' => $userID)); }
function one($sql) { global $admin; $r = $admin->query($sql); $row = $r->fetch_assoc(); $r->free(); return $row; }
function user($id) { return one("SELECT email, emailVerified+0 AS verified, emailAuth, emailAuthSent, pendingEmail, pendingEmailAuth, pendingEmailSent FROM users WHERE id='$id'"); }
function keyRow($userID) { return one("SELECT id, requestLimit, billingEnabled+0 AS billing, monthlySpendCapCents AS cap FROM api_keys WHERE userID='$userID'"); }
function privilegeCount($userID) { return (int)one("SELECT COUNT(*) AS n FROM privileges WHERE userID='$userID'")['n']; }
function lastAudit($userID, $field) { return one("SELECT oldValue, newValue, note FROM audit_log WHERE entityType='user' AND entityID='$userID' AND field='$field' ORDER BY id DESC LIMIT 1"); }
function errorCount($number) { return (int)one("SELECT COUNT(*) AS n FROM error_log WHERE errorNumber='$number'")['n']; }
// Move the account's last send (and its counted requests) into the past so
// the next request is neither inside the minute nor over the day's five.
function ageRequests($userID) {
    global $admin;
    $admin->query("UPDATE users SET pendingEmailSent = pendingEmailSent - 120 WHERE id='$userID' AND pendingEmailSent IS NOT NULL");
    $admin->query("UPDATE audit_log SET changedAt = changedAt - 90000 WHERE entityType='user' AND entityID='$userID'");
}
function mailTo($address) { $found = array(); foreach(SendEmail::$outbox as $m){ if($m['to'] === $address){ $found[] = $m; } } return $found; }
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;
$site = $k(0);
$userKeys = array('id', 'object', 'name', 'email', 'email_verified', 'email_auth', 'email_auth_sent', 'pending_email', 'pending_email_auth', 'pending_email_sent', 'admin');

// 1. GET /users/{id}/privileges
[$code, $j] = call('GET', 'privileges', $u(1), $site);
check("privileges: a row-backed brewer and a domain-match brewer with no row, by name", $code === 200 && $j['object'] === 'list' && $j['data'] === array(array('brewer_id' => $b(1), 'name' => 'Brewery A'), array('brewer_id' => $b(2), 'name' => 'Brewery A Blendery')));
[$code, $j] = call('GET', 'privileges', $u(1), $k(1));
check("privileges: the account may read its own", $code === 200 && count($j['data']) === 2);
[$code, $j] = call('GET', 'privileges', $u(1), $k(2));
check("privileges: another account's key is refused (403)", $code === 403 && $j['error'] === true && !isset($j['data']));
[$code, $j] = call('GET', 'privileges', $u(4), $site);
check("privileges: user4@example.com is staff of Example Ales by domain alone", $code === 200 && count($j['data']) === 1 && $j['data'][0]['name'] === 'Example Ales');
[$code, $j] = call('GET', 'privileges', $u(3), $site);
check("privileges: an unverified account has none", $code === 200 && $j['data'] === array());

// 2. A verified account asks to move to a new domain
$keyBefore = keyRow($u(1));
[$code, $j] = patch($u(1), $site, array('email' => 'Alice@New-Home.example'));
$code1 = $j['pending_email_auth'] ?? null;
check("request: 200 with every key of the user object", $code === 200 && array_keys($j) === $userKeys);
check("request: email unchanged, still verified", $j['email'] === 'alice@brewery-a.example' && $j['email_verified'] === true);
check("request: pending_email is the new address, lowercased", $j['pending_email'] === 'alice@new-home.example' && is_int($j['pending_email_sent']));
check("request: an admin caller receives the code", is_string($code1) && strlen($code1) === 36);
check("request: the key row is untouched", keyRow($u(1)) === $keyBefore && $keyBefore['id'] === $k(1) && $keyBefore['billing'] === '1' && $keyBefore['cap'] === '2000' && $keyBefore['requestLimit'] === '5000');
check("request: privileges still held", privilegeCount($u(1)) === 1);
$mail = mailTo('alice@new-home.example');
check("request: one confirmation mail, to the NEW address", count($mail) === 1 && $mail[0]['tag'] === 'confirm-new-email' && count(mailTo('alice@brewery-a.example')) === 0);
check("mail: carries the link with the code, on the staging host", strpos($mail[0]['html'], "https://staging.catalog.beer/confirm-email/$code1") !== false && strpos($mail[0]['text'], "https://staging.catalog.beer/confirm-email/$code1") !== false);
$mailKept = SendEmail::$outbox;
// The sign-up and password-reset mails open the same environment that sent them.
SendEmail::$outbox = array();
$hostMail = new SendEmail();
$hostMail->verifyEmail('host-check@example.com', 'HOSTAUTH');
$hostMail->passwordResetEmail('host-check@example.com', 'HOSTKEY');
check("mail: sign-up link is on the staging host, HTML and text",
    strpos(SendEmail::$outbox[0]['html'], 'https://staging.catalog.beer/verify-email/HOSTAUTH') !== false
    && strpos(SendEmail::$outbox[0]['text'], 'https://staging.catalog.beer/verify-email/HOSTAUTH') !== false);
check("mail: password-reset link is on the staging host, HTML and text",
    strpos(SendEmail::$outbox[1]['html'], 'https://staging.catalog.beer/password-reset/HOSTKEY') !== false
    && strpos(SendEmail::$outbox[1]['text'], 'https://staging.catalog.beer/password-reset/HOSTKEY') !== false);
$hostMail->emailChanged('host-check@example.com', 'new@example.com', time());
$hostMail->confirmNewEmail('new@example.com', 'HOSTAUTH2', 'host-check@example.com');
$hostMail->errorDigest('body', 'text', 1, 'today');
$hostMail->phpErrorDigest('body', 'text', 1, 'today');
check("mail: every account and digest footer links to the staging host, no token left",
    count(SendEmail::$outbox) === 6 && count(array_filter(SendEmail::$outbox, function($m){
        return strpos($m['html'], '<a href="https://staging.catalog.beer">') !== false && strpos($m['html'], '##') === false;
    })) === 6);
check("mail: the password-reset plain text carries no HTML entities",
    strpos(SendEmail::$outbox[1]['text'], '&#') === false);
SendEmail::$outbox = $mailKept;

check("mail: names the account by its masked address, never the full one", strpos($mail[0]['html'], 'a***@brewery-a.example') !== false && strpos($mail[0]['html'], 'alice@brewery-a.example') === false && strpos($mail[0]['text'], 'alice@brewery-a.example') === false);
check("mail: no placeholder left unfilled", strpos($mail[0]['html'], '##') === false);
check("audit: pendingEmail null -> new address", lastAudit($u(1), 'pendingEmail') === array('oldValue' => null, 'newValue' => 'alice@new-home.example', 'note' => null));

// 3. The account's own key never sees a code
[$code, $j] = call('GET', '', $u(1), $k(1));
check("own key: pending_email shown, pending_email_auth and email_auth null", $code === 200 && $j['pending_email'] === 'alice@new-home.example' && $j['pending_email_auth'] === null && $j['email_auth'] === null);
[$code, $j] = call('GET', '', $u(3), $site);
check("admin key: an unverified account's email_auth is still returned", $j['email_auth'] === $signupCode);

// 4. One mail a minute
[$code, $j, $header] = patch($u(1), $site, array('email' => 'alice@new-home.example'));
check("resend inside a minute: 429 with Retry-After 1..60", $code === 429 && $j['error'] === true && preg_match('/^Retry-After: (\d+)$/', $header, $m) === 1 && $m[1] >= 1 && $m[1] <= 60);
check("the 429 is logged as 382", errorCount(382) === 1);
check("the 429 sent nothing and kept the code", count(SendEmail::$outbox) === 1 && user($u(1))['pendingEmailAuth'] === $code1);

// 5. A resend: new code, the old one dead
ageRequests($u(1));
[$code, $j] = patch($u(1), $site, array('email' => 'alice@new-home.example'));
$code2 = $j['pending_email_auth'] ?? null;
check("resend after the minute: 200, a new code, a second mail", $code === 200 && is_string($code2) && $code2 !== $code1 && count(mailTo('alice@new-home.example')) === 2);
check("audit: the resend is its own row, noted", lastAudit($u(1), 'pendingEmail')['note'] === 'resent');
[$code, $j] = confirm($code1, $site, $u(1));
check("the replaced code is refused (400) and changes nothing", $code === 400 && user($u(1))['email'] === 'alice@brewery-a.example' && user($u(1))['pendingEmailAuth'] === $code2);

// 6. Who may confirm, and as whom
[$code, $j] = confirm($code2, $k(1), $u(1));
check("confirm with the account's own key: 403 (admin callers only)", $code === 403 && errorCount(388) === 1);
[$code, $j] = confirm($code2, $site);
check("confirm with no user_id: 403", $code === 403 && errorCount(386) === 1);
[$code, $j] = confirm($code2, $site, $u(2));
check("confirm signed in as another account: 403, still pending", $code === 403 && user($u(1))['pendingEmailAuth'] === $code2 && user($u(1))['email'] === 'alice@brewery-a.example');
[$code, $j] = confirm('', $site, $u(1));
check("confirm with no code: 400", $code === 400 && errorCount(383) === 1);

// 7. Cancel: PATCH the address the account already has
[$code, $j] = patch($u(1), $site, array('email' => ' ALICE@brewery-a.example '));
check("cancel: 200, pending cleared, email as it was", $code === 200 && $j['pending_email'] === null && $j['pending_email_sent'] === null && $j['email'] === 'alice@brewery-a.example');
check("audit: cancelled", lastAudit($u(1), 'pendingEmail') === array('oldValue' => 'alice@new-home.example', 'newValue' => null, 'note' => 'cancelled'));
[$code, $j] = confirm($code2, $site, $u(1));
check("a cancelled code is refused (400)", $code === 400);
[$code, $j] = patch($u(1), $site, array('email' => 'alice@brewery-a.example'));
check("the current address with nothing pending: a 200 that does nothing", $code === 200 && $j['pending_email'] === null);

// 8. Expiry
ageRequests($u(1));
[$code, $j] = patch($u(1), $site, array('email' => 'alice@new-home.example'));
$code3 = $j['pending_email_auth'];
$admin->query("UPDATE users SET pendingEmailSent = " . (time() - 86401) . " WHERE id='{$u(1)}'");
[$code, $j] = confirm($code3, $site, $u(1));
check("a code older than 24 hours: 400, and the request is cleared", $code === 400 && strpos($j['error_msg'], 'expired') !== false && user($u(1))['pendingEmail'] === null && errorCount(385) === 1);
check("audit: expired", lastAudit($u(1), 'pendingEmail')['note'] === 'expired');

// 9. The confirmation
ageRequests($u(1));
[$code, $j] = patch($u(1), $site, array('email' => 'alice@new-home.example'));
$code4 = $j['pending_email_auth'];
$admin->query("UPDATE users SET pendingEmailSent = " . (time() - 86000) . " WHERE id='{$u(1)}'");   // inside the day
TestUsers::$stripeCalls = array();
[$code, $j] = confirm($code4, $site, $u(1));
check("confirm: 200 with the user object", $code === 200 && array_keys($j) === $userKeys);
check("confirm: email is the new address, verified, nothing pending", $j['email'] === 'alice@new-home.example' && $j['email_verified'] === true && $j['pending_email'] === null && $j['pending_email_auth'] === null && $j['pending_email_sent'] === null);
check("confirm: SAME key, billing on, cap and limit as they were", keyRow($u(1)) === $keyBefore);
check("confirm: the month's usage still belongs to the key", one("SELECT count FROM api_usage WHERE apiKey='{$k(1)}' AND year=$Y AND month=$M")['count'] === '1234');
check("confirm: a new domain ends the privileges", privilegeCount($u(1)) === 0);
[$c2, $j2] = call('GET', 'privileges', $u(1), $site);
check("confirm: staff of nothing now", $j2['data'] === array());
check("confirm: other accounts' privileges untouched", privilegeCount($u(2)) === 1);
check("confirm: the Stripe customer is given the new address", TestUsers::$stripeCalls === array(array('cus_test1', 'alice@new-home.example')));
$notice = mailTo('alice@brewery-a.example');
check("confirm: the OLD address is told, naming both", count($notice) === 1 && $notice[0]['tag'] === 'email-changed' && strpos($notice[0]['html'], 'alice@brewery-a.example') !== false && strpos($notice[0]['html'], 'alice@new-home.example') !== false && strpos($notice[0]['html'], '##') === false);
check("audit: email old -> new, confirmed", lastAudit($u(1), 'email') === array('oldValue' => 'alice@brewery-a.example', 'newValue' => 'alice@new-home.example', 'note' => 'confirmed'));
check("audit: the privilege is recorded as cleared", one("SELECT COUNT(*) AS n FROM audit_log WHERE entityType='privilege' AND entityID='{$u(1)}' AND oldValue='{$b(1)}' AND newValue IS NULL")['n'] === '1');
check("audit: no row carries a code", one("SELECT COUNT(*) AS n FROM audit_log WHERE oldValue IN ('$code1','$code2','$code3','$code4') OR newValue IN ('$code1','$code2','$code3','$code4')")['n'] === '0');
[$code, $j] = confirm($code4, $site, $u(1));
check("a second click on the same link: 400", $code === 400 && errorCount(384) >= 1);
$login = new Users();
check("sign-in: the new address works, the old one does not", $login->login('alice@new-home.example', 'correct horse battery') === true && (new Users())->login('alice@brewery-a.example', 'correct horse battery') === false);

// 10. A same-domain change is verified too, and keeps the privileges
$key2 = keyRow($u(2));
[$code, $j] = patch($u(2), $k(2), array('email' => 'second@example.com'));
check("same domain, own key: pending, not applied, and no code in the response", $code === 200 && $j['email'] === 'user2@example.com' && $j['pending_email'] === 'second@example.com' && $j['pending_email_auth'] === null);
$code5 = user($u(2))['pendingEmailAuth'];
[$code, $j] = confirm($code5, $site, $u(2));
check("same domain: confirmed, key and privileges kept", $code === 200 && $j['email'] === 'second@example.com' && keyRow($u(2)) === $key2 && privilegeCount($u(2)) === 1);
check("same domain: no Stripe call for an account without a customer", count(TestUsers::$stripeCalls) === 1);

// 11. An unverified account: corrected in place, always re-mailed
[$code, $j] = patch($u(3), $site, array('email' => 'user3@example.com'));
check("unverified: the address is replaced at once, nothing pending", $code === 200 && $j['email'] === 'user3@example.com' && $j['email_verified'] === false && $j['pending_email'] === null);
check("unverified: a new sign-up code, mailed to the corrected address (same domain included)", is_string($j['email_auth']) && $j['email_auth'] !== $signupCode && count(mailTo('user3@example.com')) === 1 && mailTo('user3@example.com')[0]['tag'] === 'confirm-email' && strpos(mailTo('user3@example.com')[0]['html'], $j['email_auth']) !== false);
[$code, $j, $header] = patch($u(3), $site, array('email' => 'user3b@example.com'));
check("unverified: a second correction inside a minute is a 429", $code === 429 && strpos($header, 'Retry-After: ') === 0 && user($u(3))['email'] === 'user3@example.com');

// 12. Validation of the requested address
[$code, $j] = patch($u(4), $site, array('email' => 'second@example.com'));
check("another account's address: 400 on email", $code === 400 && $j['valid_state']['email'] === 'invalid' && user($u(4))['pendingEmail'] === null);
[$code, $j] = patch($u(4), $site, array('email' => 'someone@mailinator.com'));
check("a disposable address: 400 on email", $code === 400 && $j['valid_state']['email'] === 'invalid');
[$code, $j] = patch($u(4), $site, array('email' => array('a@example.com')));
check("a non-string email: 400, not a crash", $code === 400 && $j['valid_state']['email'] === 'invalid');
[$code, $j] = patch($u(4), $site, array('email' => null));
check("email null: 400", $code === 400);

// 13. The address is taken between the request and the click
[$code, $j] = patch($u(4), $site, array('email' => 'wanted@example.org'));
$code6 = $j['pending_email_auth'];
$admin->query("INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES ('{$u(5)}','wanted@example.org','x','User 5',1,0)");
[$code, $j] = confirm($code6, $site, $u(4));
check("taken in the meantime: 409, email unchanged, request cleared", $code === 409 && user($u(4))['email'] === 'user4@example.com' && user($u(4))['pendingEmail'] === null && errorCount(387) === 1);
check("taken in the meantime: the message says nothing changed", strpos($j['error_msg'], 'has not been changed') !== false);

// 14. A password change clears a pending change it did not make
ageRequests($u(4));
patch($u(4), $site, array('email' => 'other@example.org'));
[$code, $j] = patch($u(4), $site, array('password' => 'a different long password'));
check("password change: the pending change is cleared", $code === 200 && $j['pending_email'] === null && lastAudit($u(4), 'pendingEmail')['note'] === 'password changed');
ageRequests($u(4));
[$code, $j] = patch($u(4), $site, array('email' => 'other@example.org', 'password' => 'yet another long password'));
check("email and password in one request: the request stands", $code === 200 && $j['pending_email'] === 'other@example.org');
$resetKey = '99999999-0000-4000-8000-000000000004';
$admin->query("UPDATE users SET passwordResetKey='$resetKey', passwordResetSent=" . time() . " WHERE id='{$u(4)}'");
[$code, $j] = call('POST', 'password-reset', $resetKey, $site, array('password' => 'reset to this long password'));
check("password reset: 204 and the pending change is cleared", $code === 204 && user($u(4))['pendingEmail'] === null && lastAudit($u(4), 'pendingEmail')['note'] === 'password reset');

// 15. Five a day
$admin->query("DELETE FROM audit_log WHERE entityType='user' AND entityID='{$u(4)}'");
for($i = 1; $i <= 5; $i++){
    $admin->query("INSERT INTO audit_log (entityType,entityID,action,field,newValue,source,changedAt) VALUES ('user','{$u(4)}','set','pendingEmail','x$i@example.org','api'," . (time() - 3600 * $i) . ")");
}
$sent = count(SendEmail::$outbox);
[$code, $j, $header] = patch($u(4), $site, array('email' => 'sixth@example.org'));
check("the sixth mail in 24 hours: 429, Retry-After under a day, nothing sent", $code === 429 && preg_match('/^Retry-After: (\d+)$/', $header, $m) === 1 && $m[1] > 3600 && $m[1] <= 86400 && count(SendEmail::$outbox) === $sent && user($u(4))['pendingEmail'] === null);
$admin->query("UPDATE audit_log SET changedAt = " . (time() - 90000) . " WHERE entityType='user' AND entityID='{$u(4)}' AND newValue='x5@example.org'");
[$code, $j] = patch($u(4), $site, array('email' => 'sixth@example.org'));
check("once the oldest is over a day old: allowed", $code === 200 && $j['pending_email'] === 'sixth@example.org');
$admin->query("INSERT INTO audit_log (entityType,entityID,action,field,oldValue,newValue,source,note,changedAt) VALUES ('user','{$u(6)}','set','pendingEmail','a@example.org',NULL,'api','cancelled'," . time() . ")");
for($i = 1; $i <= 4; $i++){
    $admin->query("INSERT INTO audit_log (entityType,entityID,action,field,newValue,source,changedAt) VALUES ('user','{$u(6)}','set','pendingEmail','y$i@example.org','api'," . (time() - 3600 * $i) . ")");
}
[$code, $j] = patch($u(6), $site, array('email' => 'moved@example.org'));
$code7 = $j['pending_email_auth'] ?? null;
check("a cancel is not counted toward the five", $code === 200 && is_string($code7));

// 16. Stripe failing never fails the change
TestUsers::$stripeAnswer = false;
[$code, $j] = confirm($code7, $site, $u(6));
check("Stripe update fails: the change still goes through, logged as 389", $code === 200 && $j['email'] === 'moved@example.org' && errorCount(389) === 1);
check("Stripe update fails: the old address is still told", count(mailTo('user6@example.com')) === 1);

// 17. Nothing in this flow resets verification or deletes a key
check("no account lost its verification", one("SELECT COUNT(*) AS n FROM users WHERE emailVerified=0 AND id<>'{$u(3)}'")['n'] === '0');
check("every key is the one it started as", one("SELECT COUNT(*) AS n FROM api_keys")['n'] === '5' && one("SELECT COUNT(*) AS n FROM audit_log WHERE entityType='api_key'")['n'] === '0');

$admin->query("DROP DATABASE " . DB_NAME);
echo $fails === 0 ? "\nAll checks passed.\n" : "\n$fails check(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
