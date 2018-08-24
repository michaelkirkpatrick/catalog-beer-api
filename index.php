<?php
// Initialize
include_once $_SERVER["DOCUMENT_ROOT"] . '/classes/initialize.php';

// Setup Variables
$json = array();
$error = false;
$responseCode = 200;

// Method & Data
// get the HTTP method, path and body of the request
$method = $_SERVER['REQUEST_METHOD'];
$input = file_get_contents('php://input');
$data = json_decode($input);
$endpoint = '';
$id = '';
$function = '';

if(isset($_GET['endpoint'])){
	$endpoint = $_GET['endpoint'];
}
if(isset($_GET['id'])){
	$id = substr($_GET['id'], 1, 36);
}
if(isset($_GET['function'])){
	$function = $_GET['function'];
}

if($_SERVER['HTTPS'] == 'on'){
	// Check Authorization Header
	if(isset($_SERVER['PHP_AUTH_USER'])){
		// Get Submitted Username and Password
		$apiKey = $_SERVER['PHP_AUTH_USER'];

		if(!empty($apiKey)){
			$apiKeys = new apiKeys();
			if(!$apiKeys->validate($apiKey, true)){
				// Invalid User
				$error = true;
				$responseCode = 401;
				$json['error'] = true;
				$json['error_msg'] = 'Sorry to say this, but your API key appears to be invalid. Please contact Catalog.beer support if you believe you have received this message in error; we will help you figure it out.';
			}
		}else{
			// Missing Username
			$error = true;
			$responseCode = 401;
			$json['error'] = true;
			$json['error_msg'] = 'We are missing your API Key. This key should be submitted in the username field of your API request using HTTP Basic Auth. No password is required.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 7;
			$errorLog->errorMsg = 'Missing username';
			$errorLog->badData = '';
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
		}
	}else{
		// Invalid Authentication Key
		$error = true;
		$responseCode = 401;
		$json['error'] = true;
		$json['error_msg'] = 'Missing API key. Please check that your request includes your API key and then try again.';

		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 6;
		$errorLog->errorMsg = 'No credentials submitted';
		$errorLog->badData = '';
		$errorLog->filename = 'API / index.php';
		$errorLog->write();
	}
}else{
	// No HTTPS
	$error = true;
	$responseCode = 400;
	$json['error'] = true;
	$json['error_msg'] = 'In order to connect to the Catalog.beer API, you will need to connect using a secure connection (HTTPS). Please try your request again.';
}

