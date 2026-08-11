<?php
/*--
Offline regression test for UrlCheck::nameInText() and its stripOwnDomain()
helper — the "does this page name the brewery it claims to be" test that
decides whether an `ok` row reaches human review.

Run it with:  php tests/url-check-name.php

No database and no network: every case is a literal. Follows the pattern
proven by tests/text-input.php and tests/address-parse.php.

What the cases encode (2026-08-10):

- A page's mentions of its OWN domain are not evidence. The domain comes from
  the URL under test, so it always agrees with the brewer's stored URL; letting
  it satisfy the name match is circular. Next Door Brewing's domain was rebuilt
  in place as a content farm whose title echoed "nextdoorbrewing.com", the
  substring matcher found "next" and "door" inside it, and the row scored `ok`
  — which is a bucket nobody reads.
- The bare domain label is deliberately NOT stripped. For a one-word brand the
  label is the name, so stripping it would flag every Ninkasi-shaped brewery's
  real site as name-absent and drown the review queue.
- A page emptied by the strip returns false, not null. false is the reviewed
  "ok but name absent" bucket in cron/check-urls.php; null is not.

Add a case here before changing the matcher.
--*/

require_once __DIR__ . '/../classes/UrlCheck.class.php';

$pass = 0;
$fail = 0;
$urlCheck = new UrlCheck();

function describe($value){
    if($value === null){ return 'null'; }
    if($value === true){ return 'true'; }
    if($value === false){ return 'false'; }
    return "'$value'";
}

function assertName($label, $brewerName, $text, $ownDomain, $expected){
    global $pass, $fail, $urlCheck;

    $actual = $urlCheck->nameInText($brewerName, $text, $ownDomain);

    if($actual === $expected){
        $pass++;
        printf("  ok    %s\n", $label);
    }else{
        $fail++;
        printf("  FAIL  %s\n        expected: %s\n        actual:   %s\n",
            $label, describe($expected), describe($actual));
    }
}

function assertStrip($label, $text, $domain, $expected){
    global $pass, $fail, $urlCheck;

    // Whitespace is collapsed before comparing: the strip leaves a space
    // behind so words can't fuse, and how many is not the point of the test.
    $actual = trim(preg_replace('/\s+/', ' ', $urlCheck->stripOwnDomain($text, $domain)));

    if($actual === $expected){
        $pass++;
        printf("  ok    %s\n", $label);
    }else{
        $fail++;
        printf("  FAIL  %s\n        expected: %s\n        actual:   %s\n",
            $label, describe($expected), describe($actual));
    }
}

echo "\nThe gap this fix closes — a content farm titled with the domain it squats\n";
// The real 2026-08-08 finding. Both brand tokens live inside the domain string
// and nowhere else on the page.
assertName('Next Door: domain-echoing title no longer matches',
    'Next Door Brewing',
    'nextdoorbrewing.com - Home Improvement Tips, Reviews and Guides',
    'nextdoorbrewing.com',
    false);
assertName('Next Door: www. prefix stripped too',
    'Next Door Brewing',
    'Welcome to www.nextdoorbrewing.com | Insurance Quotes',
    'nextdoorbrewing.com',
    false);
assertName('Next Door: real page still matches on prose',
    'Next Door Brewing',
    'Next Door Brewing Company | Madison, WI. Visit us at nextdoorbrewing.com',
    'nextdoorbrewing.com',
    true);

echo "\nThe false negative to avoid — a one-word brand whose label is its name\n";
// Stripping the bare label would take "Ninkasi" out of the prose as well and
// flag a perfectly healthy site. Only the hostname form goes.
assertName('Ninkasi: prose name survives the strip of ninkasi.com',
    'Ninkasi Brewing Company',
    'Ninkasi Brewing Company — Eugene, Oregon. ninkasi.com',
    'ninkasi.com',
    true);
assertName('Ninkasi: name in a heading with no domain mention at all',
    'Ninkasi Brewing Company',
    'Ninkasi Brewing Company. Beers, taproom hours, and events.',
    'ninkasi.com',
    true);

echo "\nHostname forms that must be stripped\n";
assertStrip('bare domain',           'Visit nextdoorbrewing.com today',     'nextdoorbrewing.com', 'Visit today');
assertStrip('www prefix',            'www.nextdoorbrewing.com',             'nextdoorbrewing.com', '');
assertStrip('deeper subdomain',      'shop.eu.nextdoorbrewing.com rocks',   'nextdoorbrewing.com', 'rocks');
assertStrip('full URL with path',    'https://www.nextdoorbrewing.com/beer','nextdoorbrewing.com', 'https:// /beer');
assertStrip('email address',         'info@nextdoorbrewing.com',            'nextdoorbrewing.com', 'info@');
assertStrip('mixed case',            'NextDoorBrewing.COM',                 'nextdoorbrewing.com', '');
assertStrip('multi-part TLD',        'Order at hopyard.co.uk now',          'hopyard.co.uk',       'Order at now');
// Each occurrence goes, and each takes its own subdomain with it — same rule
// as the www. case above, so "a." and "c." are consumed rather than orphaned.
assertStrip('every occurrence goes', 'a.b.com and b.com and c.b.com',       'b.com',               'and and');

echo "\nForms that must survive\n";
assertStrip('bare label without TLD', 'Nextdoorbrewing is open',        'nextdoorbrewing.com', 'Nextdoorbrewing is open');
assertStrip('a longer label ending in the domain', 'notnextdoorbrewing.com', 'nextdoorbrewing.com', 'notnextdoorbrewing.com');
assertStrip('a different domain',     'See perennialbeer.com',          'nextdoorbrewing.com', 'See perennialbeer.com');
assertStrip('a longer TLD',           'nextdoorbrewing.company',        'nextdoorbrewing.com', 'nextdoorbrewing.company');
assertStrip('empty domain is a no-op','nextdoorbrewing.com',            '',                    'nextdoorbrewing.com');

echo "\nThe three-valued contract\n";
// null means "no evidence available" and never reaches review; false means
// "alive, name absent" and does. A page whose whole text was its own address
// has to be reviewable, so the emptiness test runs before the strip.
assertName('no page text at all is null',
    'Next Door Brewing', '   ', 'nextdoorbrewing.com', null);
assertName('name with no distinctive tokens is null',
    'The Brew Co', 'Some page about anything', 'thebrewco.com', null);
assertName('text that is nothing but the domain is false, not null',
    'Next Door Brewing', 'nextdoorbrewing.com', 'nextdoorbrewing.com', false);
assertName('short tokens are dropped as before',
    'Ace Beer', 'Nothing relevant here', 'acebeer.com', null);

echo "\nUnchanged behaviour without a domain argument\n";
// The default keeps every existing caller and any ad-hoc use working.
assertName('no domain passed, token present', 'Perennial Artisan Ales',
    'Perennial Artisan Ales', '', true);
assertName('no domain passed, token absent',  'Perennial Artisan Ales',
    'Unrelated page text', '', false);

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
