<?php
// CLI only
if(php_sapi_name() !== 'cli'){
	exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
	echo "Usage: php prune-api-logging.php [staging|production]\n";
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

// Prune old api_logging rows (keep 3 months)
$usage = new Usage();
$usage->pruneApiLogging(3);
?>
