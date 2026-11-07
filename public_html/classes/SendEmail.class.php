<?php
/* ---
Catalog.beer API
$sendEmail = new SendEmail();
$sendEmail->email = '';
--- */

class SendEmail {

    // Billing emails reply here (a Gmail-hosted inbox)
    const BILLING_REPLY_TO = 'Catalog.beer Billing <billing@catalog.beer>';

    // Variables
    public $email;

    private $testing = false;

    // Account-security mail (an email change) replies here
    const SECURITY_REPLY_TO = 'Catalog.beer Security <security@catalog.beer>';

    // The offline tests' outbox: when this is an array, send() appends the
    // message to it and makes no request. Null (always, on a server) sends.
    public static $outbox = null;

    // Validation
    public $error = false;
    public $errorMsg = null;
    public $responseCode = 200;

    public function validateEmail($email){
        // Initial State
        $validEmail = false;

        // Trim Email
        $email = trim($email ?? '');

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
            $host = self::siteHost();

            // Plain Text
            $textBody = "Hello!\r\n\r\nWelcome to the Catalog.beer community. We'd like to confirm your email address (as a proxy for you being a real human being and not a computer). If you don't mind, please click on the link below to confirm your account.\r\n\r\nhttps://$host/verify-email/$authCode\r\n\r\nOnce you've confirmed your account, you'll be able to contribute to the Catalog.beer database.\r\n\r\nThanks!\r\n\r\n-Michael\r\n\r\nMichael Kirkpatrick\r\nFounder, Catalog.beer\r\nmichael@catalog.beer";

            // HTML Email
            $htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
            $htmlBody .= file_get_contents(ROOT . '/classes/resources/email-confirm-address.html');
            $htmlBody = str_replace(array('##HOST##', '##AUTHCODE##'), array($host, $authCode), $htmlBody);

            // Send Email
            $this->send($to, $subject, $tag, $htmlBody, $textBody);
        }
    }
    
    // m***@example.com: enough for the owner to recognise their address,
    // not enough to hand it to whoever received the message.
    public static function maskEmail($email){
        $at = strrpos((string)$email, '@');
        if($at === false || $at === 0){
            return '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    // The website's host for links in mail, so a staging message opens staging.
    private static function siteHost(){
        return (defined('ENVIRONMENT') && ENVIRONMENT == 'staging' ? 'staging.' : '') . 'catalog.beer';
    }

    // A verified account asked to move to $email. Goes to the NEW address and
    // names the account by its masked current one, so a stranger who receives
    // it can tell the account is not theirs. Confirming needs a sign-in on
    // the website (Users::confirmEmail()).
    public function confirmNewEmail($email, $authCode, $currentEmail){
        if($this->validateEmail($email)){
            $subject = 'Confirm your new email address for Catalog.beer';
            $tag = 'confirm-new-email';
            $masked = self::maskEmail($currentEmail);
            $host = self::siteHost();

            // Plain Text
            $textBody = "Hello!\r\n\r\nWe received a request to change the email address on the Catalog.beer account for $masked to this address. To confirm the change, open this link:\r\n\r\nhttps://$host/confirm-email/$authCode\r\n\r\nIf you aren't already signed in, you'll be asked to sign in first. Use the account's current email address, not this one. The link works for 24 hours.\r\n\r\nIf you didn't request this, you can safely ignore this email; nothing will change. If you have any questions about your account, feel free to email security@catalog.beer.\r\n\r\n-Catalog.beer";

            // HTML Email
            $htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
            $htmlBody .= file_get_contents(ROOT . '/classes/resources/email-confirm-new-address.html');
            $htmlBody = str_replace(
                array('##CURRENT##', '##HOST##', '##AUTHCODE##'),
                array(htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'), $host, $authCode),
                $htmlBody
            );

            // Send Email
            $this->send($email, $subject, $tag, $htmlBody, $textBody, [], self::SECURITY_REPLY_TO);
        }
    }

    // The change went through. Goes to the OLD address: the one place the
    // owner still hears about it if the change was not theirs.
    public function emailChanged($oldEmail, $newEmail, $when){
        $subject = 'Your Catalog.beer email address was changed';
        $tag = 'email-changed';
        $whenText = date('F j, Y \\a\\t g:i A T', $when);

        // Plain Text
        $textBody = "Hello!\r\n\r\nThe email address on your Catalog.beer account was changed from $oldEmail to $newEmail on $whenText. From now on you sign in with the new address, and messages about your account go there.\r\n\r\nIf you made this change, there's nothing more to do.\r\n\r\nIf you didn't, reply to this email or write to security@catalog.beer right away and we'll help you get your account back.\r\n\r\n-Catalog.beer";

        // HTML Email
        $htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
        $htmlBody .= file_get_contents(ROOT . '/classes/resources/email-address-changed.html');
        $htmlBody = str_replace(
            array('##HOST##', '##OLD##', '##NEW##', '##WHEN##'),
            array(self::siteHost(), htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8'), htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8'), $whenText),
            $htmlBody
        );

        // Send Email
        $this->send($oldEmail, $subject, $tag, $htmlBody, $textBody, [], self::SECURITY_REPLY_TO);
    }

    public function passwordResetEmail($email, $passwordResetKey){
        // Email address already validated -- precondition of sending password reset
        // Email Basics
        $to = $email;
        $subject = 'Resetting your Catalog.beer Password';
        $tag = 'password-reset';
        $host = self::siteHost();

        // Plain Text
        $textBody = "Hello!\r\n\r\nWe received a request to reset the password for your account. To reset the password for your Catalog.beer account, click on this link:\r\n\r\nhttps://$host/password-reset/" . $passwordResetKey . "\r\n\r\nIf you didn't request this, you can safely ignore this email; your password will not be changed. If you have any questions about your account, feel free to email security@catalog.beer.\r\n\r\n-Catalog.beer Password Reset Robot";

        // HTML Email
        $htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
        $htmlBody .= file_get_contents(ROOT . '/classes/resources/email-password-reset.html');
        $htmlBody = str_replace(array('##HOST##', '##PASSWORDRESETKEY##'), array($host, $passwordResetKey), $htmlBody);

        // Send Email
        $this->send($to, $subject, $tag, $htmlBody, $textBody);
    }
    
    public function errorDigest($htmlContent, $textBody, $count, $date){
        // Email Basics
        $to = 'michael@catalog.beer';
        $subject = "Weekly Error Digest: $count errors — $date";
        $tag = 'error-digest';

        // HTML Email
        $htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
        $htmlBody .= file_get_contents(ROOT . '/classes/resources/email-error-digest.html');
        $htmlBody = str_replace(array('##HOST##', '##CONTENT##'), array(self::siteHost(), $htmlContent), $htmlBody);

        // Send Email
        $this->send($to, $subject, $tag, $htmlBody, $textBody);
    }

    public function phpErrorDigest($htmlContent, $textBody, $count, $date, $attachments = []){
        // Email Basics
        $to = 'michael@catalog.beer';
        $subject = "Weekly PHP Error Digest: $count errors — $date";
        $tag = 'php-error-digest';

        // HTML Email
        $htmlBody = file_get_contents(ROOT . '/classes/resources/email-head.html');
        $htmlBody .= file_get_contents(ROOT . '/classes/resources/email-error-digest.html');
        $htmlBody = str_replace(array('##HOST##', '##CONTENT##'), array(self::siteHost(), $htmlContent), $htmlBody);

        // Send Email
        $this->send($to, $subject, $tag, $htmlBody, $textBody, $attachments);
    }

    // Billing emails (Billing.class.php): the notice on the 1st, a failed
    // payment, billing turned off, a card that failed verification. Stripe
    // sends the receipt itself. $template names a body file in
    // classes/resources/; $replacements maps ##TOKEN## => value, already
    // escaped by the caller. Replies go to the billing inbox. $preheader is
    // the line inbox lists show after the subject: a hidden first element of
    // the body, padded so the visible text doesn't run on into the preview.
    public function billingEmail($to, $subject, $tag, $template, $replacements, $textBody, $preheader = ''){
        $htmlBody = file_get_contents(ROOT . '/classes/resources/email-billing-head.html');
        $htmlBody .= file_get_contents(ROOT . '/classes/resources/' . $template);
        $htmlBody = str_replace(array_keys($replacements), array_values($replacements), $htmlBody);
        if($preheader !== ''){
            $hidden = '<div style="display:none;font-size:1px;color:#f6f6f6;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
                . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . str_repeat('&#847;&zwnj;&nbsp;', 60) . '</div>';
            $htmlBody = preg_replace_callback('/<body[^>]*>/', fn($m) => $m[0] . $hidden, $htmlBody, 1);
        }

        $this->send($to, $subject, $tag, $htmlBody, $textBody, [], self::BILLING_REPLY_TO);
        return !$this->error;
    }

    private function send($to, $subject, $tag, $htmlBody, $textBody, $attachments = [], $replyTo = ''){
        if(is_array(self::$outbox)){
            self::$outbox[] = array('to' => $to, 'subject' => $subject, 'tag' => $tag, 'html' => $htmlBody, 'text' => $textBody, 'replyTo' => $replyTo);
            return;
        }
        if(!$this->testing){
            // Generate Postmark Email Body
            $postmarkSendEmail = new PostmarkSendEmail();
            $postmarkSendEmail->generateBody($to, $subject, $tag, $htmlBody, $textBody, $attachments, $replyTo);
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
                    "X-Postmark-Server-Token: " . POSTMARK_SERVER_TOKEN
                ),
                CURLOPT_POSTFIELDS => "$json"
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);


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
