<?php
class USPSAuth {

	// Static Token Cache
	private static $accessToken = null;
	private static $tokenExpiry = 0;

	// Get Access Token
	public static function getAccessToken(){
		// Check if we have a valid cached token (with 60-second safety margin)
		if(self::$accessToken !== null && time() < (self::$tokenExpiry - 60)){
			return self::$accessToken;
		}

		// Request new token via Client Credentials flow
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => USPS_API_BASE_URL . '/oauth2/v3/token',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'grant_type' => 'client_credentials',
				'client_id' => USPS_CLIENT_ID,
				'client_secret' => USPS_CLIENT_SECRET
			)),
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/x-www-form-urlencoded'
			),
		));

		$response = curl_exec($curl);
		$err = curl_error($curl);

		curl_close($curl);

		if($err){
			// cURL Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 221;
			$errorLog->errorMsg = 'USPS OAuth cURL Error';
			$errorLog->badData = $err;
			$errorLog->filename = 'API / USPSAuth.class.php';
			$errorLog->write();

			return null;
		}

		// Parse Response
		$responseData = json_decode($response, true);

		if(isset($responseData['access_token'])){
			// Cache Token
			self::$accessToken = $responseData['access_token'];
			self::$tokenExpiry = time() + intval($responseData['expires_in'] ?? 3600);

			return self::$accessToken;
		}else{
			// Token Request Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 222;
			$errorLog->errorMsg = 'USPS OAuth Token Request Error';
			$errorLog->badData = $response;
			$errorLog->filename = 'API / USPSAuth.class.php';
			$errorLog->write();

			return null;
		}
	}
}
?>
