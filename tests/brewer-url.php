<?php
/*--
Offline regression test for BrewerUrl — how a brewer's URL is reduced to its
host and its page, and which URLs count as "the same" brewer.

Run it with:  php tests/brewer-url.php

No database and no network: host(), path(), pageKey() and isRoot() are pure.
conflict() needs rows and is covered by tests/brewer-lead.php (local MySQL)
and the "Shared Domain" folder of the Postman suite. Add a case here before
changing the normalisation.
--*/

require_once __DIR__ . '/../classes/BrewerUrl.class.php';

$pass = 0;
$fail = 0;

function same($label, $actual, $expect){
    global $pass, $fail;
    if($actual === $expect){
        $pass++;
        printf("  ok    %s\n", $label);
    }else{
        $fail++;
        printf("  FAIL  %s\n        expected: %s\n        actual:   %s\n", $label, var_export($expect, true), var_export($actual, true));
    }
}

echo "host()\n";
same('plain host',                  BrewerUrl::host('https://northchair.com/'), 'northchair.com');
same('www. stripped',               BrewerUrl::host('https://www.northchair.com/whetstone-beer/'), 'northchair.com');
same('lowercased',                  BrewerUrl::host('https://NorthChair.COM/'), 'northchair.com');
same('scheme-less input',           BrewerUrl::host('northchair.com'), 'northchair.com');
same('scheme-less with www',        BrewerUrl::host('www.northchair.com/beer'), 'northchair.com');
same('subdomain kept',              BrewerUrl::host('https://shop.northchair.com/'), 'shop.northchair.com');
same('port ignored',                BrewerUrl::host('https://northchair.com:8443/'), 'northchair.com');
same('empty -> null',               BrewerUrl::host(''), null);
same('null -> null',                BrewerUrl::host(null), null);
// Syntax is validated upstream (filter_var in Brewer, a dot in the host in
// BrewerLead); host() only reduces. Garbage yields its first label, not null.
same('garbage yields its first label', BrewerUrl::host('not a url'), 'not');
same('whitespace trimmed',          BrewerUrl::host("  https://northchair.com/  "), 'northchair.com');

echo "path()\n";
same('root, no slash',              BrewerUrl::path('https://northchair.com'), '');
same('root, slash',                 BrewerUrl::path('https://northchair.com/'), '');
same('index.html is root',          BrewerUrl::path('https://northchair.com/index.html'), '');
same('index.htm is root',           BrewerUrl::path('https://northchair.com/index.htm'), '');
same('index.php is root',           BrewerUrl::path('https://northchair.com/index.php'), '');
same('page, trailing slash dropped', BrewerUrl::path('https://northchair.com/whetstone-beer/'), '/whetstone-beer');
same('page, no trailing slash',     BrewerUrl::path('https://northchair.com/whetstone-beer'), '/whetstone-beer');
same('page lowercased',             BrewerUrl::path('https://northchair.com/Whetstone-Beer/'), '/whetstone-beer');
same('nested page',                 BrewerUrl::path('https://northchair.com/brands/whetstone/'), '/brands/whetstone');
same('index.html inside a page is not root', BrewerUrl::path('https://northchair.com/whetstone/index.html'), '/whetstone/index.html');
same('query kept on a page',        BrewerUrl::path('https://example.com/?p=123'), '/?p=123');
same('query kept on root',          BrewerUrl::path('https://example.com?page_id=7'), '/?page_id=7');
same('fragment dropped',            BrewerUrl::path('https://northchair.com/whetstone-beer/#menu'), '/whetstone-beer');
same('fragment on root dropped',    BrewerUrl::path('https://northchair.com/#top'), '');
same('scheme-less page',            BrewerUrl::path('northchair.com/whetstone-beer'), '/whetstone-beer');

echo "pageKey()\n";
same('root key is the host',        BrewerUrl::pageKey('https://www.northchair.com/'), 'northchair.com');
same('page key',                    BrewerUrl::pageKey('https://www.NorthChair.com/Whetstone-Beer/'), 'northchair.com/whetstone-beer');
same('same page, four spellings', array_unique(array(
    BrewerUrl::pageKey('https://northchair.com/whetstone-beer/'),
    BrewerUrl::pageKey('http://www.northchair.com/whetstone-beer'),
    BrewerUrl::pageKey('https://NORTHCHAIR.com/Whetstone-Beer/#hours'),
    BrewerUrl::pageKey('northchair.com/whetstone-beer/'),
)), array('northchair.com/whetstone-beer'));
same('different pages differ',      BrewerUrl::pageKey('https://northchair.com/whetstone') === BrewerUrl::pageKey('https://northchair.com/whetstone-beer'), false);
same('query makes a different page', BrewerUrl::pageKey('https://example.com/?p=1') === BrewerUrl::pageKey('https://example.com/?p=2'), false);
same('bare word is its own host',   BrewerUrl::pageKey('nope'), 'nope');
same('empty -> null',               BrewerUrl::pageKey(''), null);

echo "isRoot()\n";
same('root',                        BrewerUrl::isRoot('https://northchair.com/'), true);
same('www root, no slash',          BrewerUrl::isRoot('http://www.northchair.com'), true);
same('index.html',                  BrewerUrl::isRoot('https://northchair.com/index.html'), true);
same('page',                        BrewerUrl::isRoot('https://northchair.com/whetstone-beer/'), false);
same('root with query is a page',   BrewerUrl::isRoot('https://example.com/?p=1'), false);
same('no host is not root',         BrewerUrl::isRoot(''), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
?>
