<?php
/*
Offline regression test for Address::parseValidatedAddress() — the
street/unit parser over Google Address Validation responses.

    php tests/address-parse.php         # table + pass/fail, exit 1 on failure
    php tests/address-parse.php -v      # also dump each fixture's components

No network, no database: fixtures/google-address-validation.json holds real
captured API responses (28 shape probes from 2026-08-05 plus rows promoted
from the full 587-address production audit — see scratch/address-audit);
fixtures/google-address-validation-intl.json holds 22 international responses
captured 2026-10-13 from the staging box (the key is IP-restricted).

When Google or the parser changes behaviour, re-capture with the audit script
and update the expectations here BY HAND — every expected value in this table
has been human-verified, which is what makes a failure meaningful. Don't
regenerate them mechanically from parser output.

Expectations encode the 2026-08-05 decisions:
- address2 from Google addressComponents (street_number + route / post_box),
  AP-abbreviated for display ("4th St NW", "I-35"); CASS renders road
  DESIGNATIONS ("FM 423", "W Highway 92") and dual-named roads
- address1 exists per Google's subpremise, rendered per CASS ("BLDG 12");
  unconfirmed unit on unconfirmed route = parse artifact, dropped
*/

if(php_sapi_name() !== 'cli'){ exit('CLI only'); }
require_once(__DIR__ . '/../public_html/classes/Address.class.php');

$verbose = in_array('-v', $argv);

// label-prefix => [expected address2, expected address1]
$expected = array(
    'A1' => array('6450 N Desert Blvd',                 'Bldg 12'),
    'A2' => array('6450 N Desert Blvd',                 'Bldg 12'),
    'A3' => array('6450 N Desert Blvd',                 'Bldg 12'),
    'A4' => array('6450 N Desert Blvd',                 ''),
    'B1' => array('2200 S I-35 Frontage Rd',            'B1'),
    'B2' => array('2200 S I-35 Frontage Rd',            '#B1'),   // client wrote #, Google kept it
    'B3' => array('2200 S I-35 Frontage Rd',            'B1'),
    'C1' => array('2707 Commercial Center Blvd',        'Ste K100'),
    'C2' => array('2707 Commercial Center Blvd',        'Ste K100'),
    'C3' => array('3236 La Orilla Rd NW',               '# 1'),
    'D1' => array('1270 Lincoln Ave',                   'Ste 800'),
    'D2' => array('2215 India St',                      ''),
    'D3' => array('14356 Woodinville Redmond Rd NE',    ''),
    'D4' => array('6890 Paoli Rd',                      ''),
    'E1' => array('PO Box 1234',                        ''),
    'E2' => array('1 World Trade Center',               'Fl 45'),   // Center deliberately unabbreviated
    'E3' => array('100 N Front St',                     ''),
    'E4' => array('2200 S I-35 Frontage Rd',            'B1'),      // business name on line 1
    'E5' => array('1000 N Broadway',                    'Bldg B'),
    'F1' => array('1000 N Broadway',                    'Unit 5'),
    'F2' => array('1270 Lincoln Ave',                   'Ste 800'),
    'F3' => array('123 1/2 Main St',                    ''),
    'F4' => array('2707 Commercial Center Blvd',        'Ste A-1'),
    'F6' => array('725 4th St',                         'Taproom'),
    'F8' => array('6450 N Desert Blvd',                 'Bldg 12'),
    'F9' => array('1000 Front St',                      ''),
    'G1' => array('2707 Commercial Center Blvd',        'Ste K100'),
    'G2' => array('6450 N Desert Blvd',                 'Bldg 12'),
    'H1' => array('9322 State Route 414',               ''),        // Google route = "New York 414"
    'H2' => array('1326 W Highway 92',                  'Ste 8'),   // "West Arizona 92" + unit folded in CASS line
    'H3' => array('1660 FM 423',                        ''),        // "Farm to Market Road 423"
    'H4' => array('1328 Pine View Rd',                  ''),        // "State Road 656" — CASS keeps the local name
    'H5' => array('6621 State Route 5 And 20',          ''),        // unconfirmed parse; "And 20" is NOT a unit
    'H6' => array('N71W13040 Appleton Ave',             ''),        // grid number cased per CASS; Google's added W loses (skeleton)
    'H7' => array('680 North Ave NE',                   ''),        // directional IS the name — stays "North"
    'I1' => array('5401 Linda Vista Rd',                'Ste 406'), // Google reports NO subpremise; CASS standardized it
    'I2' => array('2215 India St',                      ''),        // Google route "India" (truncated, CONFIRMED); CASS has "ST"

    // §I22 family, captured 2026-08-17 — one fixture per defect shape plus
    // its passing shape. See OPEN-ITEMS.md §I22 in catalog-beer-cleanup.
    'I14a' => array('37 Paynes Way',        'Ste 1'),   // zero-padded "Suite 001" anchors to CASS's STE 1
    'I15b' => array('1158 Broadway',        ''),        // suffixless street, locality form — clean
    'I16a' => array('1 Hemsley Pl',         ''),        // street CASS doesn't know: passthrough, no invented unit
    'I16b' => array('1 Helmsley Pl',        ''),        // USPS spelling, ROUTE granularity + dpv=Y: CASS line wholesale
    'I20a' => array('876 E Falmouth Hwy',   ''),
    'I20b' => array('876 E Falmouth Hwy',   ''),
    'I21b' => array('5054 N US 31',         ''),        // rural highway, zip5 form — clean
    'I17a' => array('2402 Waynoka Rd',      ''),
    'I17b' => array('2402 Waynoka Rd',      ''),
    'I17c' => array('2937 41st Ave',        ''),
    'I17d' => array('2937 41st Ave',        ''),
    'I17e' => array('501 Willow Blvd',      ''),
);

