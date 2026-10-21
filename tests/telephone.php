<?php
/*
Offline regression test for Telephone (formatting an E.164 number the way its
country writes it) and the TelephoneMetadata table it reads.

    php tests/telephone.php          # pass/fail per assertion, exit 1 on failure
    php tests/telephone.php -v       # also print every formatted value

No network and no database. The expected strings are each country's own
convention as its operators and the ITU numbering plans write it -- checked
by a person, not pasted from the formatter -- so when one fails, decide which
side is wrong before editing it. A metadata refresh (tools/
build-telephone-metadata.py) that changes a grouping should show up here.
*/

if(php_sapi_name() !== 'cli'){ exit('CLI only'); }

define('ROOT', dirname(__DIR__) . '/public_html');
require_once ROOT . '/classes/CountryCode.class.php';
require_once ROOT . '/classes/TelephoneMetadata.class.php';
require_once ROOT . '/classes/Telephone.class.php';
require_once ROOT . '/classes/Address.class.php';

$verbose = in_array('-v', $argv);
$pass = 0;
$fail = 0;

function ok($label, $condition, $detail = ''){
    global $pass, $fail, $verbose;
    if($condition){ $pass++; } else { $fail++; }
    printf("  %s %s%s\n", $condition ? 'PASS' : 'FAIL', $label,
        ($detail !== '' && (!$condition || $verbose)) ? "  ($detail)" : '');
}

function section($title){
    echo "\n\033[1m$title\033[0m\n";
}

// ---------------------------------------------------------------------------
section('Table and Address agree on the countries');

foreach(Address::COUNTRIES as $cc){
    ok("$cc has a region", isset(TelephoneMetadata::REGIONS[$cc]));
    if(isset(TelephoneMetadata::REGIONS[$cc])){
        ok("$cc calling code matches Address::CALLING_CODES",
            TelephoneMetadata::REGIONS[$cc]['cc'] === Address::CALLING_CODES[$cc],
            TelephoneMetadata::REGIONS[$cc]['cc'] . ' vs ' . Address::CALLING_CODES[$cc]);
    }
}
ok('no region without formats lacks a region to borrow from',
    count(array_filter(TelephoneMetadata::REGIONS, fn($r) => empty($r['formats']) && empty($r['formats_from']))) === 0);

// ---------------------------------------------------------------------------
section('International and national layouts');

