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
//
// The secrets file lives OUTSIDE the web root, at the vhost root beside
// public_html (/var/www/html/<vhost>/common/passwords.php), so Apache cannot
// serve it under any misconfiguration. ROOT is public_html in every context,
// so dirname(ROOT) is the vhost root from any file at any depth. Locally the
// same expression resolves to the repo's own common/passwords.php. The cron
// and algolia scripts do the equivalent from their own directories.
require_once dirname(ROOT) . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
    require_once  ROOT . '/classes/' . $class_name . '.class.php';
});
?>
