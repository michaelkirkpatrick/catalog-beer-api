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
	public $admin = false;
	
	// Validation
	public $error = false;
	public $errorMsg = '';
	public $validState = array('name'=>'', 'email'=>'', 'password'=>'', 'terms_agreement'=>'');
	public $validMsg = array('name'=>'', 'email'=>'', 'password'=>'');
	
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
				$this->responseCode = 400;
			}

			// Generate userID
			$uuid = new uuid();
			$this->userID = $uuid->generate('users');
			if($uuid->error){
				// userID Generation Error
				$this->error = true;
				$this->errorMsg = $uuid->errorMsg;
				$this->responseCode = $uuid->responseCode;
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
				$db->query("INSERT INTO users (id, email, passwordHash, name, admin, emailVerified) VALUES ('$dbUserID', '$dbEmail', '$dbPasswordHash', '$dbName', '0', '0')");
				if(!$db->error){
					// Send email confirmation
					$sendEmail = new SendEmail();
					$this->emailAuth = $sendEmail->verifyEmail($this->email);
					$this->emailAuthSent = time();

					if(!$sendEmail->error){
						// Update Database
						$dbEmailAuth = $db->escape($this->emailAuth);
						$dbEmailAuthSent = $db->escape($this->emailAuthSent);
						$db->query("UPDATE users SET emailAuth='$dbEmailAuth', emailAuthSent='$dbEmailAuthSent' WHERE id='$dbUserID'");
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
			// Not an admin, can't create new account
			$this->error = true;
			$this->errorMsg = 'Sorry, your account does not have permission to perform this action.';
			$this->responseCode = 403;
			
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
						$this->responseCode = 400;
					}
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
	
	public function verifyEmail($emailAuth){
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
					$db->query("UPDATE users SET emailVerified='1', emailAuth='', emailAuthSent='0' WHERE id='$dbUserID'");
					if(!$db->error){
						// Validate User
						$this->validate($userID, true);
						
						// Create API Key
						$apiKeys = new apiKeys();
						$apiKeys->add($userID);
						
						// Give user Brewer Privledges?
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
	}
	
	public function emailDomainName($email){
		preg_match('/(?<=@)[^.]+(?=\.).*/m', $email, $matches);
		return $matches[0];
	}
	
	public function getAdminEmails(){
		// Email Array
		$emails = array();
		
		// Connect to Database
		$db = new Database();
		$db->query("SELECT email FROM users WHERE admin=1");
		if(!$db->error){
			while($array = $db->resultArray()){
				$emails[] = $array['email'];
			}
		}else{
			$this->error = true;
			$this->errorMsg = $db->errorMsg;
			$this->responseCode = $db->responseCode;
		}
		$db->close();
		
		// Return
		return $emails;
	}
	
	public function usersAPI($method, $function, $id, $apiKey, $data){
		/*---
		{METHOD} https://api.catalog.beer/users/{function}/{email_auth}
		{METHOD} https://api.catalog.beer/users/{id}/{function}
		
		GET https://api.catalog.beer/users/{id}
		GET https://api.catalog.beer/users/{id}/api-key
		
		POST https://api.catalog.beer/users/verify-email/{email_auth}
		POST https://api.catalog.beer/users
		---*/
		
		// Validate API Key
		$apiKeys = new apiKeys();
		$apiKeys->validate($apiKey, true);
		
		// Validate userID
		$this->validate($apiKeys->userID, true);
		if($this->admin){
			switch($method){
				case 'GET':
					if(!empty($id) && empty($function)){
						// GET https://api.catalog.beer/users/{id}
						if($this->validate($id, true)){
							$this->json['id'] = $this->userID;
							$this->json['object'] = 'users';
							$this->json['name'] = $this->name;
							$this->json['email'] = $this->email;
							$this->json['email_verified'] = $this->emailVerified;
							$this->json['email_auth'] = $this->emailAuth;
							$this->json['email_auth_sent'] = $this->emailAuthSent;
							$this->json['admin'] = $this->admin;
						}else{
							// Invalid User
							$this->json['error'] = true;
							$this->json['error_msg'] = $this->errorMsg;
						}
					}else{
						switch($function){
							// GET https://api.catalog.beer/users/{id}/api-key
							case 'api-key':
								if(!empty($id)){
									// Get API Key
									$userAPIKey = $apiKeys->getKey($id);
									if(!empty($userAPIKey)){
										$this->json['object'] = 'api_key';
										$this->json['user_id'] = $id;
										$this->json['api_key'] = $userAPIKey;
									}else{
										// Invalid User
										$this->responseCode = $apiKeys->responseCode;
										$this->json['error'] = true;
										$this->json['error_msg'] = $apiKeys->errorMsg;
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
								$errorLog->badData = "UserID: $apiKeys->userID / function: $function / userID: $id";
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
						$this->createAccount($data->name, $data->email, $data->password, $data->terms_agreement, $apiKeys->userID);
						if(!$this->error){
							$this->json['id'] = $this->userID;
							$this->json['object'] = 'users';
							$this->json['name'] = $this->name;
							$this->json['email'] = $this->email;
							$this->json['email_verified'] = $this->emailVerified;
							$this->json['email_auth'] = $this->emailAuth;
							$this->json['email_auth_sent'] = $this->emailAuthSent;
							$this->json['admin'] = $this->admin;
						}else{
							$this->json['error'] = true;
							$this->json['error_msg'] = $this->errorMsg;
							$this->json['valid_state'] = $this->validState;
							$this->json['valid_msg'] = $this->validMsg;
						}
					}else{
						switch($function){
							case 'verify-email':
								// POST https://api.catalog.beer/users/verify-email/{email_auth}
								$this->verifyEmail($id);
								if(!$this->error){
									$this->json['id'] = $this->userID;
									$this->json['object'] = 'users';
									$this->json['name'] = $this->name;
									$this->json['email'] = $this->email;
									$this->json['email_verified'] = $this->emailVerified;
									$this->json['email_auth'] = $this->emailAuth;
									$this->json['email_auth_sent'] = $this->emailAuthSent;
									$this->json['admin'] = $this->admin;
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
				default:
					// Invalid Method
					$this->responseCode = 405;
					$this->json['error'] = true;
					$this->json['error_msg'] = 'Invalid HTTP method for this endpoint.';
					$this->responseHeader = 'Allow: GET, POST';

					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 72;
					$errorLog->errorMsg = 'Invalid Method (/users)';
					$errorLog->badData = $method;
					$errorLog->filename = 'API / Users.class.php';
					$errorLog->write();
			}
		}else{
			// Not an Admin
			$this->responseCode = 403;
			$this->json['error'] = true;
			$this->json['error_msg'] = 'Sorry, your account does not have permission to perform this action.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 37;
			$errorLog->errorMsg = 'Non-Admin trying to get account info';
			$errorLog->badData = "UserID: $apiKeys->userID / id: $id / function: $function";
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