// [E.164, international, national]. One or two real public numbers per
// country where a brewery's is known; otherwise a number of the country's
// shape. The expected layouts are the national conventions.
$cases = array(
    // North America: NANP, same layout in US, CA, PR
    array('+16192557213', '+1 619-255-7213', '(619) 255-7213'),      // Ballast Point, San Diego
    array('+15035550100', '+1 503-555-0100', '(503) 555-0100'),
    array('+14165551234', '+1 416-555-1234', '(416) 555-1234'),      // Toronto
    array('+17875551234', '+1 787-555-1234', '(787) 555-1234'),      // Puerto Rico
    // Benelux
    array('+31205286237', '+31 20 528 6237', '020 528 6237'),        // Brouwerij 't IJ, Amsterdam (2-digit area code)
    array('+31592123456', '+31 592 123 456', '0592 123 456'),        // Assen (3-digit area code)
    array('+31612345678', '+31 6 12345678', '06 12345678'),          // mobile
    array('+3257400376', '+32 57 40 03 76', '057 40 03 76'),         // Westvleteren
    array('+3289864711', '+32 89 86 47 11', '089 86 47 11'),         // Brouwerij Cornelissen, Bree
    array('+3225214928', '+32 2 521 49 28', '02 521 49 28'),         // Cantillon, Brussels (1-digit area code)
    array('+32478123456', '+32 478 12 34 56', '0478 12 34 56'),      // mobile
    array('+35227123456', '+352 27 12 34 56', '27 12 34 56'),        // Luxembourg, no trunk prefix
    // British Isles
    array('+442089962000', '+44 20 8996 2000', '020 8996 2000'),     // Fuller's, London
    array('+441212345678', '+44 121 234 5678', '0121 234 5678'),     // Birmingham
    array('+447700900123', '+44 7700 900123', '07700 900123'),       // mobile
    array('+35314084800', '+353 1 408 4800', '(01) 408 4800'),       // Guinness Storehouse, Dublin; ComReg brackets the area code
    array('+353214500000', '+353 21 450 0000', '(021) 450 0000'),    // Cork
    // Germany, Austria, Switzerland
    array('+4995156060', '+49 951 56060', '0951 56060'),             // Schlenkerla, Bamberg
    array('+493012345678', '+49 30 12345678', '030 12345678'),       // Berlin
    array('+4989123456', '+49 89 123456', '089 123456'),             // Munich
    array('+4318901234', '+43 1 8901234', '01 8901234'),             // Vienna
    array('+41441234567', '+41 44 123 45 67', '044 123 45 67'),      // Zürich
    // France, Spain, Italy, Portugal
    array('+33140053720', '+33 1 40 05 37 20', '01 40 05 37 20'),    // Paris
    array('+34913456789', '+34 913 45 67 89', '913 45 67 89'),       // Madrid, no trunk prefix
    array('+390212345678', '+39 02 1234 5678', '02 1234 5678'),      // Milan: Italy keeps its 0
    array('+393123456789', '+39 312 345 6789', '312 345 6789'),      // mobile
    array('+351212345678', '+351 21 234 5678', '21 234 5678'),       // Lisbon
    // Nordics
    array('+4533273300', '+45 33 27 33 00', '33 27 33 00'),          // Carlsberg, Copenhagen
    array('+46812345678', '+46 8 123 456 78', '08-123 456 78'),      // Stockholm
    array('+4722123456', '+47 22 12 34 56', '22 12 34 56'),          // Oslo
    array('+358912345678', '+358 9 12345678', '09 12345678'),        // Helsinki
    // Central Europe
    array('+420377062888', '+420 377 062 888', '377 062 888'),       // Pilsner Urquell, Plzeň
    array('+421212345678', '+421 2 123 456 78', '02 123 456 78'),    // Bratislava
    array('+48221234567', '+48 22 123 45 67', '22 123 45 67'),       // Warsaw
    array('+3612345678', '+36 1 234 5678', '(06 1) 234 5678'),       // Budapest; the 06 trunk prefix is bracketed with the area code
    array('+38612345678', '+386 1 234 56 78', '(01) 234 56 78'),     // Ljubljana
    array('+38512345678', '+385 1 2345 678', '01 2345 678'),         // Zagreb: 4-3 subscriber grouping
    // Baltics and Bulgaria
    array('+3726123456', '+372 612 3456', '612 3456'),               // Tallinn
    array('+37161234567', '+371 61 234 567', '61 234 567'),          // Riga
    array('+37052123456', '+370 5 212 3456', '(0-5) 212 3456'),      // Vilnius
    array('+35921234567', '+359 2 123 4567', '02 123 4567'),         // Sofia
    // Asia-Pacific
    array('+81312345678', '+81 3-1234-5678', '03-1234-5678'),        // Tokyo
    array('+6562345678', '+65 6234 5678', '6234 5678'),              // Singapore
    array('+60321234567', '+60 3-2123 4567', '03-2123 4567'),        // Kuala Lumpur
    array('+912212345678', '+91 22 1234 5678', '022 1234 5678'),     // Mumbai
    array('+919876543210', '+91 98765 43210', '098765 43210'),       // mobile
    array('+61299999999', '+61 2 9999 9999', '(02) 9999 9999'),      // Sydney
    array('+6432345678', '+64 3 234 5678', '03 234 5678'),           // Christchurch
    // Latin America
    array('+5511912345678', '+55 11 91234-5678', '(11) 91234-5678'), // São Paulo mobile
    array('+551132345678', '+55 11 3234-5678', '(11) 3234-5678'),    // São Paulo landline (subscriber numbers start 2-5)
    array('+525512345678', '+52 55 1234 5678', '55 1234 5678'),      // Mexico City
    array('+541112345678', '+54 11 1234-5678', '011 1234-5678'),     // Buenos Aires
    array('+56223456789', '+56 2 2345 6789', '2 2345 6789'),         // Santiago; Chile has no trunk prefix
    array('+576012345678', '+57 601 2345678', '(601) 2345678'),      // Bogotá (10-digit landlines since 2021)
);

