<?php
http_response_code(503);
header('Content-Type: application/json');
echo json_encode([
	'error' => true,
	'error_msg' => 'The Catalog.beer API is currently down for maintenance while we perform server upgrades. We apologize for the inconvenience.',
	'contact' => 'michael@mekstudios.com'
]);
