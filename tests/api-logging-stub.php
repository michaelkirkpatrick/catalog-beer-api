<?php
/*--
Regression test for apiLogging::responseStub() -- no DB, no network.

    php tests/api-logging-stub.php

What it proves: a successful write's logged response keeps exactly what
Activity.class.php reads back out of it (id, name, brewer.id, brewer.name)
and nothing else; a response with none of those keys (a DELETE, a bare
array, a non-JSON string) is stored as '' rather than "[]"; and the stub is
JSON Activity's json_decode() can read.
--*/
if(php_sapi_name() !== 'cli'){ exit(1); }
define('ROOT', dirname(__DIR__) . '/public_html');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

// A POST /beer response, as index.php hands it over (json_encode'd string)
$beer = json_encode(array(
    'id' => '6b1f0d2e-1111-4a2b-9c3d-000000000001',
    'object' => 'beer',
    'name' => 'Test IPA',
    'style' => 'India Pale Ale',
    'description' => str_repeat('hoppy ', 200),
    'abv' => 6.5,
    'brewer' => array('id' => '6b1f0d2e-2222-4a2b-9c3d-000000000002', 'name' => 'Test Brewing', 'url' => 'https://example.com', 'description' => 'long'),
    'valid_state' => array('name' => 'valid'),
));
$stub = apiLogging::responseStub($beer);
$decoded = json_decode($stub, true);
check("beer response decodes", is_array($decoded));
check("keeps id and name", $decoded['id'] === '6b1f0d2e-1111-4a2b-9c3d-000000000001' && $decoded['name'] === 'Test IPA');
check("keeps brewer id and name", $decoded['brewer'] === array('id' => '6b1f0d2e-2222-4a2b-9c3d-000000000002', 'name' => 'Test Brewing'));
check("drops everything else", array_keys($decoded) === array('id', 'name', 'brewer'));
check("stub is small", strlen($stub) < 200);

// A brewer response has no nested brewer
$brewer = json_encode(array('id' => 'b', 'object' => 'brewer', 'name' => 'Solo', 'description' => 'x'));
check("brewer response: id and name only", json_decode(apiLogging::responseStub($brewer), true) === array('id' => 'b', 'name' => 'Solo'));

// Location responses may have a null name; isset() skips it, the id stays
$location = json_encode(array('id' => 'l', 'name' => null, 'brewer' => array('id' => 'b', 'name' => 'Parent')));
check("null name is dropped, brewer kept", json_decode(apiLogging::responseStub($location), true) === array('id' => 'l', 'brewer' => array('id' => 'b', 'name' => 'Parent')));

// Nothing Activity reads -> empty column, not "[]"
check("DELETE (empty response) -> ''", apiLogging::responseStub('') === '');
check("null -> ''", apiLogging::responseStub(null) === '');
check("object without id/name -> ''", apiLogging::responseStub('{"status":"ok"}') === '');
check("non-JSON -> ''", apiLogging::responseStub('not json') === '');
check("JSON list -> ''", apiLogging::responseStub('[1,2,3]') === '');

// An array (not a string) is accepted too
check("array input", json_decode(apiLogging::responseStub(array('id' => 'x', 'name' => 'Y')), true) === array('id' => 'x', 'name' => 'Y'));

echo "\n" . ($fails ? "$fails FAILED" : "all passed") . "\n";
exit($fails ? 1 : 0);
