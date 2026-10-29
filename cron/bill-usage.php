<?php
// Monthly Stripe billing for API overage, in two phases a few days apart so
// every customer is told the exact amount before their card is charged:
//
//   php cron/bill-usage.php production record    (the 1st, after update-usage)
//   php cron/bill-usage.php production invoice   (the 4th; Billing::CHARGE_DAY)
//
// record: for every billing-enabled key that went past its free tier last
// month, records a billing_charges row ($1 per 1,000 requests over the
// limit, blocks rounded up, clamped to the key's spend cap), then emails each
// customer with an unbilled balance: the amount and the card it will be
// charged to, or that it rolls forward under the $5 floor.
//
// invoice: invoices each key whose unbilled total has reached the $5 floor
// (waived when December is billed, so no balance rolls across years).
//
// Both are safe to re-run: charge rows INSERT IGNORE, notices are stamped
// per cycle, invoice items are tracked per row, and Stripe calls carry
// idempotency keys. Payment outcomes arrive later via POST /stripe-webhook.

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__) . '/public_html');

// Determine environment and phase from CLI arguments. The phase is required:
// running both at once would charge cards the day the notice says "on the 4th".
$env = $argv[1] ?? '';
$phase = $argv[2] ?? '';
if(!in_array($env, ['staging', 'production']) || !in_array($phase, ['record', 'invoice'])){
    fwrite(STDERR, "Usage: php bill-usage.php staging|production record|invoice\n");
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

$billing = new Billing();
if($phase == 'record'){
    // Close last month and tell each customer what's coming
    $billing->recordMonthlyUsage();
}else{
    // Charge what was announced on the 1st
    $billing->invoicePending();
}
?>
