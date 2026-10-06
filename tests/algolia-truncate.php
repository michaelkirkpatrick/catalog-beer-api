<?php
/*--
Regression test for Algolia::truncateProse() -- no DB, no network.

    php tests/algolia-truncate.php

What it proves: text within the cap passes through untouched (null and ''
too); an over-long text is cut under the cap, never mid-UTF-8-sequence, and
at a word boundary when one falls in the last quarter of the window; and the
catalog's own maximum description (65,535 bytes) comes out small enough to
leave an Algolia record under 10 KB.
--*/
if(php_sapi_name() !== 'cli'){ exit(1); }
define('ROOT', dirname(__DIR__) . '/public_html');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;
$MAX = Algolia::PROSE_MAX_BYTES;

check("null and '' pass through", Algolia::truncateProse(null) === null && Algolia::truncateProse('') === '');
$short = str_repeat('a ', 100);
check("text within the cap is unchanged", Algolia::truncateProse($short) === $short);
$exact = str_repeat('x', $MAX);
check("text exactly at the cap is unchanged", Algolia::truncateProse($exact) === $exact);

$words = trim(str_repeat('lorem ipsum dolor ', 400));   // ~7,200 bytes
$cut = Algolia::truncateProse($words);
check("over-long text is cut under the cap", strlen($cut) <= $MAX && strlen($cut) > 0);
check("cut lands on a word boundary", preg_match('/\b(lorem|ipsum|dolor)$/', $cut) === 1);
check("cut is a prefix of the original", strpos($words, $cut) === 0);

$multibyte = str_repeat('Brauerei Zürich — Weißbier ', 200);   // ~5,600 bytes, 2- and 3-byte chars
$cut = Algolia::truncateProse($multibyte);
check("multibyte text stays valid UTF-8 after the cut", preg_match('//u', $cut) === 1 && strlen($cut) <= $MAX);
check("multibyte cut is a prefix of the original", strpos($multibyte, $cut) === 0);

$nospace = str_repeat('x', $MAX + 500);
$cut = Algolia::truncateProse($nospace);
check("text with no whitespace is cut at the byte cap", strlen($cut) === $MAX);

$emoji = str_repeat('a', $MAX - 2) . '🍺🍺🍺';   // 4-byte char straddles the cap
$cut = Algolia::truncateProse($emoji);
check("a 4-byte character straddling the cap is dropped whole", preg_match('//u', $cut) === 1 && strlen($cut) === $MAX - 2);

$max = str_repeat('word ', 13107);   // 65,535 bytes, the catalog's description limit
$cut = Algolia::truncateProse($max);
check("the catalog's maximum description comes out under the cap", strlen($cut) <= $MAX);
check("custom cap is honoured", strlen(Algolia::truncateProse($words, 100)) <= 100);

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
?>
