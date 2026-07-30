<?php
// Monthly Stripe billing for API overage. Run on the 1st of the month, after
// update-usage.php has finalized last month's counts:
//   php cron/bill-usage.php [staging|production]   (defaults to production)
//
// For every billing-enabled key that went past its free tier last month, this
// records a billing_charges row ($1 per 1,000 requests over the limit, blocks
// rounded up, clamped to the key's spend cap), then invoices each key whose
// unbilled total has reached the $5 floor (the floor is waived when December
// is billed, so no balance rolls across years). Safe to re-run: charge rows
// INSERT IGNORE, invoice items are tracked per row, and Stripe calls carry
// idempotency keys. Payment outcomes arrive later via POST /stripe-webhook.

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php bill-usage.php [staging|production]\n";
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

// Bill Last Month's Usage
$billing = new Billing();
$billing->billMonthlyUsage();
?>
