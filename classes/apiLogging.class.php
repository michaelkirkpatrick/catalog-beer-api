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
            }else{
                // Save the Response
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
