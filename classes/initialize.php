<?php
// Start Session
session_start();

// Define Root
define("ROOT", $_SERVER["DOCUMENT_ROOT"]);
define("SERVER_NAME", $_SERVER['SERVER_NAME']);

// Establish Environment
$serverName = explode('.', $_SERVER['SERVER_NAME']);
if($serverName[0] == 'api-staging'){
	define('ENVIRONMENT', 'staging');
}elseif($serverName[0] == 'api'){
	define('ENVIRONMENT', 'production');
}

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
	require_once  ROOT . '/classes/' . $class_name . '.class.php';
});
?>