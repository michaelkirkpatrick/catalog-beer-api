<?php
// One-time, for the 2026-10-29 Algolia re-key: delete every record the
// `catalog` index holds under a legacy random objectID. Until this release
// each beer, brewer and location was indexed under a separate UUID kept in
// the local `algolia` table; now the entity's own id is the objectID. Run
// AFTER batch-upload.php has written every record under its new id, and
// BEFORE migrations/2026-10-29-drop-algolia-table.sql removes the table this
// reads. Delete this script with the table.
//
// Usage: php delete-legacy-ids.php [staging|production]

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__) . '/public_html');

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php delete-legacy-ids.php [staging|production]\n";
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

// Every legacy objectID. The entity columns are FK ON DELETE CASCADE, so a
// row whose entity is gone was already removed from the index by the delete
// path; the rows that remain are exactly the records to delete.
$result = $db->query("SELECT algolia_id FROM algolia");
if($db->error){
    echo "Could not read the algolia table: {$db->errorMsg}\n";
    exit(1);
}
$ids = array();
while($row = $result->fetch_assoc()){
    $ids[] = $row['algolia_id'];
}
$db->close();

echo "--- Deleting " . count($ids) . " legacy records from the catalog index (1,000 per request)...\n";
$algolia->batchDelete('catalog', $ids);
echo "--- Done. Check error_log for 292/293 before dropping the algolia table.\n";
?>
