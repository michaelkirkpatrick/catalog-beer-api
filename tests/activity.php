<?php
/*--
Offline regression test for the pure helpers of Activity.class.php (the
admin activity report). No database, no network.

    php tests/activity.php

Covers: the endpoint family a request is counted under for the per-key mix
(list / detail / search / brewer_beer / write / other); the search term
parsed from a logged URI; the endpoint pattern (UUIDs collapsed, query cut);
and the pace projection that feeds the billing forecast (never extrapolated
from less than an hour, never past the month).
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}
define('ROOT', dirname(__DIR__) . '/public_html');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

$pass = 0;
$fail = 0;
function check($label, $expected, $actual){
    global $pass, $fail;
    if($expected === $actual){
        $pass++;
    }else{
        $fail++;
        echo "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

// family()
check('list beer', 'list', Activity::family('GET', '/beer'));
check('list brewer', 'list', Activity::family('GET', '/brewer'));
check('detail beer', 'detail', Activity::family('GET', '/beer/{id}'));
check('detail location', 'detail', Activity::family('GET', '/location/{id}'));
check('search beer', 'search', Activity::family('GET', '/beer/search'));
check('search brewer', 'search', Activity::family('GET', '/brewer/search'));
check('brewer beers', 'brewer_beer', Activity::family('GET', '/brewer/{id}/beer'));
check('brewer locations is other', 'other', Activity::family('GET', '/brewer/{id}/locations'));
check('permissions is other', 'other', Activity::family('GET', '/brewer/{id}/permissions'));
check('style search is other', 'other', Activity::family('GET', '/style/search'));
check('count is other', 'other', Activity::family('GET', '/beer/count'));
check('POST is write', 'write', Activity::family('POST', '/beer'));
check('PATCH is write', 'write', Activity::family('PATCH', '/beer/{id}'));
check('DELETE is write', 'write', Activity::family('DELETE', '/location/{id}'));

// searchTerm()
check('plus-encoded spaces', 'Westmalle Tripel', Activity::searchTerm('/beer/search?q=Westmalle+Tripel'));
check('percent-encoded spaces and count', 'Hoegaarden Wit', Activity::searchTerm('/beer/search?q=Hoegaarden%20Wit&count=5'));
check('cursor before q', 'Zzzflipflop Nonsense Beer 9000', Activity::searchTerm('/beer/search?cursor=MjU%3D&q=Zzzflipflop+Nonsense+Beer+9000'));
check('apostrophe', "Redd's Hard Apple", Activity::searchTerm("/beer/search?q=Redd%27s+Hard+Apple&count=25"));
check('unicode', 'Żywiec', Activity::searchTerm('/beer/search?q=%C5%BBywiec&count=100'));
check('inner whitespace collapsed', 'Stella Artois', Activity::searchTerm('/beer/search?q=Stella++Artois'));
check('no q', null, Activity::searchTerm('/beer/search?count=25'));
check('empty q', null, Activity::searchTerm('/beer/search?q=&count=25'));
check('no query string', null, Activity::searchTerm('/beer/search'));
check('q as array is ignored', null, Activity::searchTerm('/beer/search?q[]=x'));

// endpointPattern()
check('uuid collapsed', '/brewer/{id}/beer', Activity::endpointPattern('/brewer/2e8ae8bd-2d12-4e68-98b7-18620d474f5f/beer'));
check('query cut', '/beer/search', Activity::endpointPattern('/beer/search?q=Duvel&count=3'));
check('two ids', '/brewer/{id}/beer/{id}', Activity::endpointPattern('/brewer/2e8ae8bd-2d12-4e68-98b7-18620d474f5f/beer/8a3911c3-5c7b-4057-b77f-e392fa1a91ea'));
check('plain path', '/beer', Activity::endpointPattern('/beer'));

// projectedCount(): a 30-day month is 2,592,000 seconds
$month = 30 * 86400;
check('half the month, double the count', 2000, Activity::projectedCount(1000, 15 * 86400, $month));
check('a tenth of the month', 10000, Activity::projectedCount(1000, 3 * 86400, $month));
check('first minute is treated as an hour', 720000, Activity::projectedCount(1000, 60, $month));
check('zero count', 0, Activity::projectedCount(0, 86400, $month));
check('month over: the count itself', 1234, Activity::projectedCount(1234, $month + 5, $month));
check('exactly the month', 1234, Activity::projectedCount(1234, $month, $month));
check('zero month', 0, Activity::projectedCount(5, 10, 0));
check('rounds to an integer', 3, Activity::projectedCount(1, 10 * 86400, $month));

echo "activity: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
