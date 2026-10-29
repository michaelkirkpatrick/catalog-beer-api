<?php
/*
$apiLogging = new apiLogging();
$apiLogging->add($apiKey, $method, $uri, $body, $response, $responseCode);
*/

class apiLogging {

    // Variables
    public $id = '';
    public $apiKey = '';
    public $timestamp = 0;
    public $ipAddress = '';
    public $method = '';
    public $uri = '';
    public $body = '';
    public $response = '';
    public $responseCode = 0;

    // Field names redacted from logged body and response
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
            $this->ipAddress = $_SERVER['REMOTE_ADDR'];
            $this->method = $method;
            $this->uri = $uri;
            $this->body = $this->scrubSensitive($body);
            if($method == 'GET'){
                // Don't save the response (Memory Issues with large requests)
                $this->response = '';
            }elseif($responseCode >= 200 && $responseCode < 300){
                // A successful write echoes the whole entity back. Keep only
                // what /activity reads from it (the resource's id and name and
                // its brewer's) — the full object was 1.1 KB a row, and two
                // bulk-load months of POST /beer put 150 MB of them in this
                // table. The write itself is the catalog's record; this is not
                // an audit log (see Claude Ideas/audit-log.md).
                $this->response = self::responseStub($response);
            }else{
                // Save the error response: small, and the only record of why
                // an external caller's write was refused.
                $this->response = $this->scrubSensitive($response);
            }
            $this->responseCode = $responseCode;

            if(!$this->error){
                // Insert
                $db = new Database();
                $db->query("INSERT INTO api_logging (id, apiKey, timestamp, ipAddress, method, uri, body, response, responseCode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [$this->id, $this->apiKey, $this->timestamp, $this->ipAddress, $this->method, $this->uri, $this->body, $this->response, $this->responseCode]);
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
            $errorLog->badData = "apiKey: $apiKey / method: $method / uri: $uri / body: " . $this->scrubSensitive($body);
            $errorLog->filename = 'API / apiLogging.class.php';
            $errorLog->write();
        }
    }

    /*--
    Reduce a successful write's response to the keys Activity.class.php reads
    back out of it: id, name, and the nested brewer's id and name (a beer or
    location response carries its parent). Anything else — the description,
    the address, the style, the valid_state block — is already in the entity
    row and was only ever duplicated here. Returns '' when the response holds
    none of those keys (a DELETE, or a non-entity response), so the column
    stays empty rather than storing "[]".
    --*/
    public static function responseStub($response): string {
        if($response === null || $response === ''){
            return '';
        }
        $decoded = json_decode(is_string($response) ? $response : json_encode($response), true);
        if(!is_array($decoded)){
            return '';
        }

        $stub = array();
        foreach(array('id', 'name') as $key){
            if(isset($decoded[$key]) && is_scalar($decoded[$key])){
                $stub[$key] = $decoded[$key];
            }
        }
        if(isset($decoded['brewer']) && is_array($decoded['brewer'])){
            foreach(array('id', 'name') as $key){
                if(isset($decoded['brewer'][$key]) && is_scalar($decoded['brewer'][$key])){
                    $stub['brewer'][$key] = $decoded['brewer'][$key];
                }
            }
        }

        return empty($stub) ? '' : json_encode($stub);
    }

    // Redact known-sensitive fields from a JSON body or response before storage.
    private function scrubSensitive($payload): string {
        if($payload === null || $payload === ''){
            return '';
        }

        $payloadStr = is_string($payload) ? $payload : json_encode($payload);
        $decoded = json_decode($payloadStr, true);

        // Not a JSON object/array — nothing structured to scrub.
        if(!is_array($decoded)){
            return $payloadStr;
        }

        return json_encode($this->redactKeys($decoded));
    }

    private function redactKeys(array $data): array {
        foreach($data as $key => &$value){
            if(in_array($key, self::$sensitiveKeys, true)){
                $value = '[REDACTED]';
            }elseif(is_array($value)){
                $value = $this->redactKeys($value);
            }
        }
        return $data;
    }
}
