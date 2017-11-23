<?php
class Users {
	
	public $userID = '';
	public $email = '';
	private $password = '';
	public $name = '';
	public $emailAuth = '';
	public $emailAuthSent = 0;
	public $emailVerified = false;
	public $admin = false;
	
	// Validation
	public $error = false;
	public $errorMsg = '';
	public $validState = array('name'=>'', 'email'=>'', 'password'=>'', 'terms_agreement'=>'');
	public $validMsg = array('name'=>'', 'email'=>'', 'password'=>'');
	
	public function validate($userID, $saveToClass){
		// Valid
		$valid = false;
		
		// Trim
		$userID = trim($userID);
		
		if(!empty($userID)){
			// Prep for Database
			$db = new Database();
			$dbUserID = $db->escape($userID);
			
			// Query
			$db->query("SELECT email, name, emailAuth, emailVerified, emailAuthSent, admin FROM users WHERE id='$dbUserID'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid User
					$valid = true;
					
					// Save to Class?
					if($saveToClass){
						$array = $db->resultArray();
						$this->userID = $userID;
						$this->email = $array['email'];
						$this->name = $array['name'];
						if($array['emailVerified']){
							$this->emailVerified = true;
						}else{
							$this->emailVerified = false;
						}
						$this->emailAuth = $array['emailAuth'];
						$this->emailAuthSent = $array['emailAuthSent'];
						if($array['admin']){
							$this->admin = true;	
						}else{
							$this->admin = false;
						}
					}
				}elseif($db->result->num_rows > 1){
					// Unexpected number of results
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 5;
					$errorLog->errorMsg = 'Unexpected number of results';
					$errorLog->badData = $userID;
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing userID
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 4;
			$errorLog->errorMsg = 'Missing userID';
			$errorLog->badData = $userID;
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
		
		// Return
		return $valid;
	}
	
	public function createAccount($name, $email, $password, $termsAgreement, $userID){
		// Verify Permission to Create Account
		$this->validate($userID, true);
		
		if($this->admin){
			// Has Permission to Create Accounts
			$this->resetVars();
		
			// Save to Class
			$this->name = $name;
			$this->email = $email;
			$this->password = $password;

			// Validate Parameters
			$this->validateName();
			$this->validateEmail();
			if($this->error){
				//$this->validMsg['email'] .= ' Perhaps you want to [reset your password](/password-reset.php) or [sign in](/login.php)?';
				$this->validMsg['email'] .= ' Perhaps you want to or [sign in](/login)?';
			}
			$this->validatePassword();
			
			// Agreed to terms?
			if($termsAgreement){
				$this->validState['terms_agreement'] = 'valid';
			}else{
				$this->error = true;
				$this->errorMsg = 'Before we can create your account, you will need to agree to the Terms and Conditions by checking the box below.';
				$this->validState['terms_agreement'] = 'invalid';
			}

			// Generate userID
			$uuid = new uuid();
			$this->userID = $uuid->generate('users');
			if($uuid->error){
				// userID Generation Error
				$this->error = true;
				$this->errorMsg = $uuid->errorMsg;
			}

			// Add User to Database
			if(!$this->error){
				// Hash Password
				$passwordHash = password_hash($this->password, PASSWORD_DEFAULT);

				// Clear Saved Password
				$this->password = '';

				// Prepare for Database
				$db = new Database();
				$dbUserID = $db->escape($this->userID);
				$dbName = $db->escape($this->name);
				$dbEmail = $db->escape($this->email);
				$dbPasswordHash = $db->escape($passwordHash);

				// Add to Database
				$query = "INSERT INTO users (id, email, passwordHash, name, admin, emailVerified) VALUES ('$dbUserID', '$dbEmail', '$dbPasswordHash', '$dbName', '0', '0')";
				$db->query($query);
				if(!$db->error){
					// Send email confirmation
					$sendEmail = new SendEmail();
					$this->emailAuth = $sendEmail->verifyEmail($this->email);
					$this->emailAuthSent = time();

					if(!$sendEmail->error){
						// Update Database
						$dbEmailAuth = $db->escape($this->emailAuth);
						$dbEmailAuthSent = $db->escape($this->emailAuthSent);
						$query = "UPDATE users SET emailAuth='$dbEmailAuth', emailAuthSent='$dbEmailAuthSent' WHERE id='$dbUserID'";
						$db->query($query);
						if($db->error){
							// Error Updating Email Info
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
						}
					}else{
						// Email Verification Error
						$this->error = true;
						$this->errorMsg = $sendEmail->errorMsg;
					}
				}else{
					// Query Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
				}
			}
		}else{
			// Not an admin, can't create new account
			$this->error = true;
			$this->errorMsg = 'Sorry, your account does not have permission to perform this action.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 35;
			$errorLog->errorMsg = 'Non-Admin trying to create account';
			$errorLog->badData = "UserID: $userID";
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
	
	private function validateName(){
		// Trim Name
		$this->name = trim($this->name);
		
		if(!empty($this->name)){
			if(strlen($this->name) <= 255){
				// Valid Name
				$this->validState['name'] = 'valid';
			}else{
				// Name too long
				$this->validState['name'] = 'invalid';
				$this->validMsg['name'] = 'We apologize, your name is a little too long for our database. Please input a name that is less than 255 bytes.';
				$this->error = true;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 26;
				$errorLog->errorMsg = 'Name greater than 255 bytes in length';
				$errorLog->badData = $this->name;
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Name
			$this->validState['name'] = 'invalid';
			$this->validMsg['name'] = "What's your name? We seem to be missing that piece of information.";
			$this->error = true;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = CURLE_FTP_WEIRD_227_FORMAT;
			$errorLog->errorMsg = 'Missing Name';
			$errorLog->badData = $this->name;
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
	
	private function validateEmail(){
		// Valid Email?
		$sendEmail = new SendEmail();
		if($sendEmail->validateEmail($this->email)){
			// Save Email
			$this->email = $sendEmail->email;
			
			// Does user already exist?
			$db = new Database();
			$dbEmail = $db->escape($this->email);
			$db->query("SELECT id FROM users WHERE email='$dbEmail'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Email exists
					$emailUserID = $db->singleResult('id');
					
					if($this->userID == $emailUserID){
						// Email assigned to this user, valid
						$this->validState['email'] = 'valid';
					}else{
						// Someone else has already registered this email
						$this->error = true;
						$this->validState['email'] = 'invalid';
						$this->validMsg['email'] = 'Sorry, someone has already created an account with this email addresses.';
					}
				}elseif($db->result->num_rows == 0){
					// Valid, new email address
					$this->validState['email'] = 'valid';
				}
			}else{
				// Database Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Invalid Email
			$this->error = true;
			$this->validState['email'] = 'invalid';
			$this->validMsg['email'] = $sendEmail->errorMsg;
		}
	}
	
	private function validatePassword(){
		// Trim
		$this->password = trim($this->password);
		
		// Check Password
		if(!empty($this->password)){
			if(strlen($this->password) >= 8){
				$commonPasswords = array_map('str_getcsv', file(ROOT . '/classes/resources/common-passwords.csv'));
				if(!in_array($this->password, $commonPasswords[0])){
					// Valid Password
					$this->validState['password'] = 'valid';
				}else{
					// Common Password
					$this->error = true;
					$this->validState['password'] = 'invalid';
					$this->validState['password'] = 'Please use a different password. That password is common and easily guessed.';// [Need some help with a better password?](/support/6256)';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 31;
					$errorLog->errorMsg = 'Common Password';
					$errorLog->badData = 'Password given: ' . $this->password;
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
				}
			}else{
				// Short Password
				$this->error = true;
				$this->validState['password'] = 'invalid';
				$this->validMsg['password'] = 'Please enter a password that is at least eight (8) characters in length.';// [Need some help with a better password?](/support/6256)';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 32;
				$errorLog->errorMsg = 'Short Password';
				$errorLog->badData = 'Number of characters: ' . strlen($this->password);
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}
		}else{
			// Empty Password
			$this->error = true;
			$this->validState['password'] = 'invalid';
			$this->validMsg['password'] = 'Please enter a password.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 33;
			$errorLog->errorMsg = 'Missing password';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
	
	private function resetVars(){
		$this->userID = '';
		$this->email = '';
		$this->password = '';
		$this->name = '';
		$this->emailAuth = '';
		$this->emailAuthSent = 0;
		$this->emailVerified = false;
		$this->admin = false;
	}
	
	public function login($email, $password){
		// Successful Login?
		$success = false;
		
		// Validate Email
		$sendEmail = new SendEmail();
		if($sendEmail->validateEmail($email)){
			// Validate Password
			if(!empty($password)){
				// Prep for Database
				$db = new Database();
				$dbEmail = $db->escape($email);

				// Query Database
				$query = "SELECT id, passwordHash FROM users WHERE email='$dbEmail'";
				$db->query($query);
				if(!$db->error){
					if($db->result->num_rows == 1){
						// Parse password from result
						$array = $db->resultArray();

						// Check Password against Hash
						if(password_verify($password, $array['passwordHash'])){
							// Successful Login
							$success = true;
							
							// Save to class
							$this->validate($array['id'], true);
						}else{
							// Wrong Password
							$this->validState['password'] = 'invalid';
							$this->validMsg['password'] = 'Incorrect password. Please check your password and try again.';
							$this->error = true;

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 44;
							$errorLog->errorMsg = 'Wrong Password';
							$errorLog->badData = 'Given Email: ' . $email;
							$errorLog->filename = 'Users.classphp';
							$errorLog->write();
						}
					}elseif($db->result->num_rows > 1){
						// Email in database twice
						$this->validState['email'] = 'invalid';
						$this->validMsg['email'] = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
						$this->error = true;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 42;
						$errorLog->errorMsg = 'Email in database twice';
						$errorLog->badData = 'Given Email: ' . $email . ' Query: ' . $query;
						$errorLog->filename = 'API / Users.class.php';
						$errorLog->write();
					}else{
						// Email address not found
						$this->validState['email'] = 'invalid';
						$this->validMsg['email'] = 'Sorry, we couldn\'t find your account. Would you like to [create one](/signup)?';
						$this->error = true;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 43;
						$errorLog->errorMsg = 'Account not found';
						$errorLog->badData = 'Given Email: ' . $email;
						$errorLog->filename = 'API / Users.class.php';
						$errorLog->write();	
					}
				}else{
					// Query Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
				}
			}else{
				// No Password Given
				$this->validState['password'] = 'invalid';
				$this->validMsg['password'] = 'Please enter a password';
				$this->error = true;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 41;
				$errorLog->errorMsg = 'Missing password';
				$errorLog->badData = "email: $email";
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}
		}else{
			// Invalid Email
			$this->error = true;
			$this->validState['email'] = 'invalid';
			$this->validMsg['email'] = $sendEmail->errorMsg;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 40;
			$errorLog->errorMsg = 'Invalid Email';
			$errorLog->badData = $email;
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
		
		// Return
		return $success;
	}
	
	public function verifyEmail($emailAuth){
		if(!empty($emailAuth)){
			$db = new Database();
			$dbEmailAuth = $db->escape($emailAuth);
			$db->query("SELECT id FROM users WHERE emailAuth='$dbEmailAuth'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid Email Auth, Update Database
					$userID = $db->singleResult('id');
					$dbUserID = $db->escape($userID);
					$db->query("UPDATE users SET emailVerified='1', emailAuth='', emailAuthSent='0' WHERE id='$dbUserID'");
					if(!$db->error){
						// Validate User
						$this->validate($userID, true);
						
						// Create API Key
						$apiKeys = new apiKeys();
						$apiKeys->add($userID);
					}else{
						// Query Error
						$this->error = true;
						$this->errorMsg = $db->errorMsg;
					}
				}elseif($db->result->num_rows > 1){
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 84;
					$errorLog->errorMsg = 'Duplicate emailAuth';
					$errorLog->badData = "emailAuth: $emailAuth";
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
				}else{
					// Invalid Email Authentication Code
					$this->error = true;
					$this->errorMsg = 'Sorry, this appears to be an invalid email verification code. Please double check that you have clicked on the link in your email or have copy and pasted the entirety of the link into your browser.';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 83;
					$errorLog->errorMsg = 'Invalid emailAuth';
					$errorLog->badData = "emailAuth: $emailAuth";
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing Email Authentication Code
			$this->error = true;
			$this->errorMsg = 'Sorry, we seem to be missing your email authentication code. Try clicking on the link in your email again.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 82;
			$errorLog->errorMsg = 'Missing emailAuth';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
}
?>