/* - - - - - BREWER - - - - - */
if($endpoint == 'brewer' && !$error){
	// Get Brewer Class
	$brewer = new Brewer();
	
	switch($method){
		case 'GET':
			if(!empty($id) && empty($function)){
				// Validate ID
				if($brewer->validate($id, true)){
					$json['id'] = $brewer->brewerID;
					$json['object'] = 'brewer';
					$json['name'] = $brewer->name;
					$json['description'] = $brewer->description;
					$json['short_description'] = $brewer->shortDescription;
					$json['url'] = $brewer->url;
					$json['cb_verified'] = $brewer->cbVerified;
					$json['brewer_verified'] = $brewer->brewerVerified;
					$json['facebook_url'] = $brewer->facebookURL;
					$json['twitter_url'] = $brewer->twitterURL;
					$json['instagram_url'] = $brewer->instagramURL;
				}else{
					// Brewer Validation Error
					$responseCode = 404;
					$json['error'] = true;
					$json['error_msg'] = 'Sorry, we don\'t have any breweries with that brewer_id. Please check your request and try again.';
				}
			}else{
				if(!empty($function)){
					switch($function){
						case 'count':
							$numBrewers = $brewer->countBrewers();
							if(!$brewer->error){
								$json['object'] = 'count';
								$json['url'] = '/brewer/count';
								$json['value'] = $numBrewers;
							}else{
								$responseCode = 500;
								$json['error'] = true;
								$json['error_msg'] = $brewer->errorMsg;
							}
							break;
						case 'beer':
							$beer = new Beer();
							$json = $beer->brewerBeers($id);
							break;
						case 'locations':
							$location = new Location();
							$locationArray = $location->brewerLocations($id);
							if(!$location->error){
								$json['object'] = 'list';
								$json['url'] = '/brewer/' . $id . '/locations';
								$json['has_more'] = false;
								$json['data'] = $locationArray;
							}else{
								$responseCode = 404;
								$json['error'] = true;
								$json['error_msg'] = $location->errorMsg;
							}
							break;
						case 'last-modified':
							$users = new Users();
							$users->validate($apiKeys->userID, true);
							if($users->admin){
								if(!empty($id)){
									// Individual Brewer
									$lastModified = $brewer->lastModified($id);
									if(!$brewer->error){
										$json['object'] = 'timestamp';
										$json['url'] = '/brewer/last-modified/' . $id;
										$json['brewer_id'] = $id;
										$json['last_modified'] = $lastModified;
									}else{
										$responseCode = 404;
										$json['error'] = true;
										$json['error_msg'] = $brewer->errorMsg;
									}
								}else{
									// All Brewers
									$latestModified = $brewer->latestModified();
									if(!$brewer->error){
										$json['object'] = 'timestamp';
										$json['url'] = '/brewer/last-modified';
										$json['last_modified'] = $latestModified;
									}else{
										$responseCode = 404;
										$json['error'] = true;
										$json['error_msg'] = $brewer->errorMsg;
									}
								}
							}else{
								// Not an Admin
								$responseCode = 401;
								$json['error'] = true;
								$json['errorMsg'] = 'Sorry, your account does not have permission to access this endpoint.';

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 101;
								$errorLog->errorMsg = 'Non-Admin trying to get brewer last modified info';
								$errorLog->badData = "UserID: $apiKeys->userID / function: $function";
								$errorLog->filename = 'API / index.php';
								$errorLog->write();
							}
							break;
						default:
							// Invalid Function
							$responseCode = 404;
							$json['error'] = true;
							$json['error_msg'] = 'Sorry, this appears to be an invalid function.';
							
							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 69;
							$errorLog->errorMsg = 'Invalid Function (/brewer)';
							$errorLog->badData = $function;
							$errorLog->filename = 'API / index.php';
							$errorLog->write();
					}
				}else{
					// List Breweries
					// Defaults
					$cursor = base64_encode('0');	// Page
					$count = 500;
					
					// Get Variables
					if(isset($_GET['cursor'])){
						$cursor = $_GET['cursor'];
					}
					if(isset($_GET['count'])){
						$count = $_GET['count'];
					}
					
					// Query
					$brewerArray = $brewer->getBrewers($cursor, $count);
					if(!$brewer->error){
						// Start JSON
						$json['object'] = 'list';
						$json['url'] = '/brewer';
						
						// Next Cursor
						$nextCursor = $brewer->nextCursor($cursor, $count);
						if(!empty($nextCursor)){
							$json['has_more'] = true;
							$json['next_cursor'] = $nextCursor;
						}else{
							$json['has_more'] = false;
						}
						
						// Append Data
						$json['data'] = $brewerArray;	
					}else{
						$responseCode = 400;
						$json['error'] = true;
						$json['error_msg'] = $brewer->errorMsg;
					}
				}
			}
			break;
		case 'POST':
			$brewer->add($data->name, $data->description, $data->short_description, $data->url, $data->facebook_url, $data->twitter_url, $data->instagram_url, $apiKeys->userID);
			if(!$brewer->error){
				$json['id'] = $brewer->brewerID;
				$json['object'] = 'brewer';
				$json['name'] = $brewer->name;
				$json['description'] = $brewer->description;
				$json['short_description'] = $brewer->shortDescription;
				$json['url'] = $brewer->url;
				$json['cb_verified'] = $brewer->cbVerified;
				$json['brewer_verified'] = $brewer->brewerVerified;
				$json['facebook_url'] = $brewer->facebookURL;
				$json['twitter_url'] = $brewer->twitterURL;
				$json['instagram_url'] = $brewer->instagramURL;
			}else{
				$responseCode = 400;
				$json['error'] = true;
				$json['error_msg'] = $brewer->errorMsg;
				$json['valid_state'] = $brewer->validState;
				$json['valid_msg'] = $brewer->validMsg;
			}
			break;
		case 'PUT':
			// Account for Blanks
			if(isset($data->name)){
				$name = $data->name;
			}else{
				$name = '';
			}
			if(isset($data->description)){
				$description = $data->description;
			}else{
				$description = '';
			}
			if(isset($data->short_description)){
				$shortDescription = $data->short_description;
			}else{
				$shortDescription = '';
			}
			if(isset($data->url)){
				$url = $data->url;
			}else{
				$url = '';
			}
			if(isset($data->facebook_url)){
				$facebookURL = $data->facebook_url;
			}else{
				$facebookURL = '';
			}
			if(isset($data->twitter_url)){
				$twitterURL = $data->twitter_url;
			}else{
				$twitterURL = '';
			}
			if(isset($data->instagram_url)){
				$instagramURL = $data->instagram_url;
			}else{
				$instagramURL = '';
			}
			
			$brewer->update($name, $description, $shortDescription, $url, $facebookURL, $twitterURL, $instagramURL, $apiKeys->userID, $id);
			if(!$brewer->error){
				// Get Updated Brewer Info
				$brewer->validate($id, true);
				$json['id'] = $brewer->brewerID;
				$json['object'] = 'brewer';
				$json['name'] = $brewer->name;
				$json['description'] = $brewer->description;
				$json['short_description'] = $brewer->shortDescription;
				$json['url'] = $brewer->url;
				$json['cb_verified'] = $brewer->cbVerified;
				$json['brewer_verified'] = $brewer->brewerVerified;
				$json['facebook_url'] = $brewer->facebookURL;
				$json['twitter_url'] = $brewer->twitterURL;
				$json['instagram_url'] = $brewer->instagramURL;
			}else{
				$responseCode = 400;
				$json['error'] = true;
				$json['error_msg'] = $brewer->errorMsg;
				$json['valid_state'] = $brewer->validState;
				$json['valid_msg'] = $brewer->validMsg;
			}
			break;
		default:
			// Invalid Method
			$responseCode = 404;
			$json['error'] = true;
			$json['error_msg'] = 'Sorry, ' . $method . ' is an invalid method for this endpoint.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 68;
			$errorLog->errorMsg = 'Invalid Method (/brewer)';
			$errorLog->badData = $method;
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
	}
}

