<?php
// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__) . '/public_html');

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php update-usage.php [staging|production]\n";
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

// Update Usage
$usage = new Usage();
$usage->updateUsage();
?>
