<?php
/*
One row per authenticated request (index.php; master keys, /usage and
/billing excepted): who (key, IP, user agent), what (method, URI, status,
duration) and when. This is the metering evidence behind api_usage, the
abuse-forensics trail and the demand metrics -- a rolling 3-month window
(cron/prune-api-logging.php), not history. What a write CHANGED lives in
audit_log (Audit.class.php) forever; so since 6.7.0 a successful write stores
neither its body nor its response here, only a refused one keeps them.

$apiLogging = new apiLogging();
$apiLogging->add($apiKey, $method, $uri, $body, $response, $responseCode);
*/

class apiLogging {

    // Variables
    public $id = '';
    public $apiKey = '';
    public $timestamp = 0;
    public $ipAddress = '';
    public $userAgent = null;
    public $method = '';
    public $uri = '';
    public $body = '';
    public $response = '';
    public $responseCode = 0;
    public $durationMs = null;

    // Column widths the INSERT must respect (strict mode refuses a longer value,
    // and a refused INSERT is a request that vanishes from the metering)
    const URI_MAX = 255;
    const USER_AGENT_MAX = 255;

    // Field names redacted from a logged body or response
    private static $sensitiveKeys = [
        'password',
        'new_password',
        'current_password',
        'confirm_password',
        'password_reset_key',
    ];

    // Error Handling
    private $error = false;

    public function add($apiKey, $method, $uri, $body, $response, $responseCode){
        // Check for missing variables
        if(!empty($apiKey) && !empty($method) && !empty($uri)){
            // Generate UUID
            $uuid = new uuid();
            $this->id = $uuid->generate('api_logging');
            if($uuid->error){
                $this->error = true;
            }

            // Save to Class
            $this->apiKey = $apiKey;
            $this->timestamp = time();
            $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->userAgent = self::userAgent($_SERVER['HTTP_USER_AGENT'] ?? null);
            $this->method = $method;
            $this->uri = self::loggedUri($uri);
            $this->body = self::loggedBody($method, $responseCode, $body);
            $this->response = self::loggedResponse($method, $responseCode, $response);
            $this->responseCode = $responseCode;
            $this->durationMs = self::durationMs($_SERVER['REQUEST_TIME_FLOAT'] ?? null);

            if(!$this->error){
                // Insert
                $db = new Database();
                $db->query("INSERT INTO api_logging (id, apiKey, timestamp, ipAddress, userAgent, method, uri, body, response, responseCode, durationMs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$this->id, $this->apiKey, $this->timestamp, $this->ipAddress, $this->userAgent, $this->method, $this->uri, $this->body, $this->response, $this->responseCode, $this->durationMs]);
                if($db->error){
                    $this->error = true;
                }
                $db->close();
            }
        }else{
            // Missing required attribute
            $this->error = true;
            $errorLog = new LogError();
            $errorLog->errorNumber = 48;
            $errorLog->errorMsg = 'Missing required parameter';
            $errorLog->badData = "apiKey: $apiKey / method: $method / uri: $uri / body: " . self::scrubSensitive($body);
            $errorLog->filename = 'API / apiLogging.class.php';
            $errorLog->write();
        }
    }

    /* ----- What each column keeps (pure; tests/api-logging.php) ----- */

    // The request body: only for a write the API REFUSED, where it is the one
    // record of what was attempted. A GET has none, and a successful write's
    // outcome is the audit_log snapshot, so the body would only duplicate it
    // (it was 45 MB of a 310 MB table in Nov 2026). Scrubbed of passwords.
    public static function loggedBody($method, $responseCode, $body): string {
        if($method == 'GET' || ($responseCode >= 200 && $responseCode < 300)){
            return '';
        }
        return self::scrubSensitive($body);
    }

    // The response: only an error body (small, and the only record of WHY a
    // write was refused). Nothing read a successful write's response once
    // /activity moved onto audit_log, and a GET's was never stored.
    public static function loggedResponse($method, $responseCode, $response): string {
        if($method == 'GET' || ($responseCode >= 200 && $responseCode < 300)){
            return '';
        }
        return self::scrubSensitive($response);
    }

    // The URI with a one-time token taken out of the path (the body scrubber
    // never saw the path), cut to the column's width so a long query string
    // can never make the INSERT fail.
    public static function loggedUri($uri): string {
        $uri = preg_replace('#(/users/(?:password-reset|verify-email)/)[^/?]+#', '$1[REDACTED]', (string)$uri);
        return mb_strcut($uri, 0, self::URI_MAX);
    }

    // The user agent, trimmed, control characters out, cut to the column; null
    // when the client sent none.
    public static function userAgent($userAgent): ?string {
        if($userAgent === null){
            return null;
        }
        $userAgent = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$userAgent));
        if($userAgent === ''){
            return null;
        }
        return mb_strcut($userAgent, 0, self::USER_AGENT_MAX);
    }

    // Whole milliseconds since the request started (REQUEST_TIME_FLOAT, which
    // Apache/FPM set before PHP ran a line); null under the CLI.
    public static function durationMs($requestStart, $now = null): ?int {
        if(!is_numeric($requestStart)){
            return null;
        }
        $ms = (int)round((($now ?? microtime(true)) - (float)$requestStart) * 1000);
        return max(0, $ms);
    }

    // Redact known-sensitive fields from a JSON body or response before storage.
    private static function scrubSensitive($payload): string {
        if($payload === null || $payload === ''){
            return '';
        }

        $payloadStr = is_string($payload) ? $payload : json_encode($payload);
        $decoded = json_decode($payloadStr, true);

        // Not a JSON object/array — nothing structured to scrub.
        if(!is_array($decoded)){
            return $payloadStr;
        }

        return json_encode(self::redactKeys($decoded));
    }

    private static function redactKeys(array $data): array {
        foreach($data as $key => &$value){
            if(in_array($key, self::$sensitiveKeys, true)){
                $value = '[REDACTED]';
            }elseif(is_array($value)){
                $value = self::redactKeys($value);
            }
        }
        return $data;
    }
}
