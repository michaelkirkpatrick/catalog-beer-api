<?php
/*
Offline regression test for USAddresses::parseValidatedAddress() — the
street/unit parser over Google Address Validation responses.

    php tests/address-parse.php         # table + pass/fail, exit 1 on failure
    php tests/address-parse.php -v      # also dump each fixture's components

No network, no database: fixtures/google-address-validation.json holds real
captured API responses (28 shape probes from 2026-08-05 plus rows promoted
from the full 587-address production audit — see scratch/address-audit).

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
require_once(__DIR__ . '/../classes/USAddresses.class.php');

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
    $conflict = USAddresses::relocationConflict($r['result'], $r['in'][0] ?? '');

    if(isset($expectedConflict[$prefix])){
        $ok = $conflict !== null && $conflict['type'] === $expectedConflict[$prefix];
        if($ok){ $pass++; } else { $fail++; }
        printf("%s %-58s reject=%s\n", $ok ? 'PASS' : 'FAIL', $r['label'],
            $conflict === null ? 'NONE (expected ' . $expectedConflict[$prefix] . ')'
                : $conflict['type'] . " ('" . $conflict['found'] . "')");
        continue;
    }

    $parsed = USAddresses::parseValidatedAddress($r['result']);
    $exp = $expected[$prefix] ?? null;
    $ok = $exp !== null && $parsed['address2'] === $exp[0] && $parsed['address1'] === $exp[1];
    $problems = array();
    if($conflict !== null){
        $ok = false;
        $problems[] = 'unexpected reject: ' . $conflict['type'] . " ('" . $conflict['found'] . "')";
    }
    if(isset($expectedCity[$prefix])){
        $city = USAddresses::deriveCity($r['result'], $r['city'] ?? '');
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
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