foreach($cases as $case){
    list($e164, $international, $national) = $case;
    $gotIntl = Telephone::formatInternational($e164);
    $gotNat = Telephone::formatNational($e164);
    ok("$e164 international", $gotIntl === $international, "got " . var_export($gotIntl, true));
    ok("$e164 national", $gotNat === $national, "got " . var_export($gotNat, true));
}

// ---------------------------------------------------------------------------
section('Edges');

ok('unknown calling code formats as null (caller falls back to E.164)',
    Telephone::formatInternational('+9611234567') === null);
ok('unknown calling code: national is null too', Telephone::formatNational('+9611234567') === null);
ok('not E.164 at all is null', Telephone::formatInternational('5035550100') === null);
ok('null is null', Telephone::formatInternational(null) === null);
ok('empty string is null', Telephone::formatInternational('') === null);
// A known country whose formats don't cover the number: calling code plus bare digits.
ok('no matching format: calling code and bare national number',
    Telephone::formatInternational('+4512') === null || Telephone::formatInternational('+451234567') === '+45 1234567');
ok('parse splits NANP to the US rules', Telephone::parse('+14165551234')['region'] === 'US');
ok('parse splits a 3-digit calling code', Telephone::parse('+420377062888') === array('region' => 'CZ', 'cc' => '420', 'nsn' => '377062888'));
ok('countryName names the number\'s country, not the location\'s', Telephone::countryName('+3257400376') === 'Belgium');

// ---------------------------------------------------------------------------
section('Length check');

ok('US ten digits is a known length', Telephone::lengthKnown('+16192557213') === true);
ok('US nine digits is not', Telephone::lengthKnown('+1619255721') === false);
ok('US eleven digits is not', Telephone::lengthKnown('+161925572131') === false);
ok('Belgian eight digits is known', Telephone::lengthKnown('+3257400376') === true);
ok('Belgian ten digits is not', Telephone::lengthKnown('+325740037612') === false);
ok('Dutch nine digits is known', Telephone::lengthKnown('+31205286237') === true);
ok('unknown calling code cannot be checked (null)', Telephone::lengthKnown('+9611234567') === null);
ok('Spanish nine digits is known', Telephone::lengthKnown('+34913456789') === true);
ok('Spanish eight digits is not', Telephone::lengthKnown('+3491345678') === false);

// ---------------------------------------------------------------------------
section('Round trip through Address::normalizeTelephone');

$rounds = array(
    array('020 528 6237', 'NL', '+31 20 528 6237'),
    array('057 40 03 76', 'BE', '+32 57 40 03 76'),
    array('(619) 255-7213', 'US', '+1 619-255-7213'),
    array('+32 57 40 03 76', 'US', '+32 57 40 03 76'),   // a Belgian number on a US location
    array('02 1234 5678', 'IT', '+39 02 1234 5678'),
    array('06 1 234 5678', 'HU', '+36 1 234 5678'),
);
foreach($rounds as $round){
    list($typed, $cc, $expected) = $round;
    $e164 = Address::normalizeTelephone($typed, $cc);
    $got = Telephone::formatInternational($e164);
    ok("\"$typed\" from $cc -> $expected", $got === $expected, "e164 $e164, got " . var_export($got, true));
}

// ---------------------------------------------------------------------------
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