// label-prefix => expected deriveCity() output (asserted only where listed).
// The fixture's own 'city' field is passed as the client-sent city.
$expectedCity = array(
    'I14a' => 'Asheville',
    'I15b' => 'Seattle',
    'I16a' => 'Exeter',
    'I16b' => 'Exeter',
    'I20a' => 'East Falmouth',      // CASS is right; Google's municipality "Falmouth" must NOT win (I20)
    'I20b' => 'East Falmouth',
    'I21b' => 'Peru',
    'I17a' => 'Colorado Springs',   // every source echoes "Colorado Spgs" — vocabulary expansion
    'I17b' => 'Colorado Springs',   // sent city is the full-form witness
    'I17c' => 'Long Island City',   // vocabulary expansion (IS -> Island)
    'I17d' => 'Long Island City',
    'I17e' => 'Willow Springs',     // Google's locality is the full-form witness
    'A1'   => 'El Paso',            // legacy lock: short names pass through untouched
    'D4'   => 'Belleville',         // legacy lock: CASS unconfirmed -> Google's locality (Paoli case)
);

// label-prefix => expected relocationConflict() type. Fixtures listed here
// must be REJECTED; every other fixture must produce no conflict.
$expectedConflict = array(
    'I15a' => 'street',             // "1158 Broadway" -> 909 E Union St, #1158 as unit
    'I21a' => 'city',               // Peru IN -> Columbus IN, 100 miles south
);

