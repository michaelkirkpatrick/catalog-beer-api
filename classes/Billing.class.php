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
        $this->json['api_key'] = $apiKey;
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
        $this->json['api_key'] = $apiKey;
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
        $this->json['api_key'] = $apiKey;
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
                // settings; billing stays enabled until it gives up.
                $this->updateChargeStatus($object['id'] ?? '', 'failed');

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

    public function billMonthlyUsage(){
        // Bill the previous calendar month (America/Los_Angeles, matching how
        // api_usage buckets months). Safe to re-run: charge rows INSERT
        // IGNORE against a unique (apiKey, year, month) key, invoice items
        // are only created for rows without a stripeInvoiceItemID, and
        // Stripe calls carry idempotency keys.
        $ts = strtotime('first day of last month');
        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $monthLabel = date('F Y', $ts);
        $now = time();

        // The $5 floor is waived when billing December so no balance rolls
        // across years.
        $annualSweep = ($month == 12);

        $db = new Database();
        if($db->error){
            return;
        }

        // 1) Record an overage charge row per billing-enabled key that went
        //    past its free tier last month.
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
            }else{
                $recorded++;
            }
        }
        echo "Recorded overage for $monthLabel: $recorded key(s)\n";

        // 2) Invoice every key whose pending total has reached the floor
        //    (or everything, on the annual sweep). One invoice item per
        //    pending month keeps the line items readable.
        $pending = array();
        $result = $db->query("SELECT bc.id, bc.apiKey, bc.year, bc.month, bc.totalRequests, bc.billableRequests, bc.amountCents, bc.stripeInvoiceItemID, u.stripeCustomerID FROM billing_charges bc INNER JOIN api_keys ak ON bc.apiKey = ak.id INNER JOIN users u ON bc.userID = u.id WHERE bc.status='pending' AND ak.billingEnabled=1 AND u.stripeCustomerID IS NOT NULL ORDER BY bc.apiKey, bc.year, bc.month");
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
            if($totalCents < self::INVOICE_FLOOR_CENTS && !$annualSweep){
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
                $chargeMonthLabel = date('F Y', mktime(0, 0, 0, intval($charge['month']), 1, intval($charge['year'])));
                $blocks = intdiv(intval($charge['amountCents']), self::BLOCK_PRICE_CENTS);
                $description = 'Catalog.beer API — ' . $chargeMonthLabel . ': ' . number_format($charge['totalRequests']) . ' requests (' . number_format($charge['billableRequests']) . ' over your ' . number_format($charge['totalRequests'] - $charge['billableRequests']) . ' free requests; ' . $blocks . ' × 1,000-request block at $1.00)';
                $item = $stripe->request('POST', '/v1/invoiceitems', array(
                    'customer' => $customerID,
                    'amount' => intval($charge['amountCents']),
                    'currency' => 'usd',
                    'description' => $description
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

            // Create and auto-advance the invoice; Stripe charges the default
            // payment method and emails the receipt.
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
        echo "Invoiced: $invoiced key(s); deferred under \$5 floor: $deferred key(s)\n";
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