/* - - - - - BEER - - - - - */
if($endpoint == 'beer' && !$error){
	// Get Beer Class
	$beer = new Beer();
	
	switch($method){
		case 'GET':
			if(!empty($id) && empty($function)){
				// Validate ID
				if($beer->validate($id, true)){
					// Validate Brewery
					$brewer = new Brewer();
					if($brewer->validate($beer->brewerID, true)){
						// Beer Info
						$json['id'] = $beer->beerID;
						$json['object'] = 'beer';
						$json['name'] = $beer->name;
						$json['style'] = $beer->style;
						$json['description'] = $beer->description;
						$json['abv'] = $beer->abv;
						$json['ibu'] = $beer->ibu;
						$json['cb_verified'] = $beer->cbVerified;
						$json['brewer_verified'] = $beer->brewerVerified;
						
						// Brewer Info
						$json['brewer']['id'] = $brewer->brewerID;
						$json['brewer']['object'] = 'brewer';
						$json['brewer']['name'] = $brewer->name;
						$json['brewer']['description'] = $brewer->description;
						$json['brewer']['short_description'] = $brewer->shortDescription;
						$json['brewer']['url'] = $brewer->url;
						$json['brewer']['cb_verified'] = $brewer->cbVerified;
						$json['brewer']['brewer_verified'] = $brewer->brewerVerified;
						$json['brewer']['facebook_url'] = $brewer->facebookURL;
						$json['brewer']['twitter_url'] = $brewer->twitterURL;
						$json['brewer']['instagram_url'] = $brewer->instagramURL;
					}else{
						// Brewer Validation Error
						$responseCode = 400;
						$json['error'] = true;
						$json['error_msg'] = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					}
				}else{
					// Beer Validation Error
					$responseCode = 404;
					$json['error'] = true;
					$json['error_msg'] = 'Sorry, we don\'t have any beer with that beer_id. Please check your request and try again.';
				}
			}else{
				if(!empty($function)){
					switch($function){
						case 'count':
							$numBeers = $beer->countBeers();
							if(!$beer->error){
								$json['object'] = 'count';
								$json['url'] = '/beer/count';
								$json['value'] = $numBeers;
							}else{
								$responseCode = 500;
								$json['error'] = true;
								$json['error_msg'] = $beer->errorMsg;
							}
							break;
						case 'last-modified':
							$users = new Users();
							$users->validate($apiKeys->userID, true);
							if($users->admin){
								if(!empty($id)){
									// Individual Brewer
									$lastModified = $beer->lastModified($id);
									if(!$beer->error){
										$json['object'] = 'timestamp';
										$json['url'] = '/beer/last-modified/' . $id;
										$json['beer_id'] = $id;
										$json['last_modified'] = $lastModified;
									}else{
										$responseCode = 404;
										$json['error'] = true;
										$json['error_msg'] = $beer->errorMsg;
									}
								}else{
									// All Brewers
									$latestModified = $beer->latestModified();
									if(!$beer->error){
										$json['object'] = 'timestamp';
										$json['url'] = '/beer/last-modified';
										$json['last_modified'] = $latestModified;
									}else{
										$responseCode = 404;
										$json['error'] = true;
										$json['error_msg'] = $beer->errorMsg;
									}
								}
							}else{
								// Not an Admin
								$responseCode = 401;
								$json['error'] = true;
								$json['errorMsg'] = 'Sorry, your account does not have permission to access this endpoint.';

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 109;
								$errorLog->errorMsg = 'Non-Admin trying to get brewer last modified info';
								$errorLog->badData = "UserID: $apiKeys->userID / function: $function";
								$errorLog->filename = 'API / index.php';
								$errorLog->write();
							}
							break;
						default:
							// Invalid Function
							$responseCode = 404;
							$json['error'] = true;
							$json['error_msg'] = 'Sorry, this appears to be an invalid function.';
							
							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 70;
							$errorLog->errorMsg = 'Invalid Function (/beer)';
							$errorLog->badData = $function;
							$errorLog->filename = 'API / index.php';
							$errorLog->write();
					}
				}else{
					// List Beers
					// Defaults
					$cursor = base64_encode('0');	// Page
					$count = 500;
					
					// Get Variables
					if(isset($_GET['cursor'])){
						$cursor = $_GET['cursor'];
					}
					if(isset($_GET['count'])){
						$count = $_GET['count'];
					}
					
					// Query
					$beerArray = $beer->getBeers($cursor, $count);
					if(!$beer->error){
						// Start JSON
						$json['object'] = 'list';
						$json['url'] = '/beer';
						
						// Next Cursor
						$nextCursor = $beer->nextCursor($cursor, $count);
						if(!empty($nextCursor)){
							$json['has_more'] = true;
							$json['next_cursor'] = $nextCursor;
						}else{
							$json['has_more'] = false;
						}
						
						// Append Data
						$json['data'] = $beerArray;
					}else{
						$responseCode = 400;
						$json['error'] = true;
						$json['error_msg'] = $beer->errorMsg;
					}
				}
			}
			break;
		case 'POST':
			$beer->add($data->brewer_id, $data->name, $data->style, $data->description, $data->abv, $data->ibu, $apiKeys->userID);
			if(!$beer->error){
				$json['id'] = $beer->beerID;
				$json['object'] = 'beer';
				$json['name'] = $beer->name;
				$json['style'] = $beer->style;
				$json['description'] = $beer->description;
				$json['abv'] = floatval($beer->abv);
				$json['ibu'] = intval($beer->ibu);
				$json['cb_verified'] = $beer->cbVerified;
				$json['brewer_verified'] = $beer->brewerVerified;
			}else{
				$responseCode = 400;
				$json['error'] = true;
				$json['error_msg'] = $beer->errorMsg;
				$json['valid_state'] = $beer->validState;
				$json['valid_msg'] = $beer->validMsg;
			}
			break;
		default:
			// Invalid Method
			$responseCode = 404;
			$json['error'] = true;
			$json['error_msg'] = 'Sorry, ' . $method . ' is an invalid method for this endpoint.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 71;
			$errorLog->errorMsg = 'Invalid Method (/beer)';
			$errorLog->badData = $method;
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
	}
}

