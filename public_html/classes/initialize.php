<?php
// Define Root
define("ROOT", $_SERVER["DOCUMENT_ROOT"]);

// Establish Environment from the hostname.
//
// Exact matches only, and a miss is fatal. Apache's catch-all vhost already
// denies hostnames it doesn't serve, so a request reaching this point with an
// unlisted name means a vhost gained a name without a row here, or a CLI
// caller forgot to set SERVER_NAME. Either way, refuse rather than fall
// through to production credentials. This vhost has no www alias, so
// there is no alias row.
$hostTable = [
    'api.catalog.beer' => 'production',
    'api-staging.catalog.beer' => 'staging',
];
$hostName = strtolower($_SERVER['SERVER_NAME'] ?? '');
if (!isset($hostTable[$hostName])) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "initialize.php: unknown SERVER_NAME '{$hostName}'; expected one of "
            . implode(', ', array_keys($hostTable)) . "\n");
        exit(1);
    }
    // 421 Misdirected Request: this server does not answer for that authority.
    http_response_code(421);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Unknown host.\n");
}
define('ENVIRONMENT', $hostTable[$hostName]);
define("SERVER_NAME", $hostName);
unset($hostTable, $hostName);

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
