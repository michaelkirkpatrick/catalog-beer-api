<?php
// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Usage: php snapshot-metrics.php [staging|production]
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php snapshot-metrics.php [staging|production]\n";
    exit(1);
}
define('ENVIRONMENT', $env);

// Load Passwords
require_once ROOT . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

// Snapshot catalog health as of now
$metrics = new Metrics();
$metrics->snapshot();

if($metrics->error){
    echo "Snapshot failed: " . $metrics->errorMsg . "\n";

    $errorLog = new LogError();
    $errorLog->errorNumber = 297;
    $errorLog->errorMsg = 'Metrics snapshot failed';
    $errorLog->badData = $metrics->errorMsg;
    $errorLog->filename = 'cron/snapshot-metrics.php';
    $errorLog->write();

    exit(1);
}

echo "Snapshot {$metrics->snapshotDate}: {$metrics->rowsWritten} metrics written.\n";
?>
