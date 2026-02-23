<?php
// Define Root
define("ROOT", $_SERVER["DOCUMENT_ROOT"]);
define("SERVER_NAME", $_SERVER['SERVER_NAME']);

// Establish Environment
$serverName = explode('.', $_SERVER['SERVER_NAME']);
if($serverName[0] == 'api-staging'){
	define('ENVIRONMENT', 'staging');
}else{
	define('ENVIRONMENT', 'production');
}

// Load Passwords
require_once ROOT . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
	require_once  ROOT . '/classes/' . $class_name . '.class.php';
});
?>