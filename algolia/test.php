<?php
// Dev probe: run one search against the Algolia index and dump the response.
// Excluded from deploys; run it locally or from a checkout on the server.
//
// Usage: php test.php [staging|production] ["query"]

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root (the sibling public_html/ -- see batch-create-id.php)
define('ROOT', dirname(__DIR__) . '/public_html');

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php test.php [staging|production] [\"query\"]\n";
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

$algolia = new Algolia();
$response = $algolia->searchAlgolia($argv[2] ?? 'Ballast Point Brewing');
print_r($response);
?>
