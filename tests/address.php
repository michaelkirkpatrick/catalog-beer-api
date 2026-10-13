<?php
/*--
Regression test for the international address layer — Address.class.php,
Subdivisions.class.php and the Location country gate — against the real
`address` table. Needs a LOCAL MySQL (it creates and drops a scratch database
named cb_address_test); no network, no secrets file, no Apache. Nothing here
reaches Google: the paths exercised are the ones that answer BEFORE the
validation call (input shape, country rules, PATCH of the telephone alone)
and the read side (every output shape over rows written directly).

    php tests/address.php [path/to/catalog-beer-mysql]

The repo path defaults to ../catalog-beer-mysql; the test loads its
catalog-beer-schema.sql and replays two statements of
migrations/2026-10-13-international-addresses.sql verbatim — the copy of
US_addresses rows and the ISO 3166-2 seed — so a change to either is caught
here. Connection defaults to root@127.0.0.1 with no password (Homebrew
MySQL); override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__) . '/public_html');
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_address_test');
// Algolia writes fail fast on a host that cannot be a URL; the class logs
// error 228 to the scratch error_log and carries on, which is the point.
define('ALGOLIA_APPLICATION_ID', '127.0.0.1:no');
define('ALGOLIA_SEARCH_API_KEY', 'x');
define('ALGOLIA_WRITE_API_KEY', 'x');
// Never called here; a path that reaches it would surface as a 500 (error 266).
define('GOOGLE_ADDRESS_VALIDATION_KEY', 'not-a-key');
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

$repo = rtrim($argv[1] ?? dirname(__DIR__, 2) . '/catalog-beer-mysql', '/');
$schemaPath = $repo . '/catalog-beer-schema.sql';
$migrationPath = $repo . '/migrations/2026-10-13-international-addresses.sql';
foreach(array($schemaPath, $migrationPath) as $f){
    if(!is_readable($f)){ echo "Not found: $f\n"; exit(1); }
}
$migration = file_get_contents($migrationPath);
$copyStart = strpos($migration, 'INSERT INTO `address`');
$seedStart = strpos($migration, 'INSERT INTO subdivisions');
$copySql = substr($migration, $copyStart, strpos($migration, ";\n", $copyStart) - $copyStart + 1);
$seedSql = substr($migration, $seedStart);

// ----- Scratch database: schema, the legacy table, fixtures, migration statements -----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
$admin->query("DROP DATABASE IF EXISTS " . DB_NAME);
$admin->query("CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$admin->select_db(DB_NAME);
$sql = "SET FOREIGN_KEY_CHECKS=0;\n" . file_get_contents($schemaPath) . "\nSET FOREIGN_KEY_CHECKS=1;\n" . <<<'SQL'
-- The legacy table as it stood before the migration, for the copy statement
CREATE TABLE `US_addresses` (
  `locationID` varchar(36) NOT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `sub_code` varchar(6) NOT NULL,
  `zip5` char(5) NOT NULL,
  `zip4` char(4) DEFAULT NULL,
  `telephone` bigint DEFAULT NULL,
  PRIMARY KEY (`locationID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO subdivisions (sub_code, countryCode, sub_name, sub_type) VALUES
 ('US-CA','US','California','state'),('US-OR','US','Oregon','state'),('US-MA','US','Massachusetts','state'),('US-DC','US','District of Columbia','district');
INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES
 ('aaaaaaaa-0000-4000-8000-000000000001','admin@example.com','x','Admin',1,1),
 ('aaaaaaaa-0000-4000-8000-000000000002','plain@example.com','x','Plain User',1,0);
INSERT INTO api_keys (id,userID) VALUES
 ('bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001'),
 ('bbbbbbbb-0000-4000-8000-000000000002','aaaaaaaa-0000-4000-8000-000000000002');
INSERT INTO brewer (id,name,url,domainName,lastModified,createdAt,status,countryCode) VALUES
 ('cccccccc-0000-4000-8000-000000000001','Sint-Sixtus','https://sintsixtus.be','sintsixtus.be',100,100,'active','BE'),
 ('cccccccc-0000-4000-8000-000000000002','Lincoln Ave Brewing',NULL,NULL,100,100,'active','US');
INSERT INTO location (id,brewerID,name,countryCode,latitude,longitude,lastModified,createdAt) VALUES
 ('dddddddd-0000-4000-8000-000000000001','cccccccc-0000-4000-8000-000000000002','Taproom','US',37.2951,-121.8945,1,1),
 ('dddddddd-0000-4000-8000-000000000002','cccccccc-0000-4000-8000-000000000002','Westfield','US',42.1251,-72.7495,1,1),
 ('dddddddd-0000-4000-8000-000000000003','cccccccc-0000-4000-8000-000000000002','Portland','US',45.5152,-122.6784,1,1),
 ('dddddddd-0000-4000-8000-000000000004','cccccccc-0000-4000-8000-000000000001','Abdij','BE',50.8957083,2.7200225,1,1),
 ('dddddddd-0000-4000-8000-000000000005','cccccccc-0000-4000-8000-000000000001','Cantillon','BE',50.8416322,4.3358423,1,1),
 ('dddddddd-0000-4000-8000-000000000006','cccccccc-0000-4000-8000-000000000001','No Address Yet','BE',NULL,NULL,1,1);
INSERT INTO US_addresses (locationID,address1,address2,city,sub_code,zip5,zip4,telephone) VALUES
 ('dddddddd-0000-4000-8000-000000000001',NULL,'1270 Lincoln Ave','San Jose','US-CA','95125',NULL,4085550100),
 ('dddddddd-0000-4000-8000-000000000002','Ste 800','123 Main St','Westfield','US-MA','01085','1234',NULL),
 ('dddddddd-0000-4000-8000-000000000003','','5 Elm St','Portland','US-OR','97201',NULL,0);
SQL;
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
// The two migration statements, verbatim from the file, then the Belgian
// rows (one references a seeded region, so they come after the seed)
$admin->multi_query($seedSql . "\n" . $copySql . "\n" . <<<'SQL'
INSERT INTO address (locationID,countryCode,line1,line2,locality,dependentLocality,sub_code,postalCode,formatted,languageCode,telephone,validation,validatedAt) VALUES
 ('dddddddd-0000-4000-8000-000000000004','BE','Donkerstraat 12',NULL,'Westvleteren',NULL,NULL,'8640','["Donkerstraat 12","8640 Westvleteren"]','nl','+3257400376','validated',1760000000),
 ('dddddddd-0000-4000-8000-000000000005','BE','Rue Gheude 56',NULL,'Anderlecht',NULL,'BE-BRU','1070','["Rue Gheude 56","1070 Anderlecht"]','fr',NULL,'validated',1760000000);
SQL);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Helpers -----
$ADMIN = 'bbbbbbbb-0000-4000-8000-000000000001'; $PLAIN = 'bbbbbbbb-0000-4000-8000-000000000002';
$BE_BREWER = 'cccccccc-0000-4000-8000-000000000001'; $US_BREWER = 'cccccccc-0000-4000-8000-000000000002';
$L_SANJOSE = 'dddddddd-0000-4000-8000-000000000001'; $L_WESTFIELD = 'dddddddd-0000-4000-8000-000000000002'; $L_PORTLAND = 'dddddddd-0000-4000-8000-000000000003';
$L_ABDIJ = 'dddddddd-0000-4000-8000-000000000004'; $L_CANTILLON = 'dddddddd-0000-4000-8000-000000000005'; $L_EMPTY = 'dddddddd-0000-4000-8000-000000000006';
function addr($method, $function, $id, $key, $data = null, $get = []) {
    $_GET = $get; $a = new Address(); $a->api($method, $function, $id, $key, $data ?? new stdClass());
    return [$a->responseCode, $a->json];
}
function loc($method, $function, $id, $key, $data = null, $get = []) {
    $_GET = $get; $l = new Location(); $l->api($method, $function, $id, $key, null, '', $data ?? new stdClass());
    return [$l->responseCode, $l->json];
}
function scalar($sql) { global $admin; $r = $admin->query($sql); $row = $r->fetch_row(); return $row[0]; }
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

// 1. The seed and the copy, as the migration wrote them
check("seed: 1,082 ISO rows + the 4 US fixtures", intval(scalar("SELECT COUNT(*) FROM subdivisions")) === 1086);
check("seed: Belgian province under its region", scalar("SELECT parentCode FROM subdivisions WHERE sub_code='BE-VWV'") === 'BE-VLG' && scalar("SELECT sub_name FROM subdivisions WHERE sub_code='BE-VWV'") === 'West-Vlaanderen');
check("seed: comma-inverted ISO names rotated", scalar("SELECT sub_name FROM subdivisions WHERE sub_code='ES-MD'") === 'Comunidad de Madrid' && scalar("SELECT sub_name FROM subdivisions WHERE sub_code='CZ-10'") === 'Hlavní město Praha');
check("seed: US outlying areas present (US-PR)", scalar("SELECT countryCode FROM subdivisions WHERE sub_code='US-PR'") === 'US');
check("copy: three US rows landed", intval(scalar("SELECT COUNT(*) FROM address WHERE countryCode='US'")) === 3);
$r = $admin->query("SELECT * FROM address WHERE locationID='$L_WESTFIELD'")->fetch_assoc();
check("copy: ZIP+4 folded, leading zero kept, unit on line2", $r['postalCode'] === '01085-1234' && $r['line2'] === 'Ste 800' && $r['line1'] === '123 Main St');
check("copy: formatted lines built in US order", json_decode($r['formatted'], true) === array('123 Main St', 'Ste 800', 'Westfield, MA 01085-1234'));
$r = $admin->query("SELECT * FROM address WHERE locationID='$L_SANJOSE'")->fetch_assoc();
check("copy: NANP digits became +1 E.164, validation=validated", $r['telephone'] === '+14085550100' && $r['validation'] === 'validated' && $r['languageCode'] === 'en');
$r = $admin->query("SELECT * FROM address WHERE locationID='$L_PORTLAND'")->fetch_assoc();
check("copy: '' unit and 0 telephone became NULL", $r['line2'] === null && $r['telephone'] === null);

// 2. Reading rows back: both vocabularies, US-only keys null abroad
$a = new Address();
check("validate() finds the San Jose row", $a->validate($L_SANJOSE, true));
$o = $a->toArray();
check("US row: legacy keys", $o['address2'] === '1270 Lincoln Ave' && $o['address1'] === null && $o['city'] === 'San Jose' && $o['sub_code'] === 'US-CA' && $o['state_short'] === 'CA' && $o['state_long'] === 'California' && $o['zip5'] === '95125' && $o['zip4'] === null);
check("US row: new keys", $o['line1'] === '1270 Lincoln Ave' && $o['line2'] === null && $o['locality'] === 'San Jose' && $o['postal_code'] === '95125' && $o['language_code'] === 'en' && $o['validation'] === 'validated');
check("US row: telephone is an E.164 string", $o['telephone'] === '+14085550100' && is_string($o['telephone']));
check("US row: admin_area from the subdivision", $o['admin_area'] === array('sub_code' => 'US-CA', 'name' => 'California', 'type' => 'state'));
check("US row: formatted is a list of strings", $o['formatted'] === array('1270 Lincoln Ave', 'San Jose, CA 95125'));
$a = new Address(); $a->validate($L_WESTFIELD, true); $o = $a->toArray();
check("US row: ZIP+4 splits back into zip5/zip4", $o['zip5'] === '01085' && $o['zip4'] === '1234' && $o['postal_code'] === '01085-1234' && $o['address1'] === 'Ste 800' && $o['line2'] === 'Ste 800');
$a = new Address(); $a->validate($L_ABDIJ, true); $o = $a->toArray();
check("BE row: legacy US-only keys are null", $o['state_short'] === null && $o['state_long'] === null && $o['zip5'] === null && $o['zip4'] === null && $o['sub_code'] === null && $o['admin_area'] === null);
check("BE row: universal legacy aliases still filled", $o['address2'] === 'Donkerstraat 12' && $o['city'] === 'Westvleteren');
check("BE row: new keys", $o['locality'] === 'Westvleteren' && $o['postal_code'] === '8640' && $o['language_code'] === 'nl' && $o['telephone'] === '+3257400376' && $o['formatted'] === array('Donkerstraat 12', '8640 Westvleteren'));
$a = new Address(); $a->validate($L_CANTILLON, true); $o = $a->toArray();
check("BE row with a region: admin_area carries the seeded name and type", $o['admin_area'] === array('sub_code' => 'BE-BRU', 'name' => 'Région de Bruxelles-Capitale', 'type' => 'region') && $o['state_short'] === null);
check("validate() false for a location without an address", !(new Address())->validate($L_EMPTY, false));

// 3. Subdivisions::lookup — Google's administrative areas against the seed
check("lookup: ISO suffix (QC -> CA-QC)", (Subdivisions::lookup('CA', array('QC')) ?? array())['sub_code'] === 'CA-QC');
check("lookup: name, accent-insensitive (Quebec)", (Subdivisions::lookup('CA', array('Quebec')) ?? array())['sub_code'] === 'CA-QC');
check("lookup: province by name (Barcelona -> ES-B)", (Subdivisions::lookup('ES', array('Barcelona')) ?? array())['sub_code'] === 'ES-B');
check("lookup: suffix before name, most specific first (MI, Lombardia)", (Subdivisions::lookup('IT', array('MI', 'Lombardia')) ?? array())['sub_code'] === 'IT-MI');
check("lookup: 'County Dublin' stripped to Dublin -> IE-D", (Subdivisions::lookup('IE', array('County Dublin', 'Co. Dublin', 'Dublin')) ?? array())['sub_code'] === 'IE-D');
check("lookup: rotated name (Comunidad de Madrid)", (Subdivisions::lookup('ES', array('Comunidad de Madrid')) ?? array())['sub_code'] === 'ES-MD');
check("lookup: no match is null, never an error", Subdivisions::lookup('BE', array('Nowhere', '')) === null && Subdivisions::lookup('BE', array()) === null);
check("lookup: a name from another country does not match", Subdivisions::lookup('BE', array('Bayern')) === null);
check("countrySeeded: BE yes, ZA no", Subdivisions::countrySeeded('BE') && !Subdivisions::countrySeeded('ZA'));

// 4. The location country gate
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Nieuw', 'country_code' => 'be']);
check("POST /location with country_code be -> created, normalised to BE", in_array($code, array(200, 201), true) && ($j['country_code'] ?? null) === 'BE' && ($j['country_short_name'] ?? null) === 'Belgium');
$L_NEW = $j['id'] ?? '';
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Nope', 'country_code' => 'XX']);
check("POST /location with XX -> 400, not an ISO code", $code === 400 && str_contains($j['valid_msg']['country_code'] ?? '', 'ISO 3166-1'));
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Nope', 'country_code' => 'ZA']);
check("POST /location with ZA -> 400 naming South Africa and the list", $code === 400 && str_contains($j['valid_msg']['country_code'] ?? '', 'South Africa') && str_contains($j['valid_msg']['country_code'], 'BE'));
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Nope']);
check("POST /location without country_code -> 400 (153)", $code === 400 && ($j['valid_state']['country_code'] ?? null) === 'invalid');
check("Address::COUNTRIES holds the 40 Google regions", count(Address::COUNTRIES) === 40 && in_array('JP', Address::COUNTRIES, true) && in_array('PR', Address::COUNTRIES, true));

// 5. Country change on a location that holds an address
[$code, $j] = loc('PATCH', '', $L_ABDIJ, $ADMIN, (object)['country_code' => 'NL']);
check("PATCH country on a location with an address -> 400 (341)", $code === 400 && str_contains($j['valid_msg']['country_code'] ?? '', 'already holds an address validated in Belgium'));
check("...and nothing changed", scalar("SELECT countryCode FROM location WHERE id='$L_ABDIJ'") === 'BE');
[$code, $j] = loc('PATCH', '', $L_ABDIJ, $ADMIN, (object)['country_code' => 'be']);
check("PATCH the same country (lowercase) is a no-op 200", $code === 200 && ($j['country_code'] ?? null) === 'BE');
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['country_code' => 'NL']);
check("PATCH country on a location without an address -> 200", $code === 200 && ($j['country_code'] ?? null) === 'NL' && ($j['country_short_name'] ?? null) === 'Netherlands');
loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['country_code' => 'BE']);
[$code, $j] = loc('PUT', '', $L_ABDIJ, $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Abdij', 'country_code' => 'DE']);
check("PUT with another country on a location with an address -> 400", $code === 400 && ($j['valid_state']['country_code'] ?? null) === 'invalid');

// 6. Address input rules that answer before Google
[$code, $j] = addr('POST', '', $L_EMPTY, $ADMIN, (object)[]);
check("POST empty body -> 400 on line1 AND its twin address2", $code === 400 && $j['valid_state']['line1'] === 'invalid' && $j['valid_state']['address2'] === 'invalid' && $j['valid_msg']['line1'] === $j['valid_msg']['address2']);
check("valid_state carries both vocabularies", count($j['valid_state']) === 12 && array_key_exists('postal_code', $j['valid_state']) && array_key_exists('zip5', $j['valid_state']));
[$code, $j] = addr('POST', '', $L_EMPTY, $ADMIN, (object)['line1' => 'Donkerstraat 12', 'postal_code' => 'ABC']);
check("BE postal code shape -> 400 naming Belgium, on postal_code and zip5", $code === 400 && str_contains($j['valid_msg']['postal_code'] ?? '', 'Belgium') && $j['valid_state']['zip5'] === 'invalid');
[$code, $j] = addr('POST', '', $L_EMPTY, $ADMIN, (object)['line1' => 'Donkerstraat 12', 'locality' => 'Vleteren', 'sub_code' => 'US-OR']);
check("sub_code from another country -> 400 (338)", $code === 400 && str_contains($j['valid_msg']['sub_code'] ?? '', 'United States of America') && str_contains($j['valid_msg']['sub_code'], 'Belgium'));
[$code, $j] = addr('POST', '', $L_EMPTY, $ADMIN, (object)['line1' => 'Donkerstraat 12', 'locality' => 'Vleteren', 'sub_code' => 'XX-YY']);
check("unknown sub_code -> 400 (62)", $code === 400 && $j['valid_state']['sub_code'] === 'invalid');
[$code, $j] = addr('POST', '', $L_EMPTY, $ADMIN, (object)['address2' => 'Donkerstraat 12']);
check("legacy key read; no locality or postal code -> 400 on locality and city", $code === 400 && $j['valid_state']['locality'] === 'invalid' && $j['valid_state']['city'] === 'invalid' && $j['valid_state']['line1'] === null);
[$code, $j] = addr('PATCH', '', $L_SANJOSE, $ADMIN, (object)['zip5' => '1234']);
check("US PATCH zip5 shape -> 400 (59) on zip5 and postal_code", $code === 400 && $j['valid_state']['zip5'] === 'invalid' && $j['valid_state']['postal_code'] === 'invalid');
[$code, $j] = addr('PATCH', '', $L_SANJOSE, $ADMIN, (object)['postal_code' => '95125-12']);
check("US PATCH postal_code with a bad +4 -> 400 (60)", $code === 400 && $j['valid_state']['zip4'] === 'invalid');
[$code, $j] = addr('POST', '', $L_ABDIJ, $ADMIN, (object)['line1' => 'x']);
check("POST on a location that has an address -> 405 (189)", $code === 405);
[$code, $j] = addr('PATCH', '', $L_EMPTY, $ADMIN, (object)['telephone' => '1']);
check("PATCH on a location without an address -> 405 (190)", $code === 405);
[$code, $j] = addr('DELETE', '', $L_ABDIJ, $ADMIN);
check("DELETE -> 405 with the twelve-key valid_state", $code === 405 && count($j['valid_state']) === 12);
[$code, $j] = addr('POST', '', 'dddddddd-0000-4000-8000-0000000000ff', $ADMIN, (object)['line1' => 'x']);
check("unknown location -> 400 (57)", $code === 400);

// 7. Telephone PATCH: the one write that completes without Google
$before = intval(scalar("SELECT lastModified FROM location WHERE id='$L_CANTILLON'"));
[$code, $j] = addr('PATCH', '', $L_CANTILLON, $ADMIN, (object)['telephone' => '02 521 49 28']);
check("PATCH national Belgian number -> 200 with the E.164 string", $code === 200 && ($j['address']['telephone'] ?? null) === '+3225214928');
check("...stored as E.164 and lastModified bumped", scalar("SELECT telephone FROM address WHERE locationID='$L_CANTILLON'") === '+3225214928' && intval(scalar("SELECT lastModified FROM location WHERE id='$L_CANTILLON'")) > $before);
check("...the response is the full location object with both vocabularies", ($j['object'] ?? null) === 'location' && $j['address']['line1'] === 'Rue Gheude 56' && $j['address']['address2'] === 'Rue Gheude 56' && $j['address']['state_short'] === null && $j['country_short_name'] === 'Belgium');
[$code, $j] = addr('PATCH', '', $L_CANTILLON, $ADMIN, (object)['telephone' => '+1 503 555 0100']);
check("PATCH an international number on a BE location -> kept as sent", $code === 200 && $j['address']['telephone'] === '+15035550100');
[$code, $j] = addr('PATCH', '', $L_CANTILLON, $ADMIN, (object)['telephone' => '12']);
check("PATCH unreadable number -> 400 (65) naming the country", $code === 400 && $j['valid_state']['telephone'] === 'invalid' && str_contains($j['valid_msg']['telephone'], 'Belgium'));
[$code, $j] = addr('PATCH', '', $L_CANTILLON, $ADMIN, (object)['telephone' => null]);
check("PATCH telephone null -> cleared", $code === 200 && $j['address']['telephone'] === null && scalar("SELECT telephone FROM address WHERE locationID='$L_CANTILLON'") === null);
[$code, $j] = addr('PATCH', '', $L_SANJOSE, $ADMIN, (object)['telephone' => '(408) 555-0199']);
check("PATCH US number -> +1 E.164", $code === 200 && $j['address']['telephone'] === '+14085550199' && $j['address']['zip5'] === '95125');
[$code, $j] = addr('PATCH', '', $L_SANJOSE, $PLAIN, (object)['telephone' => '(408) 555-0100']);
check("plain key may PATCH an unverified location", $code === 200);
$admin->query("UPDATE location SET cbVerified=1 WHERE id='$L_SANJOSE'");
[$code, $j] = addr('PATCH', '', $L_SANJOSE, $PLAIN, (object)['telephone' => '(408) 555-0100']);
check("plain key refused on a cb_verified location (191)", $code === 403);
$admin->query("UPDATE location SET cbVerified=0 WHERE id='$L_SANJOSE'");

// 8. Reference lists
[$code, $j] = addr('GET', 'countries', '', $ADMIN);
$byCode = array(); foreach($j['data'] ?? array() as $row){ $byCode[$row['country_code']] = $row; }
check("GET /address/countries -> 40 rows with names, counts and calling codes", $code === 200 && count($byCode) === 40 && $byCode['BE']['country_short_name'] === 'Belgium' && $byCode['BE']['subdivisions'] === 13 && $byCode['BE']['calling_code'] === '+32' && $byCode['US']['subdivisions'] === 10);
[$code, $j] = addr('GET', 'subdivisions', '', $ADMIN, null, array('country' => 'be'));
check("GET /address/subdivisions?country=be -> 13 rows in code order with parents", $code === 200 && $j['country_code'] === 'BE' && count($j['data']) === 13 && $j['data'][0]['sub_code'] === 'BE-BRU' && $j['data'][2]['sub_code'] === 'BE-VBR' && $j['data'][2]['parent_code'] === 'BE-VLG' && $j['data'][2]['type'] === 'province');
[$code, $j] = addr('GET', 'subdivisions', '', $ADMIN, null, array('country' => 'xx'));
check("GET /address/subdivisions with a bad country -> 400 (342)", $code === 400);
[$code, $j] = addr('GET', 'subdivisions', '', $ADMIN, null, array('country' => 'ZA'));
check("GET /address/subdivisions for an unseeded country -> empty list", $code === 200 && $j['data'] === array());

// 9. The other output shapes share the builder
[$code, $j] = loc('GET', '', $L_ABDIJ, $ADMIN);
check("GET /location/{id}: address block and country name", $code === 200 && $j['address']['formatted'] === array('Donkerstraat 12', '8640 Westvleteren') && $j['address']['zip5'] === null && $j['country_short_name'] === 'Belgium');
$l = new Location(); $rows = $l->brewerLocations($BE_BREWER);
$byId = array(); foreach($rows as $row){ $byId[$row['id']] = $row; }
check("brewerLocations: rows with and without an address", isset($byId[$L_ABDIJ]['address']) && !isset($byId[$L_EMPTY]['address']) && $byId[$L_ABDIJ]['address']['telephone'] === '+3257400376' && $byId[$L_CANTILLON]['address']['admin_area']['name'] === 'Région de Bruxelles-Capitale');
$l = new Location(); $rows = $l->brewerLocations($US_BREWER);
$byId = array(); foreach($rows as $row){ $byId[$row['id']] = $row; }
check("brewerLocations: US row keeps zip5/state_short and a string telephone", $byId[$L_WESTFIELD]['address']['zip5'] === '01085' && $byId[$L_WESTFIELD]['address']['zip4'] === '1234' && $byId[$L_WESTFIELD]['address']['state_short'] === 'MA' && $byId[$L_SANJOSE]['address']['telephone'] === '+14085550100');
$l = new Location(); $near = $l->nearbyLatLng(50.8957, 2.72, 25, 'true', '', 10);
$hit = null; foreach($near['locationArray'] as $row){ if($row['location']['id'] === $L_ABDIJ){ $hit = $row; } }
check("nearbyLatLng: the Belgian row carries the full address block", $hit !== null && $hit['location']['address']['line1'] === 'Donkerstraat 12' && $hit['location']['address']['state_short'] === null && $hit['location']['country_short_name'] === 'Belgium' && $hit['location']['telephone'] === '+3257400376');
$admin->query("UPDATE location SET latitude=50.9, longitude=2.73 WHERE id='$L_EMPTY'");
$l = new Location(); $near = $l->nearbyLatLng(50.8957, 2.72, 25, 'true', '', 10);
$empty = null; foreach($near['locationArray'] as $row){ if($row['location']['id'] === $L_EMPTY){ $empty = $row; } }
check("nearbyLatLng: a location without an address has the all-null block", $empty !== null && $empty['location']['address']['line1'] === null && $empty['location']['address']['validation'] === null && count(array_filter($empty['location']['address'], fn($v) => $v !== null)) === 0);
$l = new Location(); $l->validate($L_CANTILLON, true); $so = $l->generateLocationSearchObject();
check("search object: both vocabularies, regions facet, no US-only keys", $so['address']['line1'] === 'Rue Gheude 56' && $so['address']['address2'] === 'Rue Gheude 56' && $so['address']['postal_code'] === '1070' && !isset($so['address']['zip5']) && $so['regions'] === array('BE-BRU') && !isset($so['states']) && $so['countries'] === array('BE') && $so['cities'] === array('Anderlecht'));
$l = new Location(); $l->validate($L_WESTFIELD, true); $so = $l->generateLocationSearchObject();
check("search object: US row carries states AND regions", $so['states'] === array('MA') && $so['regions'] === array('US-MA') && $so['address']['zip5'] === '01085' && $so['address']['postal_code'] === '01085-1234');
$b = new Brewer(); $b->validate($BE_BREWER, true); $bso = $b->generateBrewerSearchObject();
check("brewer search object: regions and a 'City, Country' subtitle", in_array('BE-BRU', $bso['regions'] ?? array(), true) && !isset($bso['states']) && ($bso['subtitle'] ?? '') === 'Westvleteren, Belgium');
$b = new Brewer(); $b->validate($US_BREWER, true); $bso = $b->generateBrewerSearchObject();
check("brewer search object: US keeps 'City, ST'", in_array('CA', $bso['states'], true) && in_array('US-CA', $bso['regions'], true) && $bso['subtitle'] === 'Portland, OR');

// 10. Metrics read the new table
$m = new Metrics(); $m->snapshot();
$named = array(); $res = $admin->query("SELECT metric, dimension, value FROM metrics_daily"); while($row = $res->fetch_assoc()){ $named[$row['metric'] . '|' . $row['dimension']] = intval($row['value']); }
check("metrics: location_with_address counts address; location_country and address_validation grouped", ($named['location_with_address|'] ?? null) === 5 && ($named['location_country|BE'] ?? null) === 4 && ($named['address_validation|validated'] ?? null) === 5);

// ----- Done -----
$admin->query("DROP DATABASE " . DB_NAME);
echo "\n" . ($fails === 0 ? "ALL PASSED" : "$fails FAILED") . "\n";
exit($fails === 0 ? 0 : 1);
?>