/* - - - - - USERS - - - - - */
if($endpoint == 'users' && !$error){
	// Verify User
	$users = new Users();
	$users->validate($apiKeys->userID, true);
	if($users->admin){
		switch($method){
			case 'GET':
				if(!empty($id) && empty($function)){
					if($users->validate($id, true)){
						$json['id'] = $users->userID;
						$json['object'] = 'users';
						$json['name'] = $users->name;
						$json['email'] = $users->email;
						$json['emailVerified'] = $users->emailVerified;
						$json['emailAuth'] = $users->emailAuth;
						$json['emailAuthSent'] = $users->emailAuthSent;
						$json['admin'] = $users->admin;
					}else{
						// Invalid User
						$responseCode = 401;
						$json['error'] = true;
						$json['errorMsg'] = 'Sorry, this is not a valid Catalog.beer account.';

						// Log Error
						$errorLog = new LogError();
						$errorLog->errorNumber = 36;
						$errorLog->errorMsg = 'Invalid Account';
						$errorLog->badData = "UserID: $id";
						$errorLog->filename = 'API / index.php';
						$errorLog->write();
					}
				}else{
					switch($function){
						case 'api-key':
							if(!empty($id)){
								// Get API Key
								$userAPIKey = $apiKeys->getKey($id);
								if(!empty($userAPIKey)){
									$json['object'] = 'api_key';
									$json['user_id'] = $id;
									$json['api_key'] = $userAPIKey;
								}else{
									// Invalid User
									$json['error'] = true;
									$json['error_msg'] = $apiKeys->error_msg;
								}
							}else{
								// Missing Function
								$responseCode = 400;
								$json['error'] = true;
								$json['errorMsg'] = 'We seem to be missing the user_id you would like to retreive the api_key for. Please check your submission and try again.';

								// Log Error
								$errorLog = new LogError();
								$errorLog->errorNumber = 79;
								$errorLog->errorMsg = 'Missing user_id';
								$errorLog->badData = "UserID: $apiKeys->userID / function: $function / userID: $id";
								$errorLog->filename = 'API / index.php';
								$errorLog->write();
							}
							break;
						default:
							// Missing Function
							$responseCode = 400;
							$json['error'] = true;
							$json['errorMsg'] = 'Sorry, this is an invalid endpoint.';

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 78;
							$errorLog->errorMsg = 'Invalid Endpoint (/users)';
							$errorLog->badData = "UserID: $apiKeys->userID / function: $function / userID: $id";
							$errorLog->filename = 'API / index.php';
							$errorLog->write();		
					}
				}
				break;
			case 'POST':
				if(empty($function)){
					// Create Account
					$users->createAccount($data->name, $data->email, $data->password, $data->terms_agreement, $apiKeys->userID);
					if(!$users->error){
						$json['id'] = $users->userID;
						$json['object'] = 'users';
						$json['name'] = $users->name;
						$json['email'] = $users->email;
						$json['emailVerified'] = $users->email;
						$json['emailAuth'] = $users->email;
						$json['emailAuthSent'] = $users->email;
						$json['admin'] = $users->admin;
					}else{
						$responseCode = 400;
						$json['error'] = true;
						$json['error_msg'] = $users->errorMsg;
						$json['valid_state'] = $users->validState;
						$json['valid_msg'] = $users->validMsg;
					}
				}else{
					switch($function){
						case 'api-key':
							$apiKeys->add($id);
							if(!$apiKeys->error){
								$json['object'] = 'api_key';
								$json['user_id'] = $id;
								$json['api_key'] = $apiKeys->apiKey;
							}else{
								$responseCode = 400;
								$json['error'] = true;
								$json['errorMsg'] = $apiKeys->errorMsg;
							}
							break;
						case 'verify-email':
							$users->verifyEmail($id);
							if(!$users->error){
								$json['id'] = $users->userID;
								$json['object'] = 'users';
								$json['name'] = $users->name;
								$json['email'] = $users->email;
								$json['emailVerified'] = $users->email;
								$json['emailAuth'] = $users->email;
								$json['emailAuthSent'] = $users->email;
								$json['admin'] = $users->admin;
							}else{
								$responseCode = 400;
								$json['error'] = true;
								$json['error_msg'] = $users->errorMsg;
							}
							break;
						default:
							// Missing Function
							$responseCode = 400;
							$json['error'] = true;
							$json['error_msg'] = 'Sorry, this is an invalid endpoint.';

							// Log Error
							$errorLog = new LogError();
							$errorLog->errorNumber = 80;
							$errorLog->errorMsg = 'Invalid Endpoint (/users)';
							$errorLog->badData = "UserID: $apiKeys->userID / function: $function / id: $id";
							$errorLog->filename = 'API / index.php';
							$errorLog->write();	
					}
				}
				break;
			default:
				// Invalid Method
				$responseCode = 404;
				$json['error'] = true;
				$json['error_msg'] = 'Sorry, ' . $method . ' is an invalid method for this endpoint.';

				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 72;
				$errorLog->errorMsg = 'Invalid Method (/users)';
				$errorLog->badData = $method;
				$errorLog->filename = 'API / index.php';
				$errorLog->write();
		}
	}else{
		// Not an Admin
		$responseCode = 401;
		$json['error'] = true;
		$json['errorMsg'] = 'Sorry, your account does not have permission to perform this action.';

		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 37;
		$errorLog->errorMsg = 'Non-Admin trying to get account info';
		$errorLog->badData = "UserID: $apiKeys->userID / id: $id / function: $function";
		$errorLog->filename = 'API / index.php';
		$errorLog->write();
	}
}

