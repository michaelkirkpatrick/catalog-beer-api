<?php
/*--
Regression test for the address on a location — Address.class.php as the
validator, the address columns on `location`, Subdivisions.class.php and the
Location country gate. Needs a LOCAL MySQL (it creates and drops a scratch database
named cb_address_test); no network, no secrets file, no Apache. Nothing here
reaches Google: the paths exercised are the ones that answer BEFORE the
validation call (input shape, country rules, the required address, a PATCH
of the telephone alone) and the read side (every output shape over rows written directly).

    php tests/address.php [path/to/catalog-beer-mysql]

The repo path defaults to ../catalog-beer-mysql; the test loads its
catalog-beer-schema.sql and replays, verbatim, the data statements of the two
migrations that built this shape — the copy of US_addresses into `address` and
the ISO 3166-2 seed (2026-10-13), then the fold of `address` into `location`
(2026-10-17) — so a change to any of them is caught here. Connection defaults to root@127.0.0.1 with no password (Homebrew
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
$migration1 = $repo . '/migrations/2026-10-13-international-addresses.sql';
$migration2 = $repo . '/migrations/2026-10-17-location-address-merge.sql';
foreach(array($schemaPath, $migration1, $migration2) as $f){
    if(!is_readable($f)){ echo "Not found: $f\n"; exit(1); }
}
$m1 = file_get_contents($migration1);
$ca = strpos($m1, 'CREATE TABLE `address`');
$createAddress = substr($m1, $ca, strpos($m1, ";\n", $ca) - $ca + 1);
$copyStart = strpos($m1, 'INSERT INTO `address`');
$copySql = substr($m1, $copyStart, strpos($m1, ";\n", $copyStart) - $copyStart + 1);
$seedSql = substr($m1, strpos($m1, 'INSERT INTO subdivisions'));
$m2 = file_get_contents($migration2);
$foldSql = substr($m2, strpos($m2, 'UPDATE `location` l JOIN `address` a'));   // the UPDATE ... JOIN and the DROP TABLE

// ----- Scratch database: schema, the legacy table, fixtures, migration statements -----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
$admin->query("DROP DATABASE IF EXISTS " . DB_NAME);
$admin->query("CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$admin->select_db(DB_NAME);
$sql = "SET FOREIGN_KEY_CHECKS=0;\n" . file_get_contents($schemaPath) . "\nSET FOREIGN_KEY_CHECKS=1;\n" . <<<'SQL'
-- The pre-6.0 table, for the copy statement
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
// Migration 1's table and data statements, then migration 2's fold, verbatim;
// then the Belgian rows straight onto location (one references a seeded region)
$admin->multi_query($createAddress . "\n" . $seedSql . "\n" . $copySql . "\n" . $foldSql . "\n" . <<<'SQL'
UPDATE location SET line1='Donkerstraat 12', locality='Westvleteren', postalCode='8640', formatted='["Donkerstraat 12","8640 Westvleteren"]', languageCode='nl', telephone='+3257400376', validatedAt=1760000000 WHERE id='dddddddd-0000-4000-8000-000000000004';
UPDATE location SET line1='Rue Gheude 56', locality='Anderlecht', sub_code='BE-BRU', postalCode='1070', formatted='["Rue Gheude 56","1070 Anderlecht"]', languageCode='fr', validatedAt=1760000000 WHERE id='dddddddd-0000-4000-8000-000000000005';
SQL);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Helpers -----
$ADMIN = 'bbbbbbbb-0000-4000-8000-000000000001'; $PLAIN = 'bbbbbbbb-0000-4000-8000-000000000002';
$BE_BREWER = 'cccccccc-0000-4000-8000-000000000001'; $US_BREWER = 'cccccccc-0000-4000-8000-000000000002';
$L_SANJOSE = 'dddddddd-0000-4000-8000-000000000001'; $L_WESTFIELD = 'dddddddd-0000-4000-8000-000000000002'; $L_PORTLAND = 'dddddddd-0000-4000-8000-000000000003';
$L_ABDIJ = 'dddddddd-0000-4000-8000-000000000004'; $L_CANTILLON = 'dddddddd-0000-4000-8000-000000000005'; $L_EMPTY = 'dddddddd-0000-4000-8000-000000000006';
function loc($method, $function, $id, $key, $data = null, $get = []) {
    $_GET = $get; $l = new Location(); $l->api($method, $function, $id, $key, null, '', $data ?? new stdClass());
    return [$l->responseCode, $l->json];
}
function scalar($sql) { global $admin; $r = $admin->query($sql); $row = $r->fetch_row(); return $row[0]; }
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;
$ADDR_KEYS = array('line1', 'line2', 'locality', 'dependent_locality', 'sub_code', 'postal_code', 'formatted', 'language_code', 'telephone', 'admin_area');
$STATE_KEYS = array('line1', 'line2', 'locality', 'dependent_locality', 'sub_code', 'postal_code', 'telephone');

// 1. The seed, the copy and the fold, as the migrations wrote them
check("seed: 1,082 ISO rows + the 4 US fixtures", intval(scalar("SELECT COUNT(*) FROM subdivisions")) === 1086);
check("seed: Belgian province under its region", scalar("SELECT parentCode FROM subdivisions WHERE sub_code='BE-VWV'") === 'BE-VLG' && scalar("SELECT sub_name FROM subdivisions WHERE sub_code='BE-VWV'") === 'West-Vlaanderen');
check("seed: comma-inverted ISO names rotated", scalar("SELECT sub_name FROM subdivisions WHERE sub_code='ES-MD'") === 'Comunidad de Madrid' && scalar("SELECT sub_name FROM subdivisions WHERE sub_code='CZ-10'") === 'Hlavní město Praha');
check("seed: US outlying areas present (US-PR)", scalar("SELECT countryCode FROM subdivisions WHERE sub_code='US-PR'") === 'US');
check("fold: the address table is gone", $admin->query("SHOW TABLES LIKE 'address'")->num_rows === 0);
check("fold: three US rows landed on location", intval(scalar("SELECT COUNT(*) FROM location WHERE countryCode='US' AND line1 IS NOT NULL")) === 3);
$r = $admin->query("SELECT * FROM location WHERE id='$L_WESTFIELD'")->fetch_assoc();
check("fold: ZIP+4 folded, leading zero kept, unit on line2", $r['postalCode'] === '01085-1234' && $r['line2'] === 'Ste 800' && $r['line1'] === '123 Main St');
check("fold: formatted lines built in US order", json_decode($r['formatted'], true) === array('123 Main St', 'Ste 800', 'Westfield, MA 01085-1234'));
$r = $admin->query("SELECT * FROM location WHERE id='$L_SANJOSE'")->fetch_assoc();
check("fold: NANP digits became +1 E.164, language en", $r['telephone'] === '+14085550100' && $r['languageCode'] === 'en');
$r = $admin->query("SELECT * FROM location WHERE id='$L_PORTLAND'")->fetch_assoc();
check("fold: '' unit and 0 telephone became NULL", $r['line2'] === null && $r['telephone'] === null);
check("a location without an address has every address column NULL", scalar("SELECT locality FROM location WHERE id='$L_EMPTY'") === null && scalar("SELECT line1 FROM location WHERE id='$L_EMPTY'") === null);

// 2. Reading rows back
$l = new Location();
check("validate() loads the San Jose address", $l->validate($L_SANJOSE, true) && $l->address !== null);
$o = $l->address->toArray();
check("US row: the eleven keys, nothing else", array_keys($o) === $ADDR_KEYS);
check("US row: values", $o['line1'] === '1270 Lincoln Ave' && $o['line2'] === null && $o['locality'] === 'San Jose' && $o['sub_code'] === 'US-CA' && $o['postal_code'] === '95125' && $o['language_code'] === 'en');
check("US row: telephone is an E.164 string", $o['telephone'] === '+14085550100' && is_string($o['telephone']));
check("US row: admin_area from the subdivision", $o['admin_area'] === array('sub_code' => 'US-CA', 'name' => 'California', 'type' => 'state'));
check("US row: formatted is a list of strings", $o['formatted'] === array('1270 Lincoln Ave', 'San Jose, CA 95125'));
$l = new Location(); $l->validate($L_WESTFIELD, true); $o = $l->address->toArray();
check("US row: ZIP+4 and unit", $o['postal_code'] === '01085-1234' && $o['line2'] === 'Ste 800' && $o['admin_area']['sub_code'] === 'US-MA');
$l = new Location(); $l->validate($L_ABDIJ, true); $o = $l->address->toArray();
check("BE row: no region, no admin_area", $o['sub_code'] === null && $o['admin_area'] === null && $o['line1'] === 'Donkerstraat 12');
check("BE row: values", $o['locality'] === 'Westvleteren' && $o['postal_code'] === '8640' && $o['language_code'] === 'nl' && $o['telephone'] === '+3257400376' && $o['formatted'] === array('Donkerstraat 12', '8640 Westvleteren'));
$l = new Location(); $l->validate($L_CANTILLON, true); $o = $l->address->toArray();
check("BE row with a region: admin_area carries the seeded name and type", $o['admin_area'] === array('sub_code' => 'BE-BRU', 'name' => 'Région de Bruxelles-Capitale', 'type' => 'region'));
$l = new Location(); $l->validate($L_EMPTY, true);
check("validate() leaves address null for a location without one", $l->address === null);

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
check("POST /location with country_code be passes the gate and stops at the required address", $code === 400 && ($j['valid_state']['country_code'] ?? null) === 'valid' && ($j['valid_state']['address']['line1'] ?? null) === 'invalid');
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Nope', 'country_code' => 'XX']);
check("POST /location with XX -> 400, not an ISO code", $code === 400 && str_contains($j['valid_msg']['country_code'] ?? '', 'ISO 3166-1'));
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Nope', 'country_code' => 'ZA']);
check("POST /location with ZA -> 400 naming South Africa and the list", $code === 400 && str_contains($j['valid_msg']['country_code'] ?? '', 'South Africa') && str_contains($j['valid_msg']['country_code'], 'BE'));
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Nope']);
check("POST /location without country_code -> 400 (153)", $code === 400 && ($j['valid_state']['country_code'] ?? null) === 'invalid');
check("valid_state nests the address state", array_keys($j['valid_state']) === array('brewer_id', 'name', 'url', 'country_code', 'address') && array_keys($j['valid_state']['address']) === $STATE_KEYS);
check("Address::COUNTRIES holds the 40 Google regions", count(Address::COUNTRIES) === 40 && in_array('JP', Address::COUNTRIES, true) && in_array('PR', Address::COUNTRIES, true));

// 5. Country change on a location that holds an address
[$code, $j] = loc('PATCH', '', $L_ABDIJ, $ADMIN, (object)['country_code' => 'NL']);
check("PATCH country alone on a location with an address -> 400 (341)", $code === 400 && str_contains($j['valid_msg']['country_code'] ?? '', 'holds an address validated in Belgium'));
check("...and nothing changed", scalar("SELECT countryCode FROM location WHERE id='$L_ABDIJ'") === 'BE' && scalar("SELECT line1 FROM location WHERE id='$L_ABDIJ'") === 'Donkerstraat 12');
[$code, $j] = loc('PATCH', '', $L_ABDIJ, $ADMIN, (object)['country_code' => 'be']);
check("PATCH the same country (lowercase) is a no-op 200", $code === 200 && ($j['country_code'] ?? null) === 'BE');
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['country_code' => 'NL']);
check("PATCH country on a location without an address -> 200", $code === 200 && ($j['country_code'] ?? null) === 'NL' && ($j['country_short_name'] ?? null) === 'Netherlands');
loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['country_code' => 'BE']);
[$code, $j] = loc('PUT', '', $L_ABDIJ, $ADMIN, (object)['brewer_id' => $BE_BREWER, 'name' => 'Abdij', 'country_code' => 'DE']);
check("PUT with another country and no address -> 400, the address is required", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid' && str_contains($j['error_msg'] ?? '', 'needs its address'));
check("...and nothing changed", scalar("SELECT countryCode FROM location WHERE id='$L_ABDIJ'") === 'BE' && scalar("SELECT line1 FROM location WHERE id='$L_ABDIJ'") === 'Donkerstraat 12');

// 6. Address input rules that answer before Google (nested on the location)
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['address' => (object)[]]);
check("PATCH with an empty address object on a location without one -> 400 on address.line1", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid' && str_contains($j['valid_msg']['address']['line1'], 'street address') && $j['error_msg'] === null);
check("valid_state.address carries the seven keys", array_keys($j['valid_state']['address']) === $STATE_KEYS);
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['address' => (object)['line1' => 'Donkerstraat 12', 'postal_code' => 'ABC']]);
check("BE postal code shape -> 400 naming Belgium on address.postal_code", $code === 400 && str_contains($j['valid_msg']['address']['postal_code'] ?? '', 'Belgium') && $j['valid_state']['address']['postal_code'] === 'invalid');
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['address' => (object)['line1' => 'Donkerstraat 12', 'locality' => 'Vleteren', 'sub_code' => 'US-OR']]);
check("sub_code from another country -> 400 (338)", $code === 400 && str_contains($j['valid_msg']['address']['sub_code'] ?? '', 'United States of America') && str_contains($j['valid_msg']['address']['sub_code'], 'Belgium'));
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['address' => (object)['line1' => 'Donkerstraat 12', 'locality' => 'Vleteren', 'sub_code' => 'XX-YY']]);
check("unknown sub_code -> 400 (62)", $code === 400 && $j['valid_state']['address']['sub_code'] === 'invalid');
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['address' => (object)['address2' => 'Donkerstraat 12', 'city' => 'Vleteren']]);
check("pre-6.0 keys are ignored, not read: 400 on line1 and locality", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid' && $j['valid_state']['address']['locality'] === 'invalid' && !array_key_exists('address2', $j['valid_state']['address']));
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['address' => (object)['line1' => 'Donkerstraat 12']]);
check("line1 alone -> 400 on locality naming the postal code alternative", $code === 400 && $j['valid_state']['address']['locality'] === 'invalid' && str_contains($j['valid_msg']['address']['locality'], 'postal code'));
[$code, $j] = loc('PATCH', '', $L_SANJOSE, $ADMIN, (object)['address' => (object)['postal_code' => '1234']]);
check("US PATCH four-digit postal_code -> 400 (59) on address.postal_code", $code === 400 && $j['valid_state']['address']['postal_code'] === 'invalid' && str_contains($j['valid_msg']['address']['postal_code'], 'ZIP'));
[$code, $j] = loc('PATCH', '', $L_SANJOSE, $ADMIN, (object)['address' => (object)['postal_code' => '95125-12']]);
check("US PATCH postal_code with a bad +4 -> 400 (60)", $code === 400 && $j['valid_state']['address']['postal_code'] === 'invalid' && str_contains($j['valid_msg']['address']['postal_code'], '+ 4'));
[$code, $j] = loc('PATCH', '', $L_SANJOSE, $ADMIN, (object)['address' => 'Donkerstraat 12']);
check("address that is not an object -> 400 (344)", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid' && str_contains($j['valid_msg']['address']['line1'], 'must be an object'));
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'country_code' => 'BE', 'address' => (object)['line1' => 'Donkerstraat 12']]);
check("POST with a refused address creates nothing (atomic)", $code === 400 && $j['valid_state']['address']['locality'] === 'invalid' && intval(scalar("SELECT COUNT(*) FROM location WHERE brewerID='$BE_BREWER'")) === 3);
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'country_code' => 'BE']);
check("POST without an address -> 400 naming the required fields, nothing created", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid' && $j['valid_state']['address']['locality'] === 'invalid' && str_contains($j['error_msg'] ?? '', 'needs its address') && intval(scalar("SELECT COUNT(*) FROM location WHERE brewerID='$BE_BREWER'")) === 3);
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $US_BREWER, 'country_code' => 'US', 'address' => null]);
check("POST with address null -> 400 on line1 and the US city/state pair", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid' && $j['valid_state']['address']['locality'] === 'invalid' && $j['valid_state']['address']['sub_code'] === 'invalid');
[$code, $j] = loc('POST', '', '', $ADMIN, (object)['brewer_id' => $BE_BREWER, 'country_code' => 'BE', 'address' => (object)['telephone' => '+3225214928']]);
check("POST with a telephone alone -> 400 on line1 (a telephone is not an address)", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid');
[$code, $j] = loc('PATCH', '', 'dddddddd-0000-4000-8000-0000000000ff', $ADMIN, (object)['address' => (object)['line1' => 'x']]);
check("unknown location -> 400 with the not-found message (the pre-6.0 answer, unchanged)", $code === 400 && str_contains($j['error_msg'] ?? '', "couldn't find a location"));

// 7. Telephone PATCH and clearing: the writes that complete without Google
$before = intval(scalar("SELECT lastModified FROM location WHERE id='$L_CANTILLON'"));
[$code, $j] = loc('PATCH', '', $L_CANTILLON, $ADMIN, (object)['address' => (object)['telephone' => '02 521 49 28']]);
check("PATCH national Belgian number -> 200 with the E.164 string", $code === 200 && ($j['address']['telephone'] ?? null) === '+3225214928');
check("...stored as E.164, the rest of the address untouched, lastModified bumped", scalar("SELECT telephone FROM location WHERE id='$L_CANTILLON'") === '+3225214928' && scalar("SELECT line1 FROM location WHERE id='$L_CANTILLON'") === 'Rue Gheude 56' && scalar("SELECT sub_code FROM location WHERE id='$L_CANTILLON'") === 'BE-BRU' && intval(scalar("SELECT lastModified FROM location WHERE id='$L_CANTILLON'")) > $before);
check("...the response is the full location object", ($j['object'] ?? null) === 'location' && $j['address']['line1'] === 'Rue Gheude 56' && $j['address']['admin_area']['name'] === 'Région de Bruxelles-Capitale' && !array_key_exists('address2', $j['address']) && $j['country_short_name'] === 'Belgium');
[$code, $j] = loc('PATCH', '', $L_CANTILLON, $ADMIN, (object)['address' => (object)['telephone' => '+1 503 555 0100']]);
check("PATCH an international number on a BE location -> kept as sent", $code === 200 && $j['address']['telephone'] === '+15035550100');
[$code, $j] = loc('PATCH', '', $L_CANTILLON, $ADMIN, (object)['address' => (object)['telephone' => '12']]);
check("PATCH unreadable number -> 400 (65) naming the country", $code === 400 && $j['valid_state']['address']['telephone'] === 'invalid' && str_contains($j['valid_msg']['address']['telephone'], 'Belgium'));
[$code, $j] = loc('PATCH', '', $L_CANTILLON, $ADMIN, (object)['address' => (object)['telephone' => null]]);
check("PATCH telephone null -> cleared", $code === 200 && $j['address']['telephone'] === null && scalar("SELECT telephone FROM location WHERE id='$L_CANTILLON'") === null);
[$code, $j] = loc('PATCH', '', $L_CANTILLON, $ADMIN, (object)['name' => 'Cantillon Taproom', 'address' => (object)['telephone' => '(503) 555-0100']]);
check("PATCH name and telephone together -> one write; a bare number is read in the location's country", $code === 200 && $j['name'] === 'Cantillon Taproom' && $j['address']['telephone'] === '+325035550100');
[$code, $j] = loc('PATCH', '', $L_CANTILLON, $ADMIN, (object)['address' => (object)['telephone' => '+1 503 555 0100']]);
check("...and an international one is kept as sent", $code === 200 && $j['address']['telephone'] === '+15035550100');
[$code, $j] = loc('PATCH', '', $L_SANJOSE, $ADMIN, (object)['address' => (object)['telephone' => '(408) 555-0199']]);
check("PATCH US number -> +1 E.164", $code === 200 && $j['address']['telephone'] === '+14085550199' && $j['address']['postal_code'] === '95125');
[$code, $j] = loc('PATCH', '', $L_SANJOSE, $PLAIN, (object)['address' => (object)['telephone' => '(408) 555-0100']]);
check("plain key may PATCH an unverified location", $code === 200);
$admin->query("UPDATE location SET cbVerified=1 WHERE id='$L_SANJOSE'");
[$code, $j] = loc('PATCH', '', $L_SANJOSE, $PLAIN, (object)['address' => (object)['telephone' => '(408) 555-0100']]);
check("plain key may PATCH a cb_verified location, and the badge clears", $code === 200 && intval(scalar("SELECT cbVerified FROM location WHERE id='$L_SANJOSE'")) === 0);
$admin->query("UPDATE location SET brewerVerified=1 WHERE id='$L_SANJOSE'");
[$code, $j] = loc('PATCH', '', $L_SANJOSE, $PLAIN, (object)['address' => (object)['telephone' => '(408) 555-0100']]);
check("plain key refused on a brewer_verified location (187)", $code === 403);
$admin->query("UPDATE location SET brewerVerified=0 WHERE id='$L_SANJOSE'");
[$code, $j] = loc('PATCH', '', $L_PORTLAND, $ADMIN, (object)['address' => null]);
check("PATCH address null -> 400 (345), an address is corrected, never removed", $code === 400 && $j['valid_state']['address']['line1'] === 'invalid' && str_contains($j['error_msg'] ?? '', 'not removed'));
check("...and the address is still there", scalar("SELECT line1 FROM location WHERE id='$L_PORTLAND'") === '5 Elm St' && scalar("SELECT locality FROM location WHERE id='$L_PORTLAND'") === 'Portland');
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['name' => 'Still No Address']);
check("a location that predates the rule still PATCHes without an address", $code === 200 && $j['name'] === 'Still No Address' && !isset($j['address']));
[$code, $j] = loc('PATCH', '', $L_EMPTY, $ADMIN, (object)['address' => null]);
check("...but cannot send address null either", $code === 400);

// 8. Reference lists
[$code, $j] = loc('GET', 'countries', '', $ADMIN);
$byCode = array(); foreach($j['data'] ?? array() as $row){ $byCode[$row['country_code']] = $row; }
check("GET /location/countries -> 40 rows with names, counts and calling codes", $code === 200 && $j['url'] === '/location/countries' && count($byCode) === 40 && $byCode['BE']['country_short_name'] === 'Belgium' && $byCode['BE']['subdivisions'] === 13 && $byCode['BE']['calling_code'] === '+32' && $byCode['US']['subdivisions'] === 10);
[$code, $j] = loc('GET', 'subdivisions', '', $ADMIN, null, array('country' => 'be'));
check("GET /location/subdivisions?country=be -> 13 rows in code order with parents", $code === 200 && $j['url'] === '/location/subdivisions' && $j['country_code'] === 'BE' && count($j['data']) === 13 && $j['data'][0]['sub_code'] === 'BE-BRU' && $j['data'][2]['sub_code'] === 'BE-VBR' && $j['data'][2]['parent_code'] === 'BE-VLG' && $j['data'][2]['type'] === 'province');
[$code, $j] = loc('GET', 'subdivisions', '', $ADMIN, null, array('country' => 'xx'));
check("GET /location/subdivisions with a bad country -> 400 (342)", $code === 400);
[$code, $j] = loc('GET', 'subdivisions', '', $ADMIN, null, array('country' => 'ZA'));
check("GET /location/subdivisions for an unseeded country -> empty list", $code === 200 && $j['data'] === array());

// 9. The other output shapes share the builder
[$code, $j] = loc('GET', '', $L_ABDIJ, $ADMIN);
check("GET /location/{id}: address block and country name", $code === 200 && $j['address']['formatted'] === array('Donkerstraat 12', '8640 Westvleteren') && count($j['address']) === 10 && $j['country_short_name'] === 'Belgium');
$l = new Location(); $rows = $l->brewerLocations($BE_BREWER);
$byId = array(); foreach($rows as $row){ $byId[$row['id']] = $row; }
check("brewerLocations: rows with and without an address", isset($byId[$L_ABDIJ]['address']) && !isset($byId[$L_EMPTY]['address']) && $byId[$L_ABDIJ]['address']['telephone'] === '+3257400376' && $byId[$L_CANTILLON]['address']['admin_area']['name'] === 'Région de Bruxelles-Capitale');
$l = new Location(); $rows = $l->brewerLocations($US_BREWER);
$byId = array(); foreach($rows as $row){ $byId[$row['id']] = $row; }
check("brewerLocations: US row with ZIP+4, region and a string telephone", $byId[$L_WESTFIELD]['address']['postal_code'] === '01085-1234' && $byId[$L_WESTFIELD]['address']['admin_area']['sub_code'] === 'US-MA' && $byId[$L_SANJOSE]['address']['telephone'] === '+14085550100');
$l = new Location(); $near = $l->nearbyLatLng(50.8957, 2.72, 25, 'true', '', 10);
$hit = null; foreach($near['locationArray'] as $row){ if($row['location']['id'] === $L_ABDIJ){ $hit = $row; } }
check("nearbyLatLng: the Belgian row carries the address block, no top-level telephone", $hit !== null && $hit['location']['address']['line1'] === 'Donkerstraat 12' && $hit['location']['address']['telephone'] === '+3257400376' && !array_key_exists('telephone', $hit['location']) && $hit['location']['country_short_name'] === 'Belgium');
$admin->query("UPDATE location SET latitude=50.9, longitude=2.73 WHERE id='$L_EMPTY'");
$l = new Location(); $near = $l->nearbyLatLng(50.8957, 2.72, 25, 'true', '', 10);
$empty = null; foreach($near['locationArray'] as $row){ if($row['location']['id'] === $L_EMPTY){ $empty = $row; } }
check("nearbyLatLng: a location without an address has the all-null block", $empty !== null && $empty['location']['address']['line1'] === null && count(array_filter($empty['location']['address'], fn($v) => $v !== null)) === 0);
$l = new Location(); $l->validate($L_CANTILLON, true); $so = $l->generateLocationSearchObject();
check("search object: address keys, regions facet, no states for a Belgian row", $so['address'] === array('line1' => 'Rue Gheude 56', 'locality' => 'Anderlecht', 'sub_code' => 'BE-BRU', 'postal_code' => '1070', 'telephone' => '+15035550100') && $so['regions'] === array('BE-BRU') && !isset($so['states']) && $so['countries'] === array('BE') && $so['cities'] === array('Anderlecht'));
$l = new Location(); $l->validate($L_WESTFIELD, true); $so = $l->generateLocationSearchObject();
check("search object: US row carries states AND regions", $so['states'] === array('MA') && $so['regions'] === array('US-MA') && $so['address']['line2'] === 'Ste 800' && $so['address']['postal_code'] === '01085-1234');
$b = new Brewer(); $b->validate($BE_BREWER, true); $bso = $b->generateBrewerSearchObject();
check("brewer search object: regions and a 'City, Country' subtitle", in_array('BE-BRU', $bso['regions'] ?? array(), true) && !isset($bso['states']) && ($bso['subtitle'] ?? '') === 'Westvleteren, Belgium');
$b = new Brewer(); $b->validate($US_BREWER, true); $bso = $b->generateBrewerSearchObject();
check("brewer search object: US keeps 'City, ST'", in_array('CA', $bso['states'], true) && in_array('US-CA', $bso['regions'], true) && in_array($bso['subtitle'], array('San Jose, CA', 'Westfield, MA', 'Portland, OR'), true));

// 10. Metrics read the merged columns
$m = new Metrics(); $m->snapshot();
$named = array(); $res = $admin->query("SELECT metric, dimension, value FROM metrics_daily"); while($row = $res->fetch_assoc()){ $named[$row['metric'] . '|' . $row['dimension']] = intval($row['value']); }
check("metrics: location_with_address counts rows with a street; location_country grouped", ($named['location_with_address|'] ?? null) === 5 && ($named['location_country|BE'] ?? null) === 3);

// ----- Done -----
$admin->query("DROP DATABASE " . DB_NAME);
echo "\n" . ($fails === 0 ? "ALL PASSED" : "$fails FAILED") . "\n";
exit($fails === 0 ? 0 : 1);
?>
