<?php
class Users {
	
	// Properties
	public $userID = '';
	public $email = '';
	private $password = '';
	public $name = '';
	public $emailAuth = '';
	public $emailAuthSent = 0;
	public $emailVerified = false;
	public $passwordResetSent = 0;
	public $passwordResetKey = '';
	public $admin = false;
	
	// Validation
	public $error = false;
	public $errorMsg = null;
	public $validState = array('name'=>null, 'email'=>null, 'password'=>null, 'terms_agreement'=>null);
	public $validMsg = array('name'=>null, 'email'=>null, 'password'=>null);
	
	// API Response
	public $responseHeader = '';
	public $responseCode = 200;
	public $json = array();
	
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
						$this->emailAuthSent = intval($array['emailAuthSent']);
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
					$this->responseCode = 500;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 5;
					$errorLog->errorMsg = 'Unexpected number of results';
					$errorLog->badData = $userID;
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
				}else{
					// User Does Not Exist
					$this->error = true;
					$this->errorMsg = "Sorry, we couldn't find a user with the userID you provided.";
					$this->responseCode = 401;
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 134;
					$errorLog->errorMsg = 'userID Not Found';
					$errorLog->badData = "userID: $userID";
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Missing userID
			$this->error = true;
			$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
			$this->responseCode = 500;
			
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
	
	public function createAccount($name, $email, $password, $termsAgreement, $apiKey, $method, $userID, $patchFields){
		
		// Default Values
		$sendEmailVerification = false;
		$sql = '';
		
		// Required Classes
		$apiKeys = new apiKeys();
		$db = new Database();
		$privileges = new Privileges();
		
		// Validate $apiKey & get user info for $apiKey
		$apiKeys->validate($apiKey, true);
		$this->validate($apiKeys->userID, true);
		
		// If API Key is an Admin ($this->admin) or if $userID = $apiKeys->userID
		if($this->admin || $userID == $apiKeys->userID){
			switch($method){
				case 'POST':
					// Verify Admin
					if($this->admin){
						// Reset Variables
						$this->resetVars();
						
						// Generate new userID
						$uuid = new uuid();
						$this->userID = $uuid->generate('users');
						if($uuid->error){
							// userID Generation Error
							$this->error = true;
							$this->errorMsg = $uuid->errorMsg;
							$this->responseCode = $uuid->responseCode;
						}
						
						// Agreed to terms?
						if($termsAgreement){
							$this->validState['terms_agreement'] = 'valid';
						}else{
							$this->error = true;
							$this->errorMsg = 'Before we can create your account, you will need to agree to the Terms and Conditions by checking the box below.';
							$this->validState['terms_agreement'] = 'invalid';
							$this->responseCode = 400;
						}
						
						// Save to Class
						$this->name = $name;
						$this->email = $email;
						$this->password = $password;

						// Validate Parameters
						$this->validateName();
						$this->validateEmail();
						$this->validatePassword();
						
						// Hash Password
						$passwordHash = password_hash($this->password, PASSWORD_DEFAULT);
						
						// Clear Saved Password
						$this->password = '';
						
						// Send Email Verification
						$sendEmailVerification = true;
						
						// Prepare for Database
						$db = new Database();
						$dbUserID = $db->escape($this->userID);
						$dbName = $db->escape($this->name);
						$dbEmail = $db->escape($this->email);
						$dbPasswordHash = $db->escape($passwordHash);
						$sql = "INSERT INTO users (id, email, passwordHash, name, admin, emailVerified) VALUES ('$dbUserID', '$dbEmail', '$dbPasswordHash', '$dbName', b'0', b'0')";
					}else{
						// Not Authorized
						$this->error = true;
						$this->errorMsg = 'Sorry, your account does not have permission to perform this action.';
						$this->responseCode = 403;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 175;
						$errorLog->errorMsg = 'Non-Admin trying to create an account.';
						$errorLog->badData = "userID: $apiKeys->userID";
						$errorLog->filename = 'API / Users.class.php';
						$errorLog->write();
					}
					break;
				case 'PATCH':
					if($this->validate($userID, true)){
						// Save Variables
						$originalEmail = $this->email;
						$originalEmailDomainName = $this->emailDomainName($originalEmail);
						
						// Reset Variables
						$this->resetVars();
						
						// SQL Update
						$sqlArray = array();

						// Validate Name
						if(in_array('name', $patchFields)){
							$this->name = $name;
							$this->validateName();
							if(!$this->error){
								$dbName = $db->escape($this->name);
								$sqlArray[] = "name='$dbName'";
							}
						}
						
						// Validate Email
						if(in_array('email', $patchFields)){
							// Validate Email
							$this->email = $email;
							$this->validateEmail();
							
							// Different Domain?
							$emailDomainName = $this->emailDomainName($this->email);
							if($originalEmailDomainName != $emailDomainName){
								// New Domain Name, Reset Brewery Privileges, Email Verification, API Key
								// Remove API Keys
								$apiKeys->deleteUser($userID);
								if($apiKeys->error){
									$this->error = true;
									$this->errorMsg = $apiKeys->errorMsg;
									$this->responseCode = $apiKeys->responseCode;
								}
								
								// Remove Brewery Privileges
								$privileges->deleteUser($userID);
								if($privileges->error){
									$this->error = true;
									$this->errorMsg = $privileges->errorMsg;
									$this->responseCode = $privileges->responseCode;
								}
								
								// Send New Email Verification
								$sendEmailVerification = true;
								
								// SQL - Update Email Verification
								$sqlArray[] = "emailVerified=b'0'";
							}
							if(!$this->error){
								// Update Email Address
								$dbEmail = $db->escape($this->email);
								$sqlArray[] = "email='$dbEmail'";
							}
						}
						
						// Validate Password
						if(in_array('password', $patchFields)){
							// Validate Password
							$this->password = $password;
							$this->validatePassword();
							if(!$this->error){
								// Hash Password
								$passwordHash = password_hash($this->password, PASSWORD_DEFAULT);
								
								// Clear Saved Password
								$this->password = '';
								
								// Update Password
								$dbPasswordHash = $db->escape($passwordHash);
								$sqlArray[] = "passwordHash='$dbPasswordHash'";
							}
						}
						
						if(!$this->error && !empty($sqlArray)){
							// Prep for Database
							$dbUserID = $db->escape($userID);

							// Construct SQL Statement
							$sql = "UPDATE users SET ";

							$totalUpdates = count($sqlArray);
							$lastUpdate = $totalUpdates - 1;
							for($i=0;$i<$totalUpdates; $i++){
								if($i == $lastUpdate){
									$sql .= $sqlArray[$i];
								}else{
									$sql .= $sqlArray[$i] . ", ";
								}
							}
							$sql .= " WHERE id='$dbUserID'";
						}
					}else{
						// $userID is invalid -- can't update it
						$this->error = true;
						$this->errorMsg = 'Sorry, your account does not have permission to perform this action.';
						$this->responseCode = 403;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 176;
						$errorLog->errorMsg = 'Invalid $userID for PATCH method.';
						$errorLog->badData = "userID: $userID";
						$errorLog->filename = 'API / Users.class.php';
						$errorLog->write();
					}
										
					break;
				default:
					// Invalid Method
					$this->error = true;
					$this->errorMsg = 'Invalid Method.';
					$this->responseCode = 405;

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 174;
					$errorLog->errorMsg = 'Invalid Method';
					$errorLog->badData = $method;
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
					break;
			}
			
			
			// Update Database
			if(!$this->error){
				$db->query($sql);
				if(!$db->error){
					if($sendEmailVerification){
						// Generate Email Auth Code
						$uuid = new uuid();
						$this->emailAuth = $uuid->createCode();
						
						// Send email confirmation
						$sendEmail = new SendEmail();
						$sendEmail->verifyEmail($this->email, $this->emailAuth);
						if(!$sendEmail->error){
							// Update Database
							$dbEmailAuth = $db->escape($this->emailAuth);
							$this->emailAuthSent = time();
							$dbEmailAuthSent = $db->escape($this->emailAuthSent);
							$db->query("UPDATE users SET emailAuth='$dbEmailAuth', emailAuthSent=$dbEmailAuthSent WHERE id='$dbUserID'");
							if($db->error){
								// Error Updating Email Info
								$this->error = true;
								$this->errorMsg = $db->errorMsg;
								$this->responseCode = $db->responseCode;
							}
						}else{
							// Email Verification Error
							$this->error = true;
							$this->errorMsg = $sendEmail->errorMsg;
							$this->responseCode = $sendEmail->responseCode;
						}
					}
				}else{
					// Query Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}
				
				// Close Database Connection
				$db->close();
			}
		}else{
			// Not an admin or user, can't create new account
			$this->error = true;
			$this->errorMsg = 'Sorry, your account does not have permission to perform this action.';
			$this->responseCode = 403;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 35;
			$errorLog->errorMsg = 'Unauthorized attempt to create or update an account';
			$errorLog->badData = "userID $apiKeys->userID is trying to get info on userID: $userID";
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
				$this->responseCode = 400;

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
			$this->responseCode = 400;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 110;
			$errorLog->errorMsg = 'Missing Name';
			$errorLog->badData = $this->name;
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
	
	private function validateEmail(){
		// Lowercase String
		$this->email = strtolower($this->email);
		
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
					// Someone else has already registered this email
					$this->error = true;
					$this->validState['email'] = 'invalid';
					$this->validMsg['email'] = 'Sorry, someone has already created an account with this email addresses.';
					$this->responseCode = 400;
				}elseif($db->result->num_rows == 0){
					// Valid, new email address
					$this->validState['email'] = 'valid';
				}
			}else{
				// Database Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			
			// Close Database Connection
			$db->close();
		}else{
			// Invalid Email
			$this->error = true;
			$this->validState['email'] = 'invalid';
			$this->validMsg['email'] = $sendEmail->errorMsg;
			$this->responseCode = $sendEmail->responseCode;
		}
	}
	
	private function validatePassword(){
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
					$this->validMsg['password'] = 'Please use a different password. That password is common and easily guessed.';// [Need some help with a better password?](/support/6256)';
					$this->responseCode = 400;

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
				$this->responseCode = 400;

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
			$this->responseCode = 400;
			
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
							$this->responseCode = 401;

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 44;
							$errorLog->errorMsg = 'Wrong Password';
							$errorLog->badData = 'Given Email: ' . $email;
							$errorLog->filename = 'API / Users.class.php';
							$errorLog->write();
						}
					}elseif($db->result->num_rows > 1){
						// Email in database twice
						$this->validState['email'] = 'invalid';
						$this->validMsg['email'] = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
						$this->error = true;
						$this->responseCode = 500;

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
						$this->responseCode = 401;

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
					$this->responseCode = $db->responseCode;
				}
				
				// Close Database Connection
				$db->close();
			}else{
				// No Password Given
				$this->validState['password'] = 'invalid';
				$this->validMsg['password'] = 'Please enter a password';
				$this->error = true;
				$this->responseCode = 400;

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
			$this->responseCode = $sendEmail->responseCode;
		}
		
		// Return
		return $success;
	}
	
	public function verifyEmail($emailAuth, $apiKey){
		// Verify Admin is performing this function
		$apiKeys = new apiKeys();
		$apiKeys->validate($apiKey, true);
		$this->validate($apiKeys->userID, true);
		
		if($this->admin){
			if(!empty($emailAuth)){
				$db = new Database();
				$dbEmailAuth = $db->escape($emailAuth);
				$db->query("SELECT id, email FROM users WHERE emailAuth='$dbEmailAuth'");
				if(!$db->error){
					if($db->result->num_rows == 1){
						// Valid Email Auth, Update Database
						$resultArray = $db->resultArray();
						$userID = $resultArray['id'];
						$dbUserID = $db->escape($userID);
						$db->query("UPDATE users SET emailVerified=b'1', emailAuth=NULL, emailAuthSent=NULL WHERE id='$dbUserID'");
						if(!$db->error){
							// Validate User
							$this->validate($userID, true);

							// Create API Key
							$apiKeys = new apiKeys();
							$apiKeys->add($userID);

							// Give user Brewer privileges?
							$emailDomainName = $this->emailDomainName($this->email);
							// To be continued...
						}else{
							// Query Error
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
							$this->responseCode = $db->responseCode;
						}
					}elseif($db->result->num_rows > 1){
						$this->error = true;
						$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
						$this->responseCode = 500;

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
						$this->responseCode = 400;

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
					$this->errorMsg = $db->responseCode;
				}

				// Close Database Connection
				$db->close();
			}else{
				// Missing Email Authentication Code
				$this->error = true;
				$this->errorMsg = 'Sorry, we seem to be missing your email authentication code. Try clicking on the link in your email again.';
				$this->responseCode = 400;

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 82;
				$errorLog->errorMsg = 'Missing emailAuth';
				$errorLog->badData = '';
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}
		}else{
			// Not an admin, not allowed to verify emails
			$this->error = true;
			$this->errorMsg = 'Sorry, your account does not have permission to perform this action.';
			$this->responseCode = 403;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 171;
			$errorLog->errorMsg = 'Non-Admin trying to verify email';
			$errorLog->badData = "userID: $apiKeys->userID attempting to confirm email_auth: $emailAuth";
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
	
	public function emailDomainName($email){
		preg_match('/(?<=@)[^.]+(?=\.).*/m', $email, $matches);
		return $matches[0];
	}
	
	public function delete($userID, $apiKey){
		// Validate $apiKey & get user info for $apiKey
		$apiKeys = new apiKeys();
		$apiKeys->validate($apiKey, true);
		$this->validate($apiKeys->userID, true);
		
		// If API Key is an Admin ($this->admin) or if $userID = $apiKeys->userID
		if($this->admin || $userID == $apiKeys->userID){
			// Prep for Database
			$db = new Database();
			$dbUserID = $db->escape($userID);

			// Delete API Keys for this userID
			$db->query("DELETE FROM users WHERE id='$dbUserID'");
			if($db->error){
				// Database Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
				$this->responseCode = $db->responseCode;
			}
			$db->close();
		}else{
			// Not Authorized
			$this->error = true;
			$this->errorMsg = "Sorry, you do not have permission to perform this action.";
			$this->responseCode = 403;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 170;
			$errorLog->errorMsg = 'Unauthorized attempt to delete user.';
			$errorLog->badData = "userID $apiKeys->userID is trying to delete userID: $userID";
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
	
	private function sendPasswordResetKey($userID, $apiKey){
		// Default Value
		$okayToSend = false;
		
		// Validate $apiKey & get user info for $apiKey
		$apiKeys = new apiKeys();
		$apiKeys->validate($apiKey, true);
		$this->validate($apiKeys->userID, true);
		
		// If API Key is an Admin ($this->admin) or if $userID = $apiKeys->userID
		if($this->admin || $userID == $apiKeys->userID){
			if($this->emailVerified){
				// Verified email, okay to send password reset
				
				// When was the last password reset sent?
				$db = new Database();
				$dbUserID = $db->escape($userID);
				$db->query("SELECT passwordResetSent FROM users WHERE id='$dbUserID'");
				if(!$db->error){
					// Get Timestamp of last Password Reset Sent
					$passwordResetSent = $db->singleResult('passwordResetSent');

					// Limit requests
					if(!empty($passwordResetSent)){
						// Next attempt allowed in 15 minutes
						$minutesBetweenAttempts = 15;
						$nextAttempt = $passwordResetSent + (60 * $minutesBetweenAttempts);
						if(time() > $nextAttempt){
							// Okay to send a password reset email (enough time has elapsed between requests)
							$okayToSend = true;
						}else{
							// Need to wait to send
							$sentMinutesAgo = round((time() - $passwordResetSent)/60,0);
							$minutesUntilAttemptAgain = $minutesBetweenAttempts - $sentMinutesAgo;
							$this->error = true;
							$this->errorMsg = "We sent you a password reset email $sentMinutesAgo minutes ago. Please wait at least $minutesUntilAttemptAgain minutes for that email to arrive before requesting another password reset email.";
							$this->responseCode = 400;

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 178;
							$errorLog->errorMsg = 'Too frequent password reset requests.';
							$errorLog->badData = "userID $apiKeys->userID is trying to send another password reset for userID: $userID";
							$errorLog->filename = 'API / Users.class.php';
							$errorLog->write();
						}
					}else{
						// Haven't sent password reset email yet
						$okayToSend = true;
					}
					
					if($okayToSend){
						// Generate Password Reset Code
						$uuid = new uuid();
						$this->passwordResetKey = $uuid->createCode();
						$this->passwordResetSent = time();

						// Update Database
						$dbPasswordResetKey = $db->escape($this->passwordResetKey);
						$dbPasswordResetSent = $db->escape($this->passwordResetSent);
						$db->query("UPDATE users SET passwordResetKey='$dbPasswordResetKey', passwordResetSent=$dbPasswordResetSent WHERE id='$dbUserID'");
						if(!$db->error){
							// Send Email to User
							$sendEmail = new SendEmail();
							$sendEmail->passwordResetEmail($this->email, $this->passwordResetKey);
							if($sendEmail->error){
								// Error Sending Email
								$this->error = true;
								$this->errorMsg = $sendEmail->errorMsg;
								$this->responseCode = $sendEmail->responseCode;
							}
						}else{
							// Database Error
							$this->error = true;
							$this->errorMsg = $db->errorMsg;
							$this->responseCode = $db->responseCode;
						}
					}
				}else{
					// Database Error
					$this->error = true;
					$this->errorMsg = $db->errorMsg;
					$this->responseCode = $db->responseCode;
				}

				// Close Database Connection
				$db->close();
			}else{
				// Haven't verified email, not allowed to reset password
				$this->error = true;
				$this->errorMsg = "Before we can reset your password, you will need to confirm your email address. Please check your email inbox for a confirmation message from Catalog.beer.";
				$this->responseCode = 400;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 179;
				$errorLog->errorMsg = 'Attempting password reset before their email has been verified.';
				$errorLog->badData = "userID: $this->userID";
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}
		}else{
			// Not an admin or user, can't create new account
			$this->error = true;
			$this->errorMsg = 'Sorry, your account does not have permission to perform this action.';
			$this->responseCode = 403;
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 177;
			$errorLog->errorMsg = 'Unauthorized person trying to send password reset';
			$errorLog->badData = "userID $apiKeys->userID is trying to get info on userID: $userID";
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
	
	private function resetPassword($passwordResetKey, $password){
		// Validate Password Reset Key
		$db = new Database();
		$dbPasswordResetKey = $db->escape($passwordResetKey);
		$db->query("SELECT id FROM users WHERE passwordResetKey='$dbPasswordResetKey'");
		if(!$db->error){
			if($db->result->num_rows == 1){
				// Password Key Found
				// Validate Password
				$this->password = $password;
				$this->validatePassword();
				if(!$this->error){
					// Get userID
					$userID = $db->singleResult('id');
					$dbUserID = $db->escape($userID);
					
					// Hash Password
					$passwordHash = password_hash($this->password, PASSWORD_DEFAULT);

					// Clear Saved Password
					$this->password = '';
					
					// Update Password in Database
					$dbPasswordHash = $db->escape($passwordHash);
					$db->query("UPDATE users SET passwordHash='$dbPasswordHash', passwordResetKey=NULL, passwordResetSent=NULL WHERE id='$dbUserID'");
					if($db->error){
						// Database Error
						$this->error = true;
						$this->errorMsg = $db->errorMsg;
						$this->responseCode = $db->responseCode;
					}
				}
			}elseif($db->result->num_rows == 0){
				// Invalid Password Reset Key
				$this->error = true;
				$this->errorMsg = 'Sorry, we are unable to process your password reset request. Your password reset link may have expired.';
				$this->responseCode = 400;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 180;
				$errorLog->errorMsg = 'Password reset key not found';
				$errorLog->badData = "Password Reset Key: $passwordResetKey";
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}else{
				// More than one result
				$this->error = true;
				$this->errorMsg = "Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.";
				$this->responseCode = 500;
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 181;
				$errorLog->errorMsg = 'Duplicate Password Reset Keys';
				$errorLog->badData = "Password Reset Key: $passwordResetKey";
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}
		}else{
			// Database Error
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = $db->responseCode;
		}
		
		// Close Database Connection
		$db->close();
	}
	
	private function generateUserObject(){
		if(empty($this->emailAuth)){$this->emailAuth = null;}
		if(empty($this->emailAuthSent)){$this->emailAuthSent = null;}
		if(empty($this->passwordResetSent)){$this->passwordResetSent = null;}
		if(empty($this->passwordResetKey)){$this->passwordResetKey = null;}
	
		$this->json['id'] = $this->userID;
		$this->json['object'] = 'users';
		$this->json['name'] = $this->name;
		$this->json['email'] = $this->email;
		$this->json['email_verified'] = $this->emailVerified;
		$this->json['email_auth'] = $this->emailAuth;
		$this->json['email_auth_sent'] = $this->emailAuthSent;
		$this->json['admin'] = $this->admin;
	}
	
	public function usersAPI($method, $function, $id, $apiKey, $data){
		/*---
		{METHOD} https://api.catalog.beer/users/{function}/{email_auth}
		{METHOD} https://api.catalog.beer/users/{id}/{function}
		
		GET https://api.catalog.beer/users/{id}
		GET https://api.catalog.beer/users/{id}/api-key
		
		POST https://api.catalog.beer/users
		POST https://api.catalog.beer/users/verify-email/{email_auth}
		
		PATCH https://api.catalog.beer/users/{id}
		
		DELETE https://api.catalog.beer/users/{id}
		---*/
			
		// Validate $apiKey & get user info for $apiKey
		$apiKeys = new apiKeys();
		$apiKeys->validate($apiKey, true);
		$this->validate($apiKeys->userID, true);
		
		// Switch based on HTTP Method
		switch($method){
			case 'GET':
				if(!empty($id) && empty($function)){
					// GET https://api.catalog.beer/users/{id}
					// Only Admins and Users themselves may access this endpoint
					if($this->admin || $id == $apiKeys->userID){
						if($this->validate($id, true)){
							$this->generateUserObject();
						}else{
							// Invalid User
							$this->json['error'] = true;
							$this->json['error_msg'] = $this->errorMsg;
						}
					}else{
						// Not Authorized
						$this->json['error'] = true;
						$this->json['error_msg'] = "Sorry, you do not have permission to perform this action.";
						$this->responseCode = 403;

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 172;
						$errorLog->errorMsg = 'Unauthorized attempt to get user info.';
						$errorLog->badData = "userID $apiKeys->userID is trying to get info on userID: $id";
						$errorLog->filename = 'API / Users.class.php';
						$errorLog->write();
					}
				}else{
					switch($function){
						// GET https://api.catalog.beer/users/{id}/api-key
						case 'api-key':
							if(!empty($id)){
								// Only Admins and Users themselves may access this endpoint
								if($this->admin || $id == $apiKeys->userID){
									// Get API Key
									$userAPIKey = $apiKeys->getKey($id);
									if(empty($userAPIKey)){$userAPIKey = null;}
									if(!$apiKeys->error){
										$this->json['object'] = 'api_key';
										$this->json['user_id'] = $id;
										$this->json['api_key'] = $userAPIKey;
										$this->responseCode = $apiKeys->responseCode;
									}else{
										// Error getting API Key
										$this->responseCode = $apiKeys->responseCode;
										$this->json['error'] = true;
										$this->json['error_msg'] = $apiKeys->errorMsg;
									}
								}else{
									// Not Authorized
									$this->error = true;
									$this->errorMsg = "Sorry, you do not have permission to perform this action.";
									$this->responseCode = 403;

									// Log Error
									$errorLog = new LogError();
									$errorLog->errorNumber = 173;
									$errorLog->errorMsg = 'Unauthorized attempt to get API Key.';
									$errorLog->badData = "userID $apiKeys->userID is trying to get the API key for userID: $userID";
									$errorLog->filename = 'API / Users.class.php';
									$errorLog->write();
								}
							}else{
								// Missing userID
								$this->responseCode = 400;
								$this->json['error'] = true;
								$this->json['error_msg'] = 'We seem to be missing the user_id you would like to retreive the api_key for. Please check your submission and try again.';

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 79;
								$errorLog->errorMsg = 'Missing user_id';
								$errorLog->badData = "UserID: $apiKeys->userID / function: $function / userID: $id";
								$errorLog->filename = 'API / Users.class.php';
								$errorLog->write();
							}
							break;
						default:
							// Missing Function
							$this->responseCode = 404;
							$this->json['error'] = true;
							$this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 78;
							$errorLog->errorMsg = 'Invalid Endpoint (/users)';
							$errorLog->badData = "Method: $method / UserID: $apiKeys->userID / function: $function / userID: $id";
							$errorLog->filename = 'API / Users.class.php';
							$errorLog->write();		
					}
				}
				break;
			case 'POST':
				if(empty($function)){
					// POST https://api.catalog.beer/users

					// Handle Empty Fields
					if(empty($data->name)){$data->name = '';}
					if(empty($data->email)){$data->email = '';}
					if(empty($data->password)){$data->password = '';}
					if(empty($data->terms_agreement)){$data->terms_agreement = '';}

					// Create Account
					$this->createAccount($data->name, $data->email, $data->password, $data->terms_agreement, $apiKey, 'POST', '', array());
					if(!$this->error){
						$this->generateUserObject();
					}else{
						$this->json['error'] = true;
						$this->json['error_msg'] = $this->errorMsg;
						$this->json['valid_state'] = $this->validState;
						$this->json['valid_msg'] = $this->validMsg;
					}
				}else{
					switch($function){
						case 'password-reset':
							// POST https://api.catalog.beer/users/password-rest/{id}
							
							// Handle Empty Fields
							if(empty($data->password)){$data->password = '';}
							
							// Reset Password
							$this->resetPassword($id, $data->password);
							if(!$this->error){
								// Successfully Reset Password
								// Return 204 - No Content
								$this->responseCode = 204;
							}else{
								$this->json['error'] = true;
								$this->json['error_msg'] = $this->errorMsg;
							}
							break;
						case 'reset-password':
							// POST https://api.catalog.beer/users/{id}/reset-password
							$this->sendPasswordResetKey($id, $apiKey);
							if(!$this->error){
								// Successfully Sent Email
								// Return 204 - No Content
								$this->responseCode = 204;
							}else{
								$this->json['error'] = true;
								$this->json['error_msg'] = $this->errorMsg;
							}
							break;
						case 'verify-email':
							// POST https://api.catalog.beer/users/verify-email/{email_auth}
							$this->verifyEmail($id, $apiKey);
							if(!$this->error){
								$this->generateUserObject();
							}else{
								$this->json['error'] = true;
								$this->json['error_msg'] = $this->errorMsg;
							}
							break;
						default:
							// Missing Function
							$this->responseCode = 404;
							$this->json['error'] = true;
							$this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 80;
							$errorLog->errorMsg = 'Invalid Endpoint (/users)';
							$errorLog->badData = "UserID: $apiKeys->userID / function: $function / id: $id";
							$errorLog->filename = 'API / Users.class.php';
							$errorLog->write();	
					}
				}
				break;
			case 'PATCH':
				// Which fields are we updating?
				$patchFields = array();
				
				if(isset($data->name)){$patchFields[] = 'name';}
				else{$data->name = '';}
				
				if(isset($data->email)){$patchFields[] = 'email';}
				else{$data->email = '';}
				
				if(isset($data->password)){$patchFields[] = 'password';}
				else{$data->password = '';}
				
				// Update User Info
				$this->createAccount($data->name, $data->email, $data->password, true, $apiKey, 'PATCH', $id, $patchFields);
				if(!$this->error){
					// Get Updated User Info
					$this->validate($id, true);
					
					// Generate Brewer Object JSON
					$this->generateUserObject();
				}else{
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
					unset($this->validState['terms_agreement']);
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
				}
				
				break;
			case 'DELETE':
				// DELETE https://api.catalog.beer/users/{id}
				$this->delete($id, $apiKey);
				if(!$this->error){
					// Successful
					$this->responseCode = 204;
				}else{
					// Error
					$this->json['error'] = true;
					$this->json['error_msg'] = $this->errorMsg;
				}
				break;
			default:
				// Invalid Method
				$this->responseCode = 405;
				$this->json['error'] = true;
				$this->json['error_msg'] = 'Invalid HTTP method for this endpoint.';
				$this->responseHeader = 'Allow: GET, POST, PATCH, DELETE';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 72;
				$errorLog->errorMsg = 'Invalid Method (/users)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
		}
	}
	
	public function loginAPI($method, $apiKey, $data) {
		/*---
		POST https://api.catalog.beer/login	
		---*/
		if($method == 'POST'){
			// Validate that the $apiKey is an admin API key
			$apiKeys = new apiKeys();
			$apiKeys->validate($apiKey, true);
			$this->validate($apiKeys->userID, true);
			if($this->admin){
				// Handle Empty Fields
				if(empty($data->email)){$data->email = '';}
				if(empty($data->password)){$data->password = '';}
				
				// Validate Login /data
				if($this->login($data->email, $data->password)){
					// Successful Login
					$this->json['object'] = 'user_id';
					$this->json['id'] = $this->userID;
				}else{
					// Invalid Login
					$this->json['error'] = true;
					$this->json['error_msg'] =$this->errorMsg;
					$this->json['valid_state'] = $this->validState;
					$this->json['valid_msg'] = $this->validMsg;
					
					// Remove Uncessary Fields
					unset($this->json['valid_state']['name']);
					unset($this->json['valid_state']['terms_agreement']);
					unset($this->json['valid_msg']['name']);
				}
			}else{
				// Not an Admin
				$this->responseCode = 403;
				$this->json['error'] = true;
				$this->json['error_msg'] = 'Sorry, your account does not have permission to perform this action.';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 39;
				$errorLog->errorMsg = 'Non-Admin trying to get account info';
				$errorLog->badData = "UserID: $apiKeys->userID";
				$errorLog->filename = 'API / Users.class.php';
				$errorLog->write();
			}
		}else{
			// Unsupported Method - Method Not Allowed
			$this->json['error'] = true;
			$this->json['error_msg'] = "Invalid HTTP method for this endpoint.";
			$this->responseCode = 405;
			$this->responseHeader = 'Allow: POST';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 73;
			$errorLog->errorMsg = 'Invalid Method (/login)';
			$errorLog->badData = $method;
			$errorLog->filename = 'API / Users.class.php';
			$errorLog->write();
		}
	}
}
?>