$rows = json_decode(file_get_contents(__DIR__ . '/fixtures/google-address-validation.json'), true);
$pass = 0; $fail = 0;
foreach($rows as $r){
    $prefix = explode(' ', $r['label'])[0];
    $conflict = Address::relocationConflict($r['result'], $r['in'][0] ?? '');

    if(isset($expectedConflict[$prefix])){
        $ok = $conflict !== null && $conflict['type'] === $expectedConflict[$prefix];
        if($ok){ $pass++; } else { $fail++; }
        printf("%s %-58s reject=%s\n", $ok ? 'PASS' : 'FAIL', $r['label'],
            $conflict === null ? 'NONE (expected ' . $expectedConflict[$prefix] . ')'
                : $conflict['type'] . " ('" . $conflict['found'] . "')");
        continue;
    }

    $parsed = Address::parseValidatedAddress($r['result']);
    $exp = $expected[$prefix] ?? null;
    $ok = $exp !== null && $parsed['address2'] === $exp[0] && $parsed['address1'] === $exp[1];
    $problems = array();
    if($conflict !== null){
        $ok = false;
        $problems[] = 'unexpected reject: ' . $conflict['type'] . " ('" . $conflict['found'] . "')";
    }
    if(isset($expectedCity[$prefix])){
        $city = Address::deriveCity($r['result'], $r['city'] ?? '');
        if($city !== $expectedCity[$prefix]){
            $ok = false;
            $problems[] = "city='$city' expected '{$expectedCity[$prefix]}'";
        }
    }
    if($ok){ $pass++; } else { $fail++; }
    printf("%s %-58s a2=%-38s a1=%s\n", $ok ? 'PASS' : 'FAIL', $r['label'],
        "'" . $parsed['address2'] . "'", "'" . $parsed['address1'] . "'");
    if(!$ok && $exp !== null){
        printf("     %-58s a2=%-38s a1=%s\n", 'expected:', "'{$exp[0]}'", "'{$exp[1]}'");
    }
    foreach($problems as $p){
        printf("     %s\n", $p);
    }
    if($verbose){
        foreach(($r['result']['address']['addressComponents'] ?? array()) as $c){
            printf("       %-26s %s\n", $c['componentType'], json_encode($c['componentName']['text'] ?? ''));
        }
    }
}

/*--
International fixtures (fixtures/google-address-validation-intl.json, 22
real responses captured 2026-10-13 from the staging box). Every expectation
below was checked by hand against Google's response; the parser is simple
on purpose, and these are what keep it that way.

  label => [line1, line2, locality, postal_code, formatted lines, admin areas]
  or    => 'REJECT' when the acceptance gate must refuse it (no CASS second
           opinion abroad: ROUTE and OTHER granularity never store)
--*/
$expectedIntl = array(
    'BE1' => array('Donkerstraat 12', '', 'Westvleteren', '8640', array('Donkerstraat 12', '8640 Westvleteren'), array()),   // sublocality IS the postal locality
    'BE2' => array('Donkerstraat 12', '', 'Vleteren', '8640', array('Donkerstraat 12', '8640 Vleteren'), array()),           // locality inferred from the postal code
    'BE3' => array('Donkerstraat 12', '', 'Vleteren', '8640', array('Donkerstraat 12', '8640 Vleteren'), array()),           // postal code inferred from the locality
    'BE4' => array('Rue Gheude 56', '', 'Anderlecht', '1070', array('Rue Gheude 56', '1070 Anderlecht'), array()),           // Brussels: no province component at all
    'BE5' => array('Rue Gheude 56', '', 'Brussel', '1070', array('Rue Gheude 56', '1070 Brussel'), array()),                 // unconfirmed locality at PREMISE still stores
    'BE6' => array('Meir 85', 'bus 3', 'Antwerpen', '2000', array('Meir 85/bus 3', '2000 Antwerpen'), array()),              // unit cut out of the folded line; formatted keeps Google's fold
    'BE7' => 'REJECT',                                                                                                       // OTHER: nonexistent street
    'NL1' => array('Funenkade 7', '', 'Amsterdam', '1018 AL', array('Funenkade 7', '1018 AL Amsterdam'), array()),
    'NL2' => array('Funenkade 7', '', 'Amsterdam', '1018 AL', array('Funenkade 7', '1018 AL Amsterdam'), array()),          // "1018al" normalised by Google
    'DE1' => array('Alte Akademie 2', '', 'Freising', '85354', array('Alte Akademie 2', '85354 Freising'), array()),
    'DE2' => array('Köpenicker Straße 70', '', 'Berlin', '10179', array('Köpenicker Straße 70', '10179 Berlin'), array()), // postal "Str." expanded from the route
    'FR1' => array("28 Rue de la Goutte d'Or", '', 'Paris', '75018', array("28 Rue de la Goutte d'Or", '75018 Paris'), array()),
    'GB1' => 'REJECT',                                                                                                       // ROUTE: unnumbered street (Fuller's)
    'GB2' => array('94 Rose Street', '', 'Edinburgh', 'EH2 3DT', array('94 Rose Street', 'Edinburgh EH2 3DT'), array()),   // PREMISE_PROXIMITY accepted; postal_town is the locality
    'LU1' => array('2 Boulevard J.F. Kennedy', '', 'Bascharage', '4930', array('2 Boulevard J.F. Kennedy', '4930 Bascharage'), array()), // "Bd" expanded; "L-4930" -> "4930"
    'IE1' => 'REJECT',                                                                                                       // OTHER: premise name, no street (Guinness)
    'CA1' => array('80 Rue des Carrières', '', 'Chambly', 'J3L 2H6', array('80 Rue des Carrières', 'Chambly, QC J3L 2H6'), array('QC')), // locality line rejoined
    'AU1' => array('24 Bath Street', '', 'Abbotsford', '3067', array('24 Bath Street', 'Abbotsford VIC 3067'), array('VIC')),
    'ES1' => array('Carrer de Pujades, 95', '', 'Barcelona', '08005', array('Carrer de Pujades, 95', '08005 Barcelona'), array('Barcelona')), // street's own comma survives the split
    'IT1' => array('Via Tortona, 37', '', 'Milano', '20144', array('Via Tortona, 37', '20144 Milano MI'), array('MI')),
    'JP1' => array('千代田区丸の内１丁目１−１', '', '千代田区', '100-0005', array('〒100-0005 東京都千代田区丸の内１丁目１−１'), array('東京都')), // no ", ": one line, leading country stripped
    'MX1' => array('Avenida Paseo de la Reforma 222', '', 'Ciudad de México', '06600', array('Avenida Paseo de la Reforma 222', 'Cuauhtémoc', '06600 Ciudad de México', 'CDMX'), array('CDMX')), // "Av. P.º" expanded
);
$expectedDependent = array('MX1' => 'Cuauhtémoc');

