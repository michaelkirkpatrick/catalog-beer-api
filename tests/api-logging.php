<?php
/*--
Regression test for what apiLogging keeps per request -- no DB, no network.

    php tests/api-logging.php

What it proves: a successful write stores neither body nor response (the
audit_log snapshot is the record); a refused write keeps both, with
passwords redacted; a GET stores neither; a password-reset or verify-email
token is cut out of the logged URI and an overlong URI is cut to the column;
the user agent is cleaned, cut to the column and null when absent; the
duration is whole milliseconds from REQUEST_TIME_FLOAT and null under the CLI.
--*/
if(php_sapi_name() !== 'cli'){ exit(1); }
define('ROOT', dirname(__DIR__) . '/public_html');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

$body = json_encode(array('name' => 'Test IPA', 'password' => 'hunter2', 'nested' => array('new_password' => 'x')));
$entity = json_encode(array('id' => 'abc', 'object' => 'beer', 'name' => 'Test IPA', 'description' => str_repeat('hoppy ', 200)));
$refusal = json_encode(array('error' => true, 'error_msg' => 'name is required', 'valid_msg' => array('password_reset_key' => 'bad')));

// Body
check("GET: no body", apiLogging::loggedBody('GET', 200, '{"q":1}') === '');
check("POST 201: no body (audit_log holds the result)", apiLogging::loggedBody('POST', 201, $body) === '');
check("PATCH 200: no body", apiLogging::loggedBody('PATCH', 200, $body) === '');
check("DELETE 204: no body", apiLogging::loggedBody('DELETE', 204, '') === '');
$kept = json_decode(apiLogging::loggedBody('POST', 400, $body), true);
check("POST 400: body kept, passwords redacted at every depth", $kept['name'] === 'Test IPA' && $kept['password'] === '[REDACTED]' && $kept['nested']['new_password'] === '[REDACTED]');
check("POST 402 (quota): body kept", apiLogging::loggedBody('POST', 402, $body) !== '');
check("non-JSON body on a refusal is kept as sent", apiLogging::loggedBody('POST', 400, 'not json') === 'not json');
check("empty body -> ''", apiLogging::loggedBody('POST', 400, '') === '' && apiLogging::loggedBody('POST', 400, null) === '');

// Response
check("GET: no response", apiLogging::loggedResponse('GET', 200, $entity) === '');
check("POST 201: no response (nothing reads it since /activity moved)", apiLogging::loggedResponse('POST', 201, $entity) === '');
check("PUT 200: no response", apiLogging::loggedResponse('PUT', 200, $entity) === '');
$kept = json_decode(apiLogging::loggedResponse('POST', 400, $refusal), true);
check("POST 400: error body kept, token key redacted", $kept['error_msg'] === 'name is required' && $kept['valid_msg']['password_reset_key'] === '[REDACTED]');
check("POST 500: error body kept", apiLogging::loggedResponse('POST', 500, '{"error":true}') !== '');

// URI
check("password-reset token redacted", apiLogging::loggedUri('/users/password-reset/0f3c2a1b-1111-4222-8333-444444444444') === '/users/password-reset/[REDACTED]');
check("verify-email token redacted, query kept", apiLogging::loggedUri('/users/verify-email/0f3c2a1b-1111-4222-8333-444444444444?x=1') === '/users/verify-email/[REDACTED]?x=1');
check("confirm-email token redacted", apiLogging::loggedUri('/users/confirm-email/0f3c2a1b-1111-4222-8333-444444444444') === '/users/confirm-email/[REDACTED]');
check("reset-password by user id is NOT a token: untouched", apiLogging::loggedUri('/users/0f3c2a1b-1111-4222-8333-444444444444/reset-password') === '/users/0f3c2a1b-1111-4222-8333-444444444444/reset-password');
check("ordinary URI untouched", apiLogging::loggedUri('/brewer/search?q=stone&count=5') === '/brewer/search?q=stone&count=5');
$long = '/beer/search?q=' . str_repeat('a', 400);
check("overlong URI cut to 255 bytes", strlen(apiLogging::loggedUri($long)) === 255);
check("cut lands on a character boundary", mb_check_encoding(apiLogging::loggedUri('/brewer/search?q=' . str_repeat('ü', 200)), 'UTF-8'));

// User agent
check("absent UA -> null", apiLogging::userAgent(null) === null);
check("blank UA -> null", apiLogging::userAgent('   ') === null);
check("UA kept, trimmed", apiLogging::userAgent('  curl/8.4.0 ') === 'curl/8.4.0');
check("control characters replaced", apiLogging::userAgent("Mozilla/5.0\r\nX: y") === 'Mozilla/5.0 X: y');
check("overlong UA cut to 255 bytes", strlen(apiLogging::userAgent(str_repeat('x', 1000))) === 255);

// Duration
check("CLI (no REQUEST_TIME_FLOAT) -> null", apiLogging::durationMs(null) === null);
check("non-numeric -> null", apiLogging::durationMs('x') === null);
check("37 ms", apiLogging::durationMs(1000.000, 1000.037) === 37);
check("rounds to whole ms", apiLogging::durationMs(1000.0, 1000.0006) === 1);
check("never negative", apiLogging::durationMs(2000.0, 1999.0) === 0);

echo "\n" . ($fails ? "$fails FAILED" : "all passed") . "\n";
exit($fails ? 1 : 0);