/* - - - - - LOGIN - - - - - */
if($endpoint == 'login' && !$error){
	$users = new Users();
	if($method == 'POST'){
		$users->validate($apiKeys->userID, true);
		if($users->admin){
			if($users->login($data->email, $data->password)){
				// Successful Login
				$json['id'] = $users->userID;
				$json['object'] = 'users';
				$json['name'] = $users->name;
				$json['email'] = $users->email;
				$json['emailVerified'] = $users->email;
				$json['emailAuth'] = $users->email;
				$json['emailAuthSent'] = $users->email;
				$json['admin'] = $users->admin;
			}else{
				// Invalid Login
				$responseCode = 401;
				$json['error'] = true;
				$json['error_msg'] = $users->errorMsg;
				$json['valid_state'] = $users->validState;
				$json['valid_msg'] = $users->validMsg;
			}
		}else{
			// Not an Admin
			$responseCode = 401;
			$json['error'] = true;
			$json['errorMsg'] = 'Sorry, your account does not have permission to perform this action.';

			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 39;
			$errorLog->errorMsg = 'Non-Admin trying to get account info';
			$errorLog->badData = "UserID: $apiKeys->userID";
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
		}
	}else{
		// Invalid Method
		$responseCode = 400;
		$json['error'] = true;
		$json['error_msg'] = 'This endpoint only accepts POST requests.';
		
		// Log Error
		$errorLog = new LogError();
		$errorLog->errorNumber = 73;
		$errorLog->errorMsg = 'Invalid Method (/login)';
		$errorLog->badData = $method;
		$errorLog->filename = 'API / index.php';
		$errorLog->write();
	}
}