echo "\n--- international ---\n";
$rows = json_decode(file_get_contents(__DIR__ . '/fixtures/google-address-validation-intl.json'), true);
foreach($rows as $r){
    $prefix = explode(' ', $r['label'])[0];
    $exp = $expectedIntl[$prefix] ?? null;
    $findable = Address::findable($r['result'], false);
    $conflict = Address::relocationConflict($r['result'], $r['request']['address']['addressLines'][0] ?? '');
    if($exp === 'REJECT'){
        $ok = !$findable;
        if($ok){ $pass++; } else { $fail++; }
        printf("%s %-58s reject=%s\n", $ok ? 'PASS' : 'FAIL', $r['label'], $findable ? 'NO (expected refusal)' : ($r['result']['verdict']['validationGranularity'] ?? ''));
        continue;
    }
    $p = Address::parseInternational($r['result']);
    $problems = array();
    if($exp === null){ $problems[] = 'no expectation'; }
    else{
        if(!$findable){ $problems[] = 'gate refused it'; }
        if($conflict !== null){ $problems[] = 'unexpected reject: ' . $conflict['type']; }
        if($p['line1'] !== $exp[0]){ $problems[] = "line1='{$p['line1']}' expected '{$exp[0]}'"; }
        if($p['line2'] !== $exp[1]){ $problems[] = "line2='{$p['line2']}' expected '{$exp[1]}'"; }
        if($p['locality'] !== $exp[2]){ $problems[] = "locality='{$p['locality']}' expected '{$exp[2]}'"; }
        if($p['postal_code'] !== $exp[3]){ $problems[] = "postal_code='{$p['postal_code']}' expected '{$exp[3]}'"; }
        if($p['formatted'] !== $exp[4]){ $problems[] = 'formatted=' . json_encode($p['formatted'], JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($exp[4], JSON_UNESCAPED_UNICODE); }
        foreach($exp[5] as $a){ if(!in_array($a, $p['admin_areas'], true)){ $problems[] = "admin area '$a' missing from " . json_encode($p['admin_areas'], JSON_UNESCAPED_UNICODE); } }
        if(isset($expectedDependent[$prefix]) && $p['dependent_locality'] !== $expectedDependent[$prefix]){ $problems[] = "dependent_locality='{$p['dependent_locality']}'"; }
        if($p['language_code'] === ''){ $problems[] = 'no language_code'; }
    }
    $ok = empty($problems);
    if($ok){ $pass++; } else { $fail++; }
    printf("%s %-58s l1=%-34s loc=%s\n", $ok ? 'PASS' : 'FAIL', $r['label'], "'" . $p['line1'] . "'", "'" . $p['locality'] . "'");
    foreach($problems as $pr){ printf("     %s\n", $pr); }
    if($verbose){
        foreach(($r['result']['address']['addressComponents'] ?? array()) as $c){
            printf("       %-26s %s\n", $c['componentType'], json_encode($c['componentName']['text'] ?? '', JSON_UNESCAPED_UNICODE));
        }
    }
}

// expandPostalLine() on its own: the window must be the route, and an
// abbreviation's dot goes with it while a spelled-out token's comma stays.
echo "\n--- expandPostalLine ---\n";
$expandCases = array(
    array('Köpenicker Str. 70', 'Köpenicker Straße', 'Köpenicker Straße 70'),
    array('2 Bd J.F. Kennedy', 'Boulevard J.F. Kennedy', '2 Boulevard J.F. Kennedy'),
    array('Av. P.º de la Reforma 222', 'Avenida Paseo de la Reforma', 'Avenida Paseo de la Reforma 222'),
    array('Carrer de Pujades, 95', 'Carrer de Pujades', 'Carrer de Pujades, 95'),
    array('24 Bath St', 'Bath Street', '24 Bath Street'),
    array('Donkerstraat 12', 'Donkerstraat', 'Donkerstraat 12'),
    array('Rue Gheude 56', 'Avenue Louise', 'Rue Gheude 56'),           // not the same route: untouched
    array('', 'Anything', ''),
);
foreach($expandCases as $c){
    $got = Address::expandPostalLine($c[0], $c[1]);
    $ok = $got === $c[2];
    if($ok){ $pass++; } else { $fail++; }
    printf("%s %-40s -> %s\n", $ok ? 'PASS' : 'FAIL', "'" . $c[0] . "'", "'" . $got . "'" . ($ok ? '' : " expected '" . $c[2] . "'"));
}

// E.164 normalisation from the location's country.
echo "\n--- normalizeTelephone ---\n";
$phoneCases = array(
    array('+32 57 40 03 76', 'BE', '+3257400376'),
    array('0032 57 40 03 76', 'BE', '+3257400376'),      // 00 international prefix
    array('057 40 03 76', 'BE', '+3257400376'),          // trunk 0 dropped
    array('57 40 03 76', 'BE', '+3257400376'),           // no trunk typed
    array('(503) 555-0100', 'US', '+15035550100'),
    array('1-503-555-0100', 'US', '+15035550100'),
    array('011 32 57 40 03 76', 'US', '+3257400376'),    // NANP international prefix
    array('5035550100', 'CA', '+15035550100'),
    array('02 1234 5678', 'IT', '+390212345678'),        // Italy keeps its 0
    array('06 1 234 5678', 'HU', '+3612345678'),         // Hungary's 06 trunk
    array('8 5 123 4567', 'LT', '+37051234567'),         // Lithuania's old 8 trunk
    array('020 7123 4567', 'GB', '+442071234567'),
    array('030 12345678', 'DE', '+493012345678'),
    array('01 42 42 42 42', 'FR', '+33142424242'),
    array('26 12 34 56', 'LU', '+35226123456'),          // no trunk prefix in LU
    array('03-1234-5678', 'JP', '+81312345678'),
    array('+1', 'US', null),
    array('abc', 'BE', null),
    array('', 'BE', null),
    array('123', 'US', null),
    array('0123456789', 'US', null),                     // NANP numbers never start with 0
    array('503-555-0100', 'ZZ', null),                   // unknown country, no calling code
);
foreach($phoneCases as $c){
    $got = Address::normalizeTelephone($c[0], $c[1]);
    $ok = $got === $c[2];
    if($ok){ $pass++; } else { $fail++; }
    printf("%s %-22s %s -> %s\n", $ok ? 'PASS' : 'FAIL', "'" . $c[0] . "'", $c[1], var_export($got, true) . ($ok ? '' : ' expected ' . var_export($c[2], true)));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
