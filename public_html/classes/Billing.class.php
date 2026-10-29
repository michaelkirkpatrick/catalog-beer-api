<?php
/*
Usage billing via Stripe. The first requestLimit requests each calendar month
are free (per-key, default 1,000); keys with a card on file may continue past
the free tier at $1 per 1,000 requests, rounded up to whole blocks, bounded by
a per-key monthly spend cap. api_usage stays the single source of truth for
metering — Stripe is only used to store cards (Checkout in setup mode), create
invoices (cron/bill-usage.php), and report payment outcomes (webhook).

Charges roll forward while a user's unbilled total is under the $5 invoice
floor (card fees would eat most of a $1 invoice); the floor is waived once a
year when December is billed, so no balance rolls across years.

Endpoints (routed from index.php):
- GET    /billing                   Billing status for the authenticated key
- POST   /billing/checkout-session  Stripe Checkout (setup mode) to add a card
- POST   /billing/portal-session    Stripe Customer Portal to manage cards
- PATCH  /billing                   Update monthly_spend_cap_cents
- DELETE /billing                   Turn billing off (key reverts to free cap)
- POST   /stripe-webhook            Stripe events (unauthenticated;
                                    signature-verified; bypasses Basic Auth)
*/

class Billing {

    // Pricing
    const BLOCK_SIZE = 1000;            // requests per billable block
    const BLOCK_PRICE_CENTS = 100;      // $1.00 per block
    const INVOICE_FLOOR_CENTS = 500;    // roll charges forward until $5 accrued
    const CHARGE_DAY = 4;               // notices go out on the 1st; cards are charged on this day

    // Spend cap bounds for PATCH (0 = no overage allowed)
    const SPEND_CAP_MIN_CENTS = 100;
    const SPEND_CAP_MAX_CENTS = 100000;

    // Error Handling
    public $error = false;
    public $errorMsg = null;

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    /* ----- Pricing helper ----- */

    // Overage charge in cents for a month's request count, clamped to the
    // spend cap. The cap clamp also absorbs the +1 the gate lets through
    // before the count freezes.
    public function overageCents($count, $requestLimit, $spendCapCents){
        $billable = $count - $requestLimit;
        if($billable <= 0){
            return 0;
        }
        $blocks = intdiv($billable + self::BLOCK_SIZE - 1, self::BLOCK_SIZE);
        return min($blocks * self::BLOCK_PRICE_CENTS, $spendCapCents);
    }

    /* ----- Authenticated endpoints ----- */