/* - - - - - LOCATION - - - - - */
if($endpoint == 'location' && !$error){
	// Connect to Class
	$location = new Location();
	$usAddresses = new USAddresses();
	
	switch($method){
		case 'POST':
			if(!empty($id)){
				if($location->validate($id, true)){
					// Add Address for Location
					$usAddresses->add($location->id, $data->address1, $data->address2, $data->city, $data->sub_code, $data->zip5, $data->zip4, $data->telephone);
					if(!$usAddresses->error){
						// Successfully Added
						$json['id'] = $location->id;
						$json['object'] = 'location';
						$json['name'] = $location->name;
						$json['brewer_id'] = $location->brewerID;
						$json['url'] = $location->url;
						$json['country_code'] = $location->countryCode;
						$json['country_short_name'] = $location->countryShortName;
						$json['latitude'] = $location->latitude;
						$json['longitude'] = $location->longitude;
						
						$json['telephone'] = $usAddresses->telephone;
						$json['address']['address1'] = $usAddresses->address1;
						$json['address']['address2'] = $usAddresses->address2;
						$json['address']['city'] = $usAddresses->city;
						$json['address']['sub_code'] = $usAddresses->sub_code;
						$json['address']['state_short'] = $usAddresses->stateShort;
						$json['address']['state_long'] = $usAddresses->stateLong;
						$json['address']['zip5'] = $usAddresses->zip5;
						$json['address']['zip4'] = $usAddresses->zip4;
					}else{
						// Error Adding Address
						$responseCode = 400;
						$json['error'] = true;
						$json['error_msg'] = $usAddresses->errorMsg;
						$json['valid_state'] = $usAddresses->validState;
						$json['valid_msg'] = $usAddresses->validMsg;
					}
				}else{
					// Invalid Location
					$responseCode = 404;
					$json['error'] = true;
					$json['error_msg'] = 'Sorry, we don\'t have any locations with that location_id. Please check your request and try again.';
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 85;
					$errorLog->errorMsg = 'Invalid location_id';
					$errorLog->badData = $id;
					$errorLog->filename = 'API / index.php';
					$errorLog->write();
				}
			}else{
				// Add Location
				$location->add($data->brewer_id, $data->name, $data->url, $data->country_code);
				if(!$location->error){
					// Successfully Added
					$json['id'] = $location->id;
					$json['object'] = 'location';
					$json['name'] = $location->name;
					$json['brewer_id'] = $location->brewerID;
					$json['url'] = $location->url;
					$json['country_code'] = $location->countryCode;
					$json['country_short_name'] = $location->countryShortName;
					$json['latitude'] = $location->latitude;
					$json['longitude'] = $location->longitude;
				}else{
					// Error Adding Location
					$responseCode = 400;
					$json['error'] = true;
					$json['error_msg'] = $location->errorMsg;
					$json['valid_state'] = $location->validState;
					$json['valid_msg'] = $location->validMsg;
				}
			}
			break;
		case 'GET':
			if(!empty($id)){
				// Validate ID
				if($location->validate($id, true)){
					// Valid Location
					$json['id'] = $location->id;
					$json['object'] = 'location';
					$json['name'] = $location->name;
					$json['brewer_id'] = $location->brewerID;
					$json['url'] = $location->url;
					$json['country_code'] = $location->countryCode;
					$json['country_short_name'] = $location->countryShortName;
					$json['latitude'] = $location->latitude;
					$json['longitude'] = $location->longitude;
					
					// Check for Address
					if($usAddresses->validate($location->id, true)){
						$json['telephone'] = $usAddresses->telephone;
						$json['address']['address1'] = $usAddresses->address1;
						$json['address']['address2'] = $usAddresses->address2;
						$json['address']['city'] = $usAddresses->city;
						$json['address']['sub_code'] = $usAddresses->sub_code;
						$json['address']['state_short'] = $usAddresses->stateShort;
						$json['address']['state_long'] = $usAddresses->stateLong;
						$json['address']['zip5'] = $usAddresses->zip5;
						$json['address']['zip4'] = $usAddresses->zip4;
					}
				}else{
					// Invalid Location
					$responseCode = 404;
					$json['error'] = true;
					$json['error_msg'] = 'Sorry, we don\'t have any locations with that location_id. Please check your request and try again.';
				}
			}else{
				// Invalid Endpoint
				$responseCode = 400;
				$json['error'] = true;
				$json['error_msg'] = 'Sorry, this is an invalid endpoint. You can list all the locations for a specific brewery (GET https://api.catalog.beer/brewer/{brewer_id}/locations).';
			}
			break;
		default:
			// Invalid Method
			$responseCode = 404;
			$json['error'] = true;
			$json['error_msg'] = 'Sorry, ' . $method . ' is an invalid method for this endpoint.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 74;
			$errorLog->errorMsg = 'Invalid Method (/location)';
			$errorLog->badData = $method;
			$errorLog->filename = 'API / index.php';
			$errorLog->write();
	}
}

/* - - - - - RESPONSE - - - - - */

// HTTP Status Code
http_response_code($responseCode);

// Header Type
header('Content-Type: application/json');

// Output JSON
if($json_encoded = json_encode($json)){
	echo $json_encoded;
}else{
	$json_orig = $json;
	$json = array();
	$json['error'] = true;
	$json['error_msg'] = 'Sorry, we have encountered an encoding error and are unable to present your data at this time. We\'ve logged the issue and our support team will look into it.';
	echo json_encode($json);
	
	// Log Error
	$errorLog = new LogError();
	$errorLog->errorNumber = 45;
	$errorLog->errorMsg = 'JSON Encoding Error';
	$errorLog->badData = $json_orig;
	$errorLog->filename = 'API / index.php';
	$errorLog->write();
}

// Log
$apiLogging = new apiLogging();
$apiLogging->add($apiKey, $method, $_SERVER['REQUEST_URI'], $data, $json_encoded, $responseCode);
?>