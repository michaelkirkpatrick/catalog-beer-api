<?php
/* ---
Catalog.beer API
$sendEmail = new SendEmail();
$sendEmail->email = '';
--- */

class SendEmail {

	// Variables
	public $email;
	private $postmarkServerToken = '';
	private $testing = false;

	// Validation
	public $error = false;
	public $errorMsg = null;
	public $responseCode = 200;

	public function validateEmail($email){
		// Initial State
		$validEmail = false;

		// Trim Email
		$email = trim($email);

		if(!empty($email)){
			// Not Blank
			if(filter_var($email, FILTER_VALIDATE_EMAIL)){
				if(strlen($email) <= 255){
					// Valid Email
					$validEmail = true;

					// Save to Class
					$this->email = $email;
				}else{
					// Check string length
					$this->error = true;
					$this->errorMsg = 'We apologize, your email address is a little too long for us to process. Please input an email that is less than 255 bytes in length.';
					$this->responseCode = 400;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 28;
					$errorLog->errorMsg = 'Email address > 255 characters';
					$errorLog->badData = $email;
					$errorLog->filename = 'API / SendEmail.class.php';
					$errorLog->write();
				}
			}else{
				// Invalid Email
				$this->error = true;
				$this->errorMsg = 'Sorry, the email address you provided appears to be invalid.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 29;
				$errorLog->errorMsg = 'Invliad Email. Does not pass filter_var';
				$errorLog->badData = $email;
				$errorLog->filename = 'API / SendEmail.class.php';
				$errorLog->write();
			}
		}else{
			// Invalid Email
			$this->error = true;
			$this->errorMsg = 'Sorry, we seem to be missing your email address. Please enter it.';
			$this->responseCode = 400;

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 30;
			$errorLog->errorMsg = 'No email address provided';
			$errorLog->badData = $email;
			$errorLog->filename = 'API / SendEmail.class.php';
			$errorLog->write();
		}

		// Return Status
		return $validEmail;
	}

	public function verifyEmail($email, $authCode){
		// Validate Email
		if($this->validateEmail($email)){
			// Email Basics
			$to = $email;
			$subject = 'Confirm your Catalog.beer Account';
			$tag = 'confirm-email';

			// Plain Text
			$textBody = "Hello!\r\n\r\nWelcome to the Catalog.beer community. We'd like to confirm your email address (as a proxy for you being a real human being and not a computer). If you don't mind, please click on the link below to confirm your account.\r\n\r\nhttps://catalog.beer/verify-email/$authCode\r\n\r\nOnce you've confirmed your account, you'll be able to contribute to the Catalog.beer database.\r\n\r\nThanks!\r\n\r\n-Michael\r\n\r\nMichael Kirkpatrick\r\nFounder, Catalog.beer\r\nmichael@catalog.beer";

			// HTML Email
			$htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
			$htmlBody .= file_get_contents(ROOT . '/classes/resources/email-confirm-address.html');
			$htmlBody = str_replace('##AUTHCODE##', $authCode, $htmlBody);

			// Send Email
			$this->send($to, $subject, $tag, $htmlBody, $textBody);
		}
	}
	
	public function passwordResetEmail($email, $passwordResetKey){
		// Email address already validated -- precondition of sending password reset
		// Email Basics
		$to = $email;
		$subject = 'Resetting your Catalog.beer Password';
		$tag = 'password-reset';

		// Plain Text
		$textBody = "Hello!\r\n\r\nWe received a request to reset the password for your account. To reset the password for your Catalog.beer account, click on this link:\r\n\r\nhttps://catalog.beer/password-reset/" . $passwordResetKey . "\r\n\r\nIf you didn&#8217;t request a password reset for your account, reply to this email or send a message to security@catalog.beer and we will monitor your account to ensure it stays safe.\r\n\r\n-Catalog.beer Password Reset Robot";

		// HTML Email
		$htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
		$htmlBody .= file_get_contents(ROOT . '/classes/resources/email-password-reset.html');
		$htmlBody = str_replace('##PASSWORDRESETKEY##', $passwordResetKey, $htmlBody);

		// Send Email
		$this->send($to, $subject, $tag, $htmlBody, $textBody);
	}
	
	private function send($to, $subject, $tag, $htmlBody, $textBody){
		if(!$this->testing){
			// Generate Postmark Email Body
			$postmarkSendEmail = new PostmarkSendEmail();
			$postmarkSendEmail->generateBody($to, $subject, $tag, $htmlBody, $textBody);
			$json = json_encode($postmarkSendEmail);

			// Start cURL
			$curl = curl_init();

			curl_setopt_array($curl, array(
				CURLOPT_URL => "https://api.postmarkapp.com/email",
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_HTTPHEADER => array(
					"Accept: application/json",
					"cache-control: no-cache",
					"Content-Type: application/json",
					"X-Postmark-Server-Token: " . $this->postmarkServerToken
				),
				CURLOPT_POSTFIELDS => "$json"
			));

			$response = curl_exec($curl);
			$err = curl_error($curl);

			curl_close($curl);

			if($err){
				// cURL Error
				$this->error = true;
				$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
				$this->responseCode = 500;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 128;
				$errorLog->errorMsg = 'cURL Error';
				$errorLog->badData = $err;
				$errorLog->filename = 'API / SendEmail.class.php';
				$errorLog->write();
			}else{
				// Response Received
				$decodedReponse = json_decode($response);
				if($decodedReponse->ErrorCode != 0){
					// Error Sending Email
					$this->error = true;
					$this->errorMsg = 'Sorry, there was an error sending your confirmation email. We\'ve logged the issue and our support team will look into it.';
					$this->responseCode = 500;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 129;
					$errorLog->errorMsg = 'Postmark App Error';
					$errorLog->badData = $decodedReponse;
					$errorLog->filename = 'API / SendEmail.class.php';
					$errorLog->write();
				}
			}
		}
	}
}
?>