    // Stripe redirects the browser to these caller-supplied URLs after
    // checkout/portal, so an arbitrary destination would let any key holder
    // mint a Catalog.beer-branded checkout that exits to a site of their
    // choosing. Pin them to catalog.beer and its subdomains.
    private function validRedirectURL($url){
        if(strpos($url, 'https://') !== 0){
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if(empty($host)){
            return false;
        }
        $host = strtolower($host);
        return $host === 'catalog.beer' || substr($host, -13) === '.catalog.beer';
    }

    public function status($apiKey){
        $apiKeys = new apiKeys();
        if(!$apiKeys->validate($apiKey, true)){
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 276;
            $errorLog->errorMsg = 'Invalid API Key (/billing)';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        $users = new Users();
        $users->validate($apiKeys->userID, true);

        // Current month usage
        $year = date('Y');
        $month = date('n');
        $count = 0;
        $db = new Database();
        $result = $db->query("SELECT count FROM api_usage WHERE apiKey=? AND year=? AND month=?", [$apiKey, $year, $month]);
        if(!$db->error){
            if($result->num_rows == 1){
                $row = $result->fetch_assoc();
                $count = intval($row['count']);
            }

            // Unbilled balance rolled forward from earlier months
            $unbilledCents = 0;
            $result = $db->query("SELECT SUM(amountCents) AS unbilled FROM billing_charges WHERE apiKey=? AND status='pending'", [$apiKey]);
            if(!$db->error){
                $row = $result->fetch_assoc();
                $unbilledCents = intval($row['unbilled']);
            }
        }
        if($db->error){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = $db->responseCode;

            $errorLog = new LogError();
            $errorLog->errorNumber = 277;
            $errorLog->errorMsg = 'Database error (/billing)';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            $db->close();
            return;
        }
        $db->close();

        // Card on file — best effort; a Stripe hiccup degrades to card:null
        // rather than failing the whole status call.
        $card = null;
        if($apiKeys->billingEnabled && !empty($users->stripeCustomerID)){
            $stripe = new Stripe();
            $customer = $stripe->request('GET', '/v1/customers/' . $users->stripeCustomerID, array('expand' => array('invoice_settings.default_payment_method')));
            if(!$stripe->error){
                $paymentMethod = $customer['invoice_settings']['default_payment_method'] ?? null;
                if(is_array($paymentMethod) && isset($paymentMethod['card'])){
                    $card = array(
                        'brand' => $paymentMethod['card']['brand'] ?? '',
                        'last4' => $paymentMethod['card']['last4'] ?? '',
                        'exp_month' => intval($paymentMethod['card']['exp_month'] ?? 0),
                        'exp_year' => intval($paymentMethod['card']['exp_year'] ?? 0)
                    );
                }
            }
        }

        $billableRequests = max(0, $count - $apiKeys->requestLimit);
        $this->json['object'] = 'billing';
        $this->json['billing_enabled'] = $apiKeys->billingEnabled;
        $this->json['monthly_spend_cap_cents'] = $apiKeys->monthlySpendCapCents;
        $this->json['card'] = $card;
        $this->json['year'] = intval($year);
        $this->json['month'] = intval($month);
        $this->json['count'] = $count;
        $this->json['request_limit'] = $apiKeys->requestLimit;
        $this->json['billable_requests'] = $billableRequests;
        $this->json['estimated_charge_cents'] = $apiKeys->billingEnabled ? $this->overageCents($count, $apiKeys->requestLimit, $apiKeys->monthlySpendCapCents) : 0;
        $this->json['unbilled_balance_cents'] = $unbilledCents;
    }

    public function checkoutSession($apiKey, $data){
        $apiKeys = new apiKeys();
        if(!$apiKeys->validate($apiKey, true)){
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 276;
            $errorLog->errorMsg = 'Invalid API Key (/billing)';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        // Redirect URLs — must be HTTPS
        $successURL = trim($data->success_url ?? '');
        $cancelURL = trim($data->cancel_url ?? '');
        if(!$this->validRedirectURL($successURL) || !$this->validRedirectURL($cancelURL)){
            $this->error = true;
            $this->errorMsg = 'Both success_url and cancel_url are required and must be HTTPS URLs on catalog.beer (or a catalog.beer subdomain). Stripe redirects the user to these pages after they finish (or cancel) adding a payment method.';
            $this->responseCode = 400;
            return;
        }

        $users = new Users();
        $users->validate($apiKeys->userID, true);

        // Create the Stripe Customer on first use
        $stripe = new Stripe();
        $customerID = $users->stripeCustomerID;
        if(empty($customerID)){
            $customer = $stripe->request('POST', '/v1/customers', array(
                'email' => $users->email,
                'name' => $users->name,
                'metadata' => array('user_id' => $apiKeys->userID)
            ));
            if($stripe->error || empty($customer['id'])){
                $this->error = true;
                $this->errorMsg = $stripe->errorMsg;
                $this->responseCode = $stripe->responseCode;

                $errorLog = new LogError();
                $errorLog->errorNumber = 278;
                $errorLog->errorMsg = 'Failed to create Stripe customer';
                $errorLog->badData = 'userID: ' . $apiKeys->userID;
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
                return;
            }
            $customerID = $customer['id'];

            $db = new Database();
            $db->query("UPDATE users SET stripeCustomerID=? WHERE id=?", [$customerID, $apiKeys->userID]);
            if($db->error){
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                $this->responseCode = $db->responseCode;

                $errorLog = new LogError();
                $errorLog->errorNumber = 277;
                $errorLog->errorMsg = 'Database error (/billing) — saving stripeCustomerID';
                $errorLog->badData = 'userID: ' . $apiKeys->userID . ' / customer: ' . $customerID;
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
                $db->close();
                return;
            }
            $db->close();
        }

        // Checkout Session in setup mode — collects a card, charges nothing
        $session = $stripe->request('POST', '/v1/checkout/sessions', array(
            'mode' => 'setup',
            'customer' => $customerID,
            'payment_method_types' => array('card'),
            'success_url' => $successURL,
            'cancel_url' => $cancelURL,
            'metadata' => array('user_id' => $apiKeys->userID)
        ));
        if($stripe->error || empty($session['url'])){
            $this->error = true;
            $this->errorMsg = $stripe->errorMsg;
            $this->responseCode = $stripe->responseCode;

            $errorLog = new LogError();
            $errorLog->errorNumber = 279;
            $errorLog->errorMsg = 'Failed to create Stripe checkout session';
            $errorLog->badData = 'userID: ' . $apiKeys->userID;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        $this->responseCode = 201;
        $this->json['object'] = 'checkout_session';
        $this->json['id'] = $session['id'];
        $this->json['url'] = $session['url'];
    }

    public function portalSession($apiKey, $data){
        $apiKeys = new apiKeys();
        if(!$apiKeys->validate($apiKey, true)){
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 276;
            $errorLog->errorMsg = 'Invalid API Key (/billing)';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        $returnURL = trim($data->return_url ?? '');
        if(!$this->validRedirectURL($returnURL)){
            $this->error = true;
            $this->errorMsg = 'return_url is required and must be an HTTPS URL on catalog.beer (or a catalog.beer subdomain). Stripe redirects the user to this page when they leave the billing portal.';
            $this->responseCode = 400;
            return;
        }

        $users = new Users();
        $users->validate($apiKeys->userID, true);
        if(empty($users->stripeCustomerID)){
            $this->error = true;
            $this->errorMsg = 'No billing account exists for this user yet. Create one by adding a payment method via POST /billing/checkout-session.';
            $this->responseCode = 400;
            return;
        }

        $stripe = new Stripe();
        $session = $stripe->request('POST', '/v1/billing_portal/sessions', array(
            'customer' => $users->stripeCustomerID,
            'return_url' => $returnURL
        ));
        if($stripe->error || empty($session['url'])){
            $this->error = true;
            $this->errorMsg = $stripe->errorMsg;
            $this->responseCode = $stripe->responseCode;

            $errorLog = new LogError();
            $errorLog->errorNumber = 280;
            $errorLog->errorMsg = 'Failed to create Stripe portal session';
            $errorLog->badData = 'userID: ' . $apiKeys->userID;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        $this->responseCode = 201;
        $this->json['object'] = 'portal_session';
        $this->json['url'] = $session['url'];
    }

    public function updateSpendCap($apiKey, $data){
        $apiKeys = new apiKeys();
        if(!$apiKeys->validate($apiKey, true)){
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 276;
            $errorLog->errorMsg = 'Invalid API Key (/billing)';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        $cap = $data->monthly_spend_cap_cents ?? null;
        // 0 is valid (no overage allowed) — use is_int, not empty()
        if(!is_int($cap) || ($cap !== 0 && ($cap < self::SPEND_CAP_MIN_CENTS || $cap > self::SPEND_CAP_MAX_CENTS))){
            $this->error = true;
            $this->errorMsg = 'monthly_spend_cap_cents must be an integer: either 0 (no overage allowed) or between ' . self::SPEND_CAP_MIN_CENTS . ' ($1) and ' . self::SPEND_CAP_MAX_CENTS . ' ($1,000).';
            $this->responseCode = 400;
            return;
        }

        $db = new Database();
        $db->query("UPDATE api_keys SET monthlySpendCapCents=? WHERE id=?", [$cap, $apiKey]);
        if($db->error){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = $db->responseCode;

            $errorLog = new LogError();
            $errorLog->errorNumber = 277;
            $errorLog->errorMsg = 'Database error (/billing) — updating spend cap';
            $errorLog->badData = "apiKey: $apiKey / cap: $cap";
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            $db->close();
            return;
        }
        $db->close();

        $this->json['object'] = 'billing';
        $this->json['billing_enabled'] = $apiKeys->billingEnabled;
        $this->json['monthly_spend_cap_cents'] = $cap;
    }

    public function disableBilling($apiKey){
        $apiKeys = new apiKeys();
        if(!$apiKeys->validate($apiKey, true)){
            // Invalid API Key
            $this->error = true;
            $this->errorMsg = 'Invalid API Key.';
            $this->responseCode = 404;

            $errorLog = new LogError();
            $errorLog->errorNumber = 276;
            $errorLog->errorMsg = 'Invalid API Key (/billing)';
            $errorLog->badData = $apiKey;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        $db = new Database();
        $db->query("UPDATE api_keys SET billingEnabled=0 WHERE id=?", [$apiKey]);
        if($db->error){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            $this->responseCode = $db->responseCode;

            $errorLog = new LogError();
            $errorLog->errorNumber = 277;
            $errorLog->errorMsg = 'Database error (/billing) — disabling billing';
            $errorLog->badData = "apiKey: $apiKey";
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            $db->close();
            return;
        }
        $db->close();

        $this->json['object'] = 'billing';
        $this->json['billing_enabled'] = false;
        $this->json['monthly_spend_cap_cents'] = $apiKeys->monthlySpendCapCents;
    }

    /* ----- Webhook (unauthenticated; signature-verified) ----- */

    public function webhook($payload, $sigHeader){
        $stripe = new Stripe();
        if(!$stripe->verifyWebhookSignature($payload, $sigHeader)){
            $this->error = true;
            $this->responseCode = 400;
            $this->json['error'] = true;
            $this->json['error_msg'] = 'Invalid webhook signature.';

            $errorLog = new LogError();
            $errorLog->errorNumber = 274;
            $errorLog->errorMsg = 'Invalid Stripe webhook signature';
            $errorLog->badData = substr($sigHeader, 0, 200);
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            return;
        }

        $event = json_decode($payload, true);
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? array();

        switch($type){
            case 'checkout.session.completed':
                // A setup-mode session finished: make the collected card the
                // customer's default for invoices, then unlock overage.
                if(($object['mode'] ?? '') == 'setup' && !empty($object['customer']) && !empty($object['setup_intent'])){
                    $setupIntent = $stripe->request('GET', '/v1/setup_intents/' . $object['setup_intent']);
                    $paymentMethod = $setupIntent['payment_method'] ?? '';
                    if(!$stripe->error && !empty($paymentMethod) && ($setupIntent['status'] ?? '') == 'succeeded'){
                        // A saved card isn't refused when the bank reports a
                        // wrong security code (unless a Radar rule says so),
                        // and the first off-session charge is then the one
                        // likely to be declined. Don't make it the default or
                        // unlock overage; ask for the card again.
                        $card = $stripe->request('GET', '/v1/payment_methods/' . $paymentMethod);
                        if(!$stripe->error && ($card['card']['checks']['cvc_check'] ?? '') === 'fail'){
                            $errorLog = new LogError();
                            $errorLog->errorNumber = 364;
                            $errorLog->errorMsg = 'Card added with a failed CVC check — billing not enabled';
                            $errorLog->badData = 'customer: ' . $object['customer'] . ' / payment method: ' . $paymentMethod;
                            $errorLog->filename = 'API / Billing.class.php';
                            $errorLog->write();

                            $label = $this->cardLabel($card);
                            $this->sendWebhookEmail($object['customer'], 'Card not verified', 'Your bank couldn\'t confirm its security code, so billing is still off.', 'billing-card-unverified', 'email-billing-card-unverified.html',
                                array('##CARD##' => $this->h($label)),
                                "You just added a card ($label) for Catalog.beer API billing, but your bank reported that the security code didn't match, so we haven't turned billing on. Please add the card again, checking the 3- or 4-digit code: https://catalog.beer/billing");
                            break;
                        }
                        // If the card couldn't be read back, carry on as
                        // before rather than strand a customer mid-checkout.
                        $stripe->request('POST', '/v1/customers/' . $object['customer'], array(
                            'invoice_settings' => array('default_payment_method' => $paymentMethod)
                        ));
                        if(!$stripe->error){
                            $this->setBillingEnabledForCustomer($object['customer'], 1);
                        }
                    }
                }
                break;
            case 'payment_method.detached':
                // The detached payment method no longer references its
                // customer — Stripe puts the old ID in previous_attributes.
                $customerID = $event['data']['previous_attributes']['customer'] ?? '';
                if(!empty($customerID)){
                    $paymentMethods = $stripe->request('GET', '/v1/payment_methods', array('customer' => $customerID, 'type' => 'card'));
                    if(!$stripe->error && empty($paymentMethods['data'])){
                        // No cards left — key reverts to the free hard cap
                        $this->setBillingEnabledForCustomer($customerID, 0);

                        $errorLog = new LogError();
                        $errorLog->errorNumber = 287;
                        $errorLog->errorMsg = 'Billing disabled — no payment methods remain';
                        $errorLog->badData = 'customer: ' . $customerID;
                        $errorLog->filename = 'API / Billing.class.php';
                        $errorLog->write();
                    }
                }
                break;
            case 'invoice.paid':
                $this->updateChargeStatus($object['id'] ?? '', 'paid');
                break;
            case 'invoice.payment_failed':
                // Stripe retries per the dashboard's automatic-collection
                // settings; billing stays enabled until it gives up. The
                // customer hears once, on the first failure (rows still
                // 'invoiced'), not on every retry.
                $charges = $this->chargesForInvoice($object['id'] ?? '');
                $firstFailure = false;
                foreach($charges as $charge){
                    if($charge['status'] == 'invoiced'){
                        $firstFailure = true;
                    }
                }
                $this->updateChargeStatus($object['id'] ?? '', 'failed');
                if($firstFailure && !empty($object['customer'])){
                    $amount = $this->dollars($object['amount_due'] ?? 0);
                    $monthNames = array();
                    foreach($charges as $charge){
                        $monthNames[] = $this->monthName($charge);
                    }
                    $months = implode(', ', $monthNames);
                    $label = $this->cardLabel($this->defaultPaymentMethod($object['customer']));
                    $cardText = $label !== '' ? $label : 'your card on file';
                    $invoiceURL = $object['hosted_invoice_url'] ?? 'https://catalog.beer/billing';
                    $this->sendWebhookEmail($object['customer'], "Payment failed: $amount", "We'll retry automatically. Pay now or update your card.", 'billing-payment-failed', 'email-billing-payment-failed.html',
                        array('##AMOUNT##' => $this->h($amount), '##CARD##' => $this->h($cardText), '##MONTHS##' => $this->h($months), '##INVOICE_URL##' => $this->h($invoiceURL)),
                        "We tried to charge $amount to $cardText for your Catalog.beer API usage in $months, and the payment didn't go through.\r\n\r\nStripe will retry automatically over the next couple of weeks. To settle it now, pay the invoice here: $invoiceURL\r\n\r\nOr update your card at https://catalog.beer/billing\r\n\r\nIf the payment still can't be collected, billing is turned off and your key goes back to the free 1,000 requests a month.");
                }

                $errorLog = new LogError();
                $errorLog->errorNumber = 285;
                $errorLog->errorMsg = 'Stripe invoice payment failed';
                $errorLog->badData = 'invoice: ' . ($object['id'] ?? '') . ' / customer: ' . ($object['customer'] ?? '');
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
                break;
            case 'invoice.marked_uncollectible':
            case 'invoice.voided':
                // Dunning gave up (or the invoice was voided by hand) — stop
                // extending paid overage to this customer.
                $this->updateChargeStatus($object['id'] ?? '', 'written_off');
                if(!empty($object['customer'])){
                    $this->setBillingEnabledForCustomer($object['customer'], 0);

                    // Tell the customer when retries ran out; a hand-voided
                    // invoice is our own decision and gets no email.
                    if($type == 'invoice.marked_uncollectible'){
                        $amount = $this->dollars($object['amount_due'] ?? 0);
                        $invoiceURL = $object['hosted_invoice_url'] ?? 'https://catalog.beer/billing';
                        $this->sendWebhookEmail($object['customer'], 'API billing turned off', "Your key is back on the free tier. $amount is still open.", 'billing-turned-off', 'email-billing-turned-off.html',
                            array('##AMOUNT##' => $this->h($amount), '##INVOICE_URL##' => $this->h($invoiceURL), '##FREE_LIMIT##' => '1,000'),
                            "We weren't able to collect $amount for your Catalog.beer API usage after several tries, so we've turned billing off. Your key is back on the free tier of 1,000 requests a month.\r\n\r\nThe invoice is still open. You can pay it here: $invoiceURL\r\n\r\nAdding a card at https://catalog.beer/billing turns billing back on.");
                    }

                    $errorLog = new LogError();
                    $errorLog->errorNumber = 287;
                    $errorLog->errorMsg = 'Billing disabled — invoice ' . $type;
                    $errorLog->badData = 'invoice: ' . ($object['id'] ?? '') . ' / customer: ' . $object['customer'];
                    $errorLog->filename = 'API / Billing.class.php';
                    $errorLog->write();
                }
                break;
            default:
                // Event type we don't act on — acknowledge so Stripe stops
                // retrying it.
                break;
        }

        if(!$this->error){
            $this->json['received'] = true;
        }
    }

    private function setBillingEnabledForCustomer($stripeCustomerID, $enabled){
        $db = new Database();
        $db->query("UPDATE api_keys ak INNER JOIN users u ON ak.userID = u.id SET ak.billingEnabled=? WHERE u.stripeCustomerID=?", [$enabled, $stripeCustomerID]);
        if($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 275;
            $errorLog->errorMsg = 'Webhook database error — setting billingEnabled';
            $errorLog->badData = 'customer: ' . $stripeCustomerID . ' / enabled: ' . $enabled;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
        }
        $db->close();
    }

    private function updateChargeStatus($stripeInvoiceID, $status){
        if(empty($stripeInvoiceID)){
            return;
        }
        $db = new Database();
        $db->query("UPDATE billing_charges SET status=?, lastUpdated=? WHERE stripeInvoiceID=?", [$status, time(), $stripeInvoiceID]);
        if($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 275;
            $errorLog->errorMsg = 'Webhook database error — updating charge status';
            $errorLog->badData = 'invoice: ' . $stripeInvoiceID . ' / status: ' . $status;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
        }
        $db->close();
    }

    /* ----- Monthly billing (cron/bill-usage.php) ----- */

    // Billing runs in two phases a few days apart, so every customer hears
    // the exact amount before their card is touched:
    //   1st  record   close last month, record the charge rows, email notices
    //   4th  invoice  invoice every key whose unbilled total reached the floor
    // Both phases bill the previous calendar month (America/Los_Angeles,
    // matching how api_usage buckets months), so they must run in the same
    // month as each other.

    private function billingMonth(){
        $ts = strtotime('first day of last month');
        return array((int)date('Y', $ts), (int)date('n', $ts), date('F Y', $ts));
    }

    // Whether an unbilled total gets invoiced this cycle. The $5 floor is
    // waived when December is billed, so no balance rolls across years.
    public function invoiceDue($totalCents, $billedMonth){
        return $totalCents >= self::INVOICE_FLOOR_CENTS || $billedMonth == 12;
    }

    // When the invoice phase charges cards in the cycle that contains $now
    public function chargeDate($now){
        return mktime(0, 0, 0, (int)date('n', $now), self::CHARGE_DAY, (int)date('Y', $now));
    }

    public function recordMonthlyUsage(){
        // Phase 1 (the 1st). Safe to re-run: charge rows INSERT IGNORE against
        // a unique (apiKey, year, month) key, and a key's notice is sent once
        // per cycle (noticeSentAt).
        list($year, $month, $monthLabel) = $this->billingMonth();
        $now = time();

        $db = new Database();
        if($db->error){
            return;
        }

        // Record an overage charge row per billing-enabled key that went past
        // its free tier last month.
        $recorded = 0;
        $result = $db->query("SELECT au.apiKey, au.count, ak.userID, ak.requestLimit, ak.monthlySpendCapCents FROM api_usage au INNER JOIN api_keys ak ON au.apiKey = ak.id WHERE au.year=? AND au.month=? AND ak.billingEnabled=1 AND au.count > ak.requestLimit", [$year, $month]);
        if($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 284;
            $errorLog->errorMsg = 'Billing cron database error — overage query';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            $db->close();
            return;
        }
        while($row = $result->fetch_assoc()){
            $count = intval($row['count']);
            $requestLimit = intval($row['requestLimit']);
            $amountCents = $this->overageCents($count, $requestLimit, intval($row['monthlySpendCapCents']));
            if($amountCents <= 0){
                continue;
            }
            $uuid = new uuid();
            $chargeID = $uuid->generate('billing_charges');
            $db->query("INSERT IGNORE INTO billing_charges (id, userID, apiKey, year, month, totalRequests, billableRequests, amountCents, status, createdAt, lastUpdated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)", [$chargeID, $row['userID'], $row['apiKey'], $year, $month, $count, $count - $requestLimit, $amountCents, $now, $now]);
            if($db->error){
                $errorLog = new LogError();
                $errorLog->errorNumber = 284;
                $errorLog->errorMsg = 'Billing cron database error — inserting charge';
                $errorLog->badData = 'apiKey: ' . $row['apiKey'] . ' / ' . $db->errorMsg;
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
            }elseif($db->getAffectedRows() > 0){
                // 0 on a re-run: the month's row already exists
                $recorded++;
            }
        }
        $db->close();
        echo "Recorded overage for $monthLabel: $recorded new key(s)\n";

        $this->sendNotices($year, $month, $monthLabel, $now);
    }

    private function sendNotices($year, $month, $monthLabel, $now){
        // One email per key with an unbilled balance: either "we'll charge
        // $X on the 4th" or "$X carried forward, nothing charged". A key gets
        // one when it has a charge for the month just closed, or when its
        // carried balance is due anyway (the December sweep). Every pending
        // row of the key is stamped with noticeSentAt, and a key stamped
        // since the start of this month is skipped, so a re-run never sends
        // twice and a failed send is retried.
        $cycleStart = mktime(0, 0, 0, (int)date('n', $now), 1, (int)date('Y', $now));
        $chargeTs = $this->chargeDate($now);

        $db = new Database();
        if($db->error){
            return;
        }
        $result = $db->query("SELECT bc.apiKey, bc.year, bc.month, bc.totalRequests, bc.billableRequests, bc.amountCents, bc.noticeSentAt, u.id AS userID, u.name, u.email, u.stripeCustomerID, ak.billingEnabled+0 AS billingEnabled, ak.monthlySpendCapCents FROM billing_charges bc INNER JOIN api_keys ak ON bc.apiKey = ak.id INNER JOIN users u ON bc.userID = u.id WHERE bc.status='pending' ORDER BY bc.apiKey, bc.year, bc.month");
        if($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 284;
            $errorLog->errorMsg = 'Billing cron database error — notice query';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            $db->close();
            return;
        }
        $pending = array();
        while($row = $result->fetch_assoc()){
            $pending[$row['apiKey']][] = $row;
        }

        $sent = 0;
        $failed = 0;
        foreach($pending as $pendingApiKey => $charges){
            $totalCents = 0;
            $hasCurrent = false;
            $alreadySent = false;
            foreach($charges as $charge){
                $totalCents += intval($charge['amountCents']);
                if(intval($charge['year']) == $year && intval($charge['month']) == $month){
                    $hasCurrent = true;
                }
                if(intval($charge['noticeSentAt']) >= $cycleStart){
                    $alreadySent = true;
                }
            }
            $due = $this->invoiceDue($totalCents, $month);
            if($alreadySent || (!$hasCurrent && !$due)){
                continue;
            }

            $first = $charges[0];
            $paymentMethod = $this->defaultPaymentMethod($first['stripeCustomerID']);
            $alerts = $this->noticeAlerts($paymentMethod, $chargeTs, intval($first['billingEnabled']) == 1, $due);

            if($this->sendNotice($first, $charges, $totalCents, $due, $monthLabel, $chargeTs, $paymentMethod, $alerts)){
                $db->query("UPDATE billing_charges SET noticeSentAt=?, lastUpdated=? WHERE apiKey=? AND status='pending'", [time(), time(), $pendingApiKey]);
                $sent++;
            }else{
                $failed++;
                $errorLog = new LogError();
                $errorLog->errorNumber = 363;
                $errorLog->errorMsg = 'Billing notice email failed';
                $errorLog->badData = 'apiKey: ' . $pendingApiKey . ' / user: ' . $first['userID'];
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
            }
        }
        $db->close();
        echo "Notices sent: $sent; failed: $failed\n";
    }

    public function invoicePending(){
        // Phase 2 (the 4th). Invoice every key whose pending total has
        // reached the floor (or everything, on the annual sweep). One invoice
        // item per pending month keeps the line items readable. Safe to
        // re-run: invoice items are only created for rows without a
        // stripeInvoiceItemID, and Stripe calls carry idempotency keys.
        //
        // billingEnabled is deliberately NOT required here: the charge rows
        // were recorded for usage served while billing was on and announced
        // on the 1st, so turning billing off before the 4th doesn't cancel
        // them.
        list($year, $month, $monthLabel) = $this->billingMonth();

        $db = new Database();
        if($db->error){
            return;
        }

        $pending = array();
        $result = $db->query("SELECT bc.id, bc.apiKey, bc.year, bc.month, bc.totalRequests, bc.billableRequests, bc.amountCents, bc.stripeInvoiceItemID, u.stripeCustomerID FROM billing_charges bc INNER JOIN users u ON bc.userID = u.id WHERE bc.status='pending' AND u.stripeCustomerID IS NOT NULL ORDER BY bc.apiKey, bc.year, bc.month");
        if($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 284;
            $errorLog->errorMsg = 'Billing cron database error — pending query';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
            $db->close();
            return;
        }
        while($row = $result->fetch_assoc()){
            $pending[$row['apiKey']][] = $row;
        }

        $invoiced = 0;
        $deferred = 0;
        foreach($pending as $pendingApiKey => $charges){
            $totalCents = 0;
            foreach($charges as $charge){
                $totalCents += intval($charge['amountCents']);
            }
            if(!$this->invoiceDue($totalCents, $month)){
                $deferred++;
                continue;
            }

            $stripe = new Stripe();
            $customerID = $charges[0]['stripeCustomerID'];

            // Invoice items first; each row remembers its item so a crashed
            // run never creates the same line twice.
            $itemsOK = true;
            foreach($charges as $charge){
                if(!empty($charge['stripeInvoiceItemID'])){
                    continue;
                }
                $item = $stripe->request('POST', '/v1/invoiceitems', array(
                    'customer' => $customerID,
                    'amount' => intval($charge['amountCents']),
                    'currency' => 'usd',
                    'description' => 'Catalog.beer API — ' . $this->monthName($charge) . ': ' . $this->lineDetail($charge)
                ), 'cb-item-' . $charge['id']);
                if($stripe->error || empty($item['id'])){
                    $itemsOK = false;
                    break;
                }
                $db->query("UPDATE billing_charges SET stripeInvoiceItemID=?, lastUpdated=? WHERE id=?", [$item['id'], time(), $charge['id']]);
                if($db->error){
                    $itemsOK = false;
                    break;
                }
            }
            if(!$itemsOK){
                // Rows stay pending; next run resumes where this one stopped
                $errorLog = new LogError();
                $errorLog->errorNumber = 283;
                $errorLog->errorMsg = 'Billing cron — invoice item creation failed';
                $errorLog->badData = 'apiKey: ' . $pendingApiKey . ' / customer: ' . $customerID;
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
                continue;
            }

            // Create and auto-advance the invoice; Stripe finalizes it, charges
            // the default payment method, and emails the receipt when
            // Settings > Customer emails > Successful payments is on.
            $invoice = $stripe->request('POST', '/v1/invoices', array(
                'customer' => $customerID,
                'collection_method' => 'charge_automatically',
                'auto_advance' => 'true',
                'pending_invoice_items_behavior' => 'include',
                'description' => 'Catalog.beer API usage'
            ), 'cb-inv-' . $pendingApiKey . '-' . $year . '-' . $month);
            if($stripe->error || empty($invoice['id'])){
                $errorLog = new LogError();
                $errorLog->errorNumber = 283;
                $errorLog->errorMsg = 'Billing cron — invoice creation failed';
                $errorLog->badData = 'apiKey: ' . $pendingApiKey . ' / customer: ' . $customerID;
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
                continue;
            }

            $db->query("UPDATE billing_charges SET status='invoiced', stripeInvoiceID=?, lastUpdated=? WHERE apiKey=? AND status='pending' AND stripeInvoiceItemID IS NOT NULL", [$invoice['id'], time(), $pendingApiKey]);
            if($db->error){
                $errorLog = new LogError();
                $errorLog->errorNumber = 284;
                $errorLog->errorMsg = 'Billing cron database error — marking invoiced';
                $errorLog->badData = 'apiKey: ' . $pendingApiKey . ' / invoice: ' . $invoice['id'];
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
            }
            $invoiced++;
        }
        $db->close();
        echo "Invoiced for $monthLabel: $invoiced key(s); deferred under \$5 floor: $deferred key(s)\n";
    }

    /* ----- Billing emails ----- */

    // The customer's default card, or null (none on file, or Stripe
    // unreachable: a notice then names no card rather than not going out).
    private function defaultPaymentMethod($stripeCustomerID){
        if(empty($stripeCustomerID)){
            return null;
        }
        $stripe = new Stripe();
        $customer = $stripe->request('GET', '/v1/customers/' . $stripeCustomerID, array('expand' => array('invoice_settings.default_payment_method')));
        if($stripe->error){
            return null;
        }
        $paymentMethod = $customer['invoice_settings']['default_payment_method'] ?? null;
        if(is_array($paymentMethod) && isset($paymentMethod['card'])){
            return $paymentMethod;
        }
        return null;
    }

    // "Visa ending 5503, expires 10/2028"
    public function cardLabel($paymentMethod){
        if(empty($paymentMethod['card'])){
            return '';
        }
        $card = $paymentMethod['card'];
        $brands = array('visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover', 'diners' => 'Diners Club', 'jcb' => 'JCB', 'unionpay' => 'UnionPay');
        $brand = $brands[$card['brand'] ?? ''] ?? ucfirst($card['brand'] ?? 'Card');
        return $brand . ' ending ' . ($card['last4'] ?? '????') . ', expires ' . sprintf('%02d', intval($card['exp_month'] ?? 0)) . '/' . intval($card['exp_year'] ?? 0);
    }

    // Anything on the notice that might stop the charge going through, as
    // [title, text] pairs. $due says whether this notice announces a charge
    // or a balance carried forward, which changes what is worth saying: an
    // expiry date only matters before a charge. Pure, so
    // tests/billing-notice.php covers it.
    public function noticeAlerts($paymentMethod, $chargeTs, $billingEnabled, $due = true){
        $alerts = array();
        $floor = $this->dollars(self::INVOICE_FLOOR_CENTS);
        if(empty($paymentMethod['card'])){
            if($due){
                $alerts[] = array('No card on file', 'We couldn\'t find a card to charge. Add one before ' . date('F j', $chargeTs) . ' so the payment goes through.');
            }else{
                $alerts[] = array('No card on file', 'Add a card so we can charge your balance once it reaches ' . $floor . '.');
            }
            return $alerts;
        }
        $card = $paymentMethod['card'];
        // A card is good through the last day of its expiry month
        $expiresTs = mktime(0, 0, 0, intval($card['exp_month'] ?? 0) + 1, 1, intval($card['exp_year'] ?? 0));
        if($due && $expiresTs <= $chargeTs){
            $alerts[] = array('Your card expires before the charge date', 'Update it before ' . date('F j', $chargeTs) . ' so the payment goes through.');
        }
        if(($card['checks']['cvc_check'] ?? '') === 'fail'){
            $alerts[] = array('Your bank couldn\'t confirm this card\'s security code', ($due ? 'The charge may be declined.' : 'A future charge may be declined.') . ' Adding the card again now, with its security code, avoids a failed payment.');
        }
        if(!$billingEnabled){
            if($due){
                $alerts[] = array('Billing is turned off for this key', 'This charge covers requests made while billing was on, so it will still be collected. Your key is on the free tier until you turn billing back on.');
            }else{
                $alerts[] = array('Billing is turned off for this key', 'This balance covers requests made while billing was on. It will be charged once it reaches ' . $floor . ', or in January if it never does. Your key is on the free tier until you turn billing back on.');
            }
        }
        return $alerts;
    }

    // The opening line of the carried-forward notice. Spells out where the
    // balance came from when earlier months are in it, so this month's
    // amount and the balance can't read as a contradiction.
    public function carriedSummary($monthLabel, $thisMonthCents, $totalCents){
        $floor = $this->dollars(self::INVOICE_FLOOR_CENTS);
        $earlier = $totalCents - $thisMonthCents;
        if($earlier > 0){
            return 'Your ' . $monthLabel . ' usage came to ' . $this->dollars($thisMonthCents) . '. With ' . $this->dollars($earlier) . ' carried from earlier months, your balance is ' . $this->dollars($totalCents) . ', under our ' . $floor . ' minimum, so nothing is charged this month.';
        }
        return 'Your ' . $monthLabel . ' usage came to ' . $this->dollars($thisMonthCents) . ', under our ' . $floor . ' minimum, so nothing is charged this month.';
    }

    // "October 2026" -> "October": subjects stay short, the body has the year
    private function monthOnly($monthLabel){
        return explode(' ', $monthLabel)[0];
    }

    private function monthName($charge){
        return date('F Y', mktime(0, 0, 0, intval($charge['month']), 1, intval($charge['year'])));
    }

    // "174,713 requests (173,713 over the 1,000 free; 174 × 1,000-request
    // block at $1.00)" -- the Stripe invoice line, so notice and invoice agree
    public function lineDetail($charge){
        $blocks = intdiv(intval($charge['amountCents']), self::BLOCK_PRICE_CENTS);
        $free = intval($charge['totalRequests']) - intval($charge['billableRequests']);
        $detail = number_format($charge['totalRequests']) . ' requests (' . number_format($charge['billableRequests']) . ' over the ' . number_format($free) . ' free; ' . $blocks . ' × 1,000-request block at $1.00';
        $uncapped = intdiv(intval($charge['billableRequests']) + self::BLOCK_SIZE - 1, self::BLOCK_SIZE);
        if($uncapped > $blocks){
            $detail .= ', limited by your monthly spend cap';
        }
        return $detail . ')';
    }

    private function dollars($cents){
        return '$' . number_format(intval($cents) / 100, 2);
    }

    private function h($value){
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    private function emailSignoff(){
        return "Questions? Just reply to this email.\r\n\r\n-Michael\r\n\r\nMichael Kirkpatrick\r\nFounder, Catalog.beer";
    }

    private function sendNotice($user, $charges, $totalCents, $due, $monthLabel, $chargeTs, $paymentMethod, $alerts){
        $name = trim($user['name'] ?? '') !== '' ? $user['name'] : 'there';
        $card = $this->cardLabel($paymentMethod);
        $billingURL = 'https://catalog.beer/billing';

        // Line items, HTML and text
        $rowTemplate = file_get_contents(ROOT . '/classes/resources/email-billing-line-item.html');
        $lineItems = '';
        $lineText = '';
        foreach($charges as $charge){
            $blocks = intdiv(intval($charge['amountCents']), self::BLOCK_PRICE_CENTS);
            $free = intval($charge['totalRequests']) - intval($charge['billableRequests']);
            $lineItems .= str_replace(
                array('##ITEM_MONTH##', '##ITEM_REQUESTS##', '##ITEM_DETAIL##', '##ITEM_AMOUNT##'),
                array(
                    $this->h($this->monthName($charge)),
                    $this->h(number_format($charge['totalRequests']) . ' requests'),
                    $this->h(number_format($charge['billableRequests']) . ' over the ' . number_format($free) . ' free · ' . $blocks . ' × $1.00'),
                    $this->h($this->dollars($charge['amountCents']))
                ),
                $rowTemplate);
            $lineText .= '  ' . $this->monthName($charge) . ': ' . $this->lineDetail($charge) . ' — ' . $this->dollars($charge['amountCents']) . "\r\n";
        }

        $alertTemplate = file_get_contents(ROOT . '/classes/resources/email-billing-alert.html');
        $alertHtml = '';
        $alertText = '';
        foreach($alerts as $alert){
            $alertHtml .= str_replace(array('##ALERT_TITLE##', '##ALERT_TEXT##'), array($this->h($alert[0]), $this->h($alert[1])), $alertTemplate);
            $alertText .= '** ' . $alert[0] . "\r\n" . $alert[1] . "\r\n\r\n";
        }

        $replacements = array(
            '##NAME##' => $this->h($name),
            '##MONTH##' => $this->h($monthLabel),
            '##LINE_ITEMS##' => $lineItems,
            '##ALERTS##' => $alertHtml,
            '##BILLING_URL##' => $billingURL
        );

        if($due){
            $amount = $this->dollars($totalCents);
            $chargeDate = date('F j, Y', $chargeTs);
            $cardText = $card !== '' ? $card : 'the card on your account';
            $cap = $this->dollars($user['monthlySpendCapCents']);
            $subject = "Your " . $this->monthOnly($monthLabel) . " API bill: $amount";
            $preheader = "We'll charge $cardText on " . date('F j', $chargeTs) . '.';
            $template = 'email-billing-notice.html';
            $tag = 'billing-notice';
            $replacements['##AMOUNT##'] = $this->h($amount);
            $replacements['##CHARGE_DATE##'] = $this->h($chargeDate);
            $replacements['##CARD##'] = $this->h($cardText);
            $replacements['##CAP##'] = $this->h($cap . ' monthly spend cap');
            $textBody = "Hi $name,\r\n\r\nHere's your Catalog.beer API bill for $monthLabel. We'll charge $amount to $cardText on $chargeDate.\r\n\r\n$lineText  Total: $amount\r\n\r\n" . $alertText . "Your monthly spend cap is $cap. Review your billing or update your card at $billingURL\r\n\r\n" . $this->emailSignoff();
        }else{
            $thisMonthCents = 0;
            foreach($charges as $charge){
                if($this->monthName($charge) === $monthLabel){
                    $thisMonthCents += intval($charge['amountCents']);
                }
            }
            $amount = $this->dollars($thisMonthCents);
            $balance = $this->dollars($totalCents);
            $floor = $this->dollars(self::INVOICE_FLOOR_CENTS);
            $subject = "Your " . $this->monthOnly($monthLabel) . " API usage: $amount, nothing due";
            $preheader = "Your $balance balance carries forward until it reaches $floor.";
            $template = 'email-billing-carried-forward.html';
            $tag = 'billing-carried-forward';
            $summary = $this->carriedSummary($monthLabel, $thisMonthCents, $totalCents);
            $replacements['##SUMMARY##'] = $this->h($summary);
            $replacements['##BALANCE##'] = $this->h($balance);
            $replacements['##FLOOR##'] = $this->h($floor);
            $textBody = "Hi $name,\r\n\r\n$summary Your balance of $balance carries forward and is charged once it reaches $floor; whatever is left is charged in January regardless.\r\n\r\n$lineText\r\n" . $alertText . "Review your billing at $billingURL\r\n\r\n" . $this->emailSignoff();
        }

        $sendEmail = new SendEmail();
        return $sendEmail->billingEmail($user['email'], $subject, $tag, $template, $replacements, $textBody, $preheader);
    }

    // The account behind a Stripe customer, for webhook emails
    private function userForCustomer($stripeCustomerID){
        $user = null;
        if(empty($stripeCustomerID)){
            return $user;
        }
        $db = new Database();
        $result = $db->query("SELECT id, name, email FROM users WHERE stripeCustomerID=?", [$stripeCustomerID]);
        if(!$db->error && $result->num_rows == 1){
            $user = $result->fetch_assoc();
        }
        $db->close();
        return $user;
    }

    private function sendWebhookEmail($stripeCustomerID, $subject, $preheader, $tag, $template, $replacements, $text){
        $user = $this->userForCustomer($stripeCustomerID);
        if(empty($user)){
            return;
        }
        $name = trim($user['name'] ?? '') !== '' ? $user['name'] : 'there';
        $replacements['##NAME##'] = $this->h($name);
        $replacements['##BILLING_URL##'] = 'https://catalog.beer/billing';
        $textBody = "Hi $name,\r\n\r\n" . $text . "\r\n\r\n" . $this->emailSignoff();

        $sendEmail = new SendEmail();
        if(!$sendEmail->billingEmail($user['email'], $subject, $tag, $template, $replacements, $textBody, $preheader)){
            // Logged and dropped: the webhook still answers 200, or Stripe
            // would redeliver the event and repeat its other effects.
            $errorLog = new LogError();
            $errorLog->errorNumber = 365;
            $errorLog->errorMsg = 'Billing webhook email failed';
            $errorLog->badData = $tag . ' / customer: ' . $stripeCustomerID;
            $errorLog->filename = 'API / Billing.class.php';
            $errorLog->write();
        }
    }

    // The charge rows an invoice covers, with their status before this event
    private function chargesForInvoice($stripeInvoiceID){
        $rows = array();
        if(empty($stripeInvoiceID)){
            return $rows;
        }
        $db = new Database();
        $result = $db->query("SELECT year, month, status FROM billing_charges WHERE stripeInvoiceID=? ORDER BY year, month", [$stripeInvoiceID]);
        if(!$db->error){
            while($row = $result->fetch_assoc()){
                $rows[] = $row;
            }
        }
        $db->close();
        return $rows;
    }

    /* ----- Router ----- */

    public function api($method, $function, $id, $apiKey, $data){
        switch($method){
            case 'GET':
                switch($function){
                    case '':
                    case null:
                        $this->status($apiKey);
                        break;
                    default:
                        $this->invalidFunction($function);
                }
                break;
            case 'POST':
                switch($function){
                    case 'checkout-session':
                        $this->checkoutSession($apiKey, $data);
                        break;
                    case 'portal-session':
                        $this->portalSession($apiKey, $data);
                        break;
                    default:
                        $this->invalidFunction($function);
                }
                break;
            case 'PATCH':
                switch($function){
                    case '':
                    case null:
                        $this->updateSpendCap($apiKey, $data);
                        break;
                    default:
                        $this->invalidFunction($function);
                }
                break;
            case 'DELETE':
                switch($function){
                    case '':
                    case null:
                        $this->disableBilling($apiKey);
                        break;
                    default:
                        $this->invalidFunction($function);
                }
                break;
            default:
                // Unsupported Method - Method Not Allowed
                $this->error = true;
                $this->errorMsg = 'Invalid HTTP method for this endpoint.';
                $this->responseCode = 405;
                $this->responseHeader = 'Allow: GET, POST, PATCH, DELETE';

                // Log Error
                $errorLog = new LogError();
                $errorLog->errorNumber = 281;
                $errorLog->errorMsg = 'Invalid Method (/billing)';
                $errorLog->badData = $method;
                $errorLog->filename = 'API / Billing.class.php';
                $errorLog->write();
        }

        if($this->error){
            $this->json['error'] = true;
            $this->json['error_msg'] = $this->errorMsg;
        }
    }

    private function invalidFunction($function){
        $this->error = true;
        $this->errorMsg = 'Invalid path. The URI you requested does not exist.';
        $this->responseCode = 404;

        // Log Error
        $errorLog = new LogError();
        $errorLog->errorNumber = 282;
        $errorLog->errorMsg = 'Invalid function (/billing)';
        $errorLog->badData = $function;
        $errorLog->filename = 'API / Billing.class.php';
        $errorLog->write();
    }
}
?>
