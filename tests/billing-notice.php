<?php
/*--
Regression test for the billing arithmetic and notice wording in
Billing.class.php -- no DB, no network.

    php tests/billing-notice.php

What it proves: the overage charge rounds up to whole blocks and clamps to
the cap; the $5 floor defers small totals except when December is billed;
the charge date is the 4th of the run's month; the card label reads the way
the notice prints it; the alerts fire for a missing card, a card that
expires before the charge date (and not one that expires in that month
after it), a failed CVC check and a key with billing turned off; and the
invoice line names the free tier and says when the cap limited the charge.
Figures are October 2026's real ones.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}
define('ROOT', dirname(__DIR__) . '/public_html');
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

$b = new Billing();

// overageCents
check("174,713 requests, $200 cap -> $174", $b->overageCents(174713, 1000, 20000) === 17400);
check("174,713 requests, $40 cap -> $40", $b->overageCents(174713, 1000, 4000) === 4000);
check("7,503 requests -> $7 (6,503 rounds up to 7 blocks)", $b->overageCents(7503, 1000, 1500) === 700);
check("3,180 requests -> $3", $b->overageCents(3180, 1000, 2500) === 300);
check("1,001 requests -> $1", $b->overageCents(1001, 1000, 5000) === 100);
check("exactly the free tier -> $0", $b->overageCents(1000, 1000, 5000) === 0);
check("cap 0 -> $0", $b->overageCents(5000, 1000, 0) === 0);

// invoiceDue
check("$7 is due", $b->invoiceDue(700, 10) === true);
check("$5 is due", $b->invoiceDue(500, 10) === true);
check("$3 rolls forward", $b->invoiceDue(300, 10) === false);
check("$3 is due when December is billed", $b->invoiceDue(300, 12) === true);

// chargeDate
$nov1 = mktime(2, 40, 0, 11, 1, 2026);
check("run on Nov 1 charges Nov 4", date('Y-m-d', $b->chargeDate($nov1)) === '2026-11-04');
check("run on Jan 1 charges Jan 4", date('Y-m-d', $b->chargeDate(mktime(2, 40, 0, 1, 1, 2027))) === '2027-01-04');
$chargeTs = $b->chargeDate($nov1);

// cardLabel
$visa = array('card' => array('brand' => 'visa', 'last4' => '5503', 'exp_month' => 10, 'exp_year' => 2028, 'checks' => array('cvc_check' => 'pass')));
$amex = array('card' => array('brand' => 'amex', 'last4' => '4009', 'exp_month' => 4, 'exp_year' => 2030, 'checks' => array('cvc_check' => 'pass')));
check("Visa label", $b->cardLabel($visa) === 'Visa ending 5503, expires 10/2028');
check("Amex label spells the brand out", $b->cardLabel($amex) === 'American Express ending 4009, expires 04/2030');
check("unknown brand is capitalized", $b->cardLabel(array('card' => array('brand' => 'cartes_bancaires', 'last4' => '1', 'exp_month' => 1, 'exp_year' => 2030))) === 'Cartes_bancaires ending 1, expires 01/2030');
check("no card -> empty label", $b->cardLabel(null) === '');
check("short label drops the expiry", $b->cardLabel($visa, false) === 'Visa ending 5503');

// noticeAlerts
$titles = function($alerts){ return array_map(fn($a) => $a[0], $alerts); };
check("good card, billing on -> no alerts", $b->noticeAlerts($visa, $chargeTs, true) === array());
check("no card -> one 'No card on file' alert", $titles($b->noticeAlerts(null, $chargeTs, true)) === array('No card on file'));
$expiredOct = $visa; $expiredOct['card']['exp_month'] = 10; $expiredOct['card']['exp_year'] = 2026;
check("card expiring Oct 2026 is flagged for a Nov 4 charge", $titles($b->noticeAlerts($expiredOct, $chargeTs, true)) === array('Your card expires before the charge date'));
$expiresNov = $visa; $expiresNov['card']['exp_month'] = 11; $expiresNov['card']['exp_year'] = 2026;
check("card expiring Nov 2026 is still good on Nov 4", $b->noticeAlerts($expiresNov, $chargeTs, true) === array());
$cvcFail = $visa; $cvcFail['card']['checks']['cvc_check'] = 'fail';
check("failed CVC check is flagged", $titles($b->noticeAlerts($cvcFail, $chargeTs, true)) === array("Your bank couldn't confirm this card's security code"));
$cvcUnchecked = $visa; $cvcUnchecked['card']['checks']['cvc_check'] = 'unchecked';
check("unchecked CVC is not flagged", $b->noticeAlerts($cvcUnchecked, $chargeTs, true) === array());
check("billing turned off is flagged", $titles($b->noticeAlerts($visa, $chargeTs, false)) === array('Billing is turned off for this key'));
check("alerts stack", count($b->noticeAlerts($cvcFail, $chargeTs, false)) === 2);
$texts = function($alerts){ return array_map(fn($a) => $a[1], $alerts); };
check("carried forward: no card asks for one before the $5 floor, not a date", $texts($b->noticeAlerts(null, $chargeTs, true, false)) === array('Add a card so we can charge your balance once it reaches $5.00.'));
check("carried forward: an expiring card is not flagged (nothing is charged)", $b->noticeAlerts($expiredOct, $chargeTs, true, false) === array());
check("carried forward: failed CVC talks about a future charge", strpos($texts($b->noticeAlerts($cvcFail, $chargeTs, true, false))[0], 'A future charge may be declined.') === 0);

// carriedSummary -- this month's amount and the balance never contradict
check("summary with earlier months names both", $b->carriedSummary('October 2026', 300, 400) === 'Your October 2026 usage came to $3.00. With $1.00 carried from earlier months, your balance is $4.00, under our $5.00 minimum, so nothing is charged this month.');
check("summary without earlier months", $b->carriedSummary('October 2026', 300, 300) === 'Your October 2026 usage came to $3.00, under our $5.00 minimum, so nothing is charged this month.');

// lineDetail
$ryan = array('year' => 2026, 'month' => 10, 'totalRequests' => 174713, 'billableRequests' => 173713, 'amountCents' => 17400);
check("Ryan's line", $b->lineDetail($ryan) === '174,713 requests (173,713 over the 1,000 free; 174 × 1,000-request block at $1.00)');
$capped = $ryan; $capped['amountCents'] = 4000;
check("a capped line says so", $b->lineDetail($capped) === '174,713 requests (173,713 over the 1,000 free; 40 × 1,000-request block at $1.00, limited by your monthly spend cap)');

// Every template the code fills exists
foreach(array('notice', 'carried-forward', 'payment-failed', 'turned-off', 'card-unverified', 'line-item', 'alert') as $t){
    check("email-billing-$t.html exists", is_file(ROOT . "/classes/resources/email-billing-$t.html"));
}

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
