<?php
/*
Minimal Stripe API client. This project has no Composer, so there is no Stripe
SDK — every call is a form-encoded HTTPS request authenticated with the secret
key via HTTP Basic Auth (key as username, empty password), per
https://docs.stripe.com/api. No Stripe-Version header is sent; calls use the
account's default API version.

Also verifies webhook signatures (Stripe-Signature header) so the webhook
endpoint can run without the SDK.
*/

class Stripe {

    // Error Handling
    public $error = false;
    public $errorMsg = null;
    public $responseCode = 200;

    // Decoded body of the last response — populated even on API errors so
    // callers can inspect Stripe's error object.
    public $lastResponse = null;

    public function request($method, $path, $params = array(), $idempotencyKey = ''){
        // Reset per-call state so one instance can make multiple calls
        $this->error = false;
        $this->errorMsg = null;
        $this->lastResponse = null;

        $url = 'https://api.stripe.com' . $path;
        $headers = array();
        if(!empty($idempotencyKey)){
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        // Initialize cURL
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        switch($method){
            case 'GET':
                if(!empty($params)){
                    $url .= '?' . http_build_query($params);
                }
                break;
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                break;
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            default:
                $this->error = true;
                $this->errorMsg = "Unsupported Stripe request method: $method";
                $this->responseCode = 500;
                curl_close($curl);
                return null;
        }

        curl_setopt($curl, CURLOPT_URL, $url);
        if(!empty($headers)){
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        // Execute
        $response = curl_exec($curl);

        if(curl_errno($curl)){
            // cURL Error
            $this->error = true;
            $this->errorMsg = 'We encountered an error connecting to our payment provider. Please try again.';
            $this->responseCode = 502;

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 272;
            $errorLog->errorMsg = 'Stripe API cURL error';
            $errorLog->badData = "$method $path / " . curl_error($curl);
            $errorLog->filename = 'API / Stripe.class.php';
            $errorLog->write();

            curl_close($curl);
            return null;
        }

        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($response, true);
        $this->lastResponse = $decoded;

        if($httpStatus < 200 || $httpStatus >= 300){
            // Stripe API Error
            $this->error = true;
            $this->errorMsg = 'We encountered an error with our payment provider. Please try again.';
            $this->responseCode = 502;

            // Log Error — Stripe's own message is the useful part
            $stripeMsg = $decoded['error']['message'] ?? substr($response ?: '', 0, 500);
            $errorLog = new LogError();
            $errorLog->errorNumber = 273;
            $errorLog->errorMsg = 'Stripe API non-2xx response';
            $errorLog->badData = "$method $path / HTTP $httpStatus / $stripeMsg";
            $errorLog->filename = 'API / Stripe.class.php';
            $errorLog->write();

            return null;
        }

        return $decoded;
    }

    public function verifyWebhookSignature($payload, $sigHeader, $tolerance = 300){
        // Stripe-Signature format: "t=<timestamp>,v1=<sig>[,v1=<sig>...]"
        // Multiple v1 entries appear during webhook secret rotation.
        if(empty($sigHeader)){
            return false;
        }

        $timestamp = 0;
        $signatures = array();
        foreach(explode(',', $sigHeader) as $part){
            $pair = explode('=', trim($part), 2);
            if(count($pair) != 2){
                continue;
            }
            if($pair[0] === 't'){
                $timestamp = intval($pair[1]);
            }elseif($pair[0] === 'v1'){
                $signatures[] = $pair[1];
            }
        }

        if($timestamp <= 0 || empty($signatures)){
            return false;
        }
        if(abs(time() - $timestamp) > $tolerance){
            // Stale or future-dated — replay protection
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, STRIPE_WEBHOOK_SECRET);
        foreach($signatures as $signature){
            if(hash_equals($expected, $signature)){
                return true;
            }
        }
        return false;
    }
}
?>
