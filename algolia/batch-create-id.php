<?php
// One-time backfill: create a local `algolia` table row for every beer, brewer
// and location that lacks one. Superseded for day-to-day use by
// ensureAlgoliaRecord() in batch-upload.php, which does the same per record
// before uploading; kept for rebuilding the table from scratch.
//
// Usage: php batch-create-id.php [staging|production]

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
//
// This script lives at the vhost root (/var/www/html/<vhost>/algolia/),
// outside the DocumentRoot, so ROOT is the sibling public_html/ -- the same
// value $_SERVER['DOCUMENT_ROOT'] gives the API. The secrets file is a sibling
// of this directory.
define('ROOT', dirname(__DIR__) . '/public_html');

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php batch-create-id.php [staging|production]\n";
    exit(1);
}
define('ENVIRONMENT', $env);

// Load Passwords
require_once dirname(__DIR__) . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

// Required Classes
$algolia = new Algolia();
$db = new Database();

// Count
$beer_count = 0;
$brewer_count = 0;
$location_count = 0;

// Beer
$result = $db->query("SELECT id FROM beer");
while($array = $result->fetch_assoc()){
    $algolia_id = $algolia->add('beer', $array['id']);
    if(!$algolia->error){
        $beer_count++;
    }else{
        echo $algolia->errorMsg . "\n";
    }
}
echo "$beer_count Beers added...\n";

// Brewer
$result = $db->query("SELECT id FROM brewer");
while($array = $result->fetch_assoc()){
    $algolia_id = $algolia->add('brewer', $array['id']);
    if(!$algolia->error){
        $brewer_count++;
    }else{
        echo $algolia->errorMsg . "\n";
    }
}
echo "$brewer_count Brewers added...\n";

// Location
$result = $db->query("SELECT id FROM location");
while($array = $result->fetch_assoc()){
    $algolia_id = $algolia->add('location', $array['id']);
    if(!$algolia->error){
        $location_count++;
    }else{
        echo $algolia->errorMsg . "\n";
    }
}
echo "$location_count Locations added...\n";
?>
