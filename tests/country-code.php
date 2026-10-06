<?php
/*--
Regression test for CountryCode.class.php -- no DB, no network.

    php tests/country-code.php

What it proves: the list is the 249 assigned ISO 3166-1 alpha-2 codes, sorted,
every key two uppercase letters with a non-empty short name; normalize() trims
and uppercases, refuses an unassigned code and every non-string shape; and the
short name for US is the one the location object has always carried.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}
define('ROOT', dirname(__DIR__) . '/public_html');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

$codes = CountryCode::CODES;
$keys = array_keys($codes);
$sorted = $keys; sort($sorted);
check("249 assigned codes", count($codes) === 249);
check("keys sorted", $keys === $sorted);
check("every key is two uppercase letters", count(array_filter($keys, fn($k) => !preg_match('/^[A-Z]{2}$/', $k))) === 0);
check("every short name is non-empty", count(array_filter($codes, fn($n) => trim($n) === '')) === 0);
foreach(array('US', 'CA', 'MX', 'GB', 'DE', 'BE', 'CZ', 'JP', 'AU', 'NZ', 'ZA', 'BR') as $c){
    check("$c is assigned", CountryCode::valid($c));
}
check("US short name matches the location object", CountryCode::shortName('US') === 'United States of America');
check("normalize trims and uppercases", CountryCode::normalize(" ca\n") === 'CA');
check("normalize refuses an unassigned code", CountryCode::normalize('ZZ') === null);
check("normalize refuses three letters", CountryCode::normalize('USA') === null);
check("normalize refuses null, '', array, bool", CountryCode::normalize(null) === null && CountryCode::normalize('') === null && CountryCode::normalize(array('US')) === null && CountryCode::normalize(true) === null);
check("valid() is case-sensitive on the stored form", CountryCode::valid('us') === false && CountryCode::valid('US') === true);
check("shortName of an unknown code is null", CountryCode::shortName('XX') === null);

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
?>
