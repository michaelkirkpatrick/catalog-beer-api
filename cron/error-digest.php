<?php
// CLI only
if(php_sapi_name() !== 'cli'){
	exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
	echo "Usage: php error-digest.php [staging|production]\n";
	exit(1);
}
define('ENVIRONMENT', $env);

// Load Passwords
require_once ROOT . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
	require_once ROOT . '/classes/' . $class_name . '.class.php';
});

// Simple markdown to HTML conversion for email
function markdownToHtml($markdown){
	$html = htmlspecialchars($markdown);

	// Headings (processed largest first to avoid partial matches)
	$html = preg_replace('/^#{3}\s+(.+)$/m', '<h5>$1</h5>', $html);
	$html = preg_replace('/^#{2}\s+(.+)$/m', '<h4>$1</h4>', $html);
	$html = preg_replace('/^#{1}\s+(.+)$/m', '<h3>$1</h3>', $html);

	// Bold
	$html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

	// Italic
	$html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

	// Inline code
	$html = preg_replace('/`(.+?)`/', '<code style="background-color: #e9ecef; padding: 2px 4px;">$1</code>', $html);

	// Bullet lists: convert consecutive lines starting with - into <ul><li>
	$html = preg_replace_callback('/(?:^- .+$\n?)+/m', function($match){
		$items = preg_replace('/^- (.+)$/m', '<li>$1</li>', trim($match[0]));
		return '<ul style="margin: 8px 0; padding-left: 20px;">' . $items . '</ul>';
	}, $html);

	// Numbered lists: convert consecutive lines starting with digits. into <ol><li>
	$html = preg_replace_callback('/(?:^\d+\. .+$\n?)+/m', function($match){
		$items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($match[0]));
		return '<ol style="margin: 8px 0; padding-left: 20px;">' . $items . '</ol>';
	}, $html);

	// Paragraphs: double newlines become paragraph breaks
	$html = preg_replace('/\n{2,}/', '</p><p>', $html);

	// Single newlines to <br> (but not inside list/heading tags)
	$html = preg_replace('/(?<!\>)\n(?!\<)/', '<br>', $html);

	return '<p>' . $html . '</p>';
}

// Yesterday's date range (Unix timestamps)
$yesterdayStart = mktime(0, 0, 0, (int)date('n'), (int)date('j') - 1, (int)date('Y'));
$yesterdayEnd = mktime(23, 59, 59, (int)date('n'), (int)date('j') - 1, (int)date('Y'));
$yesterdayDate = date('Y-m-d', $yesterdayStart);

// Prior day's date range (for comparison)
$priorDayStart = mktime(0, 0, 0, (int)date('n'), (int)date('j') - 2, (int)date('Y'));
$priorDayEnd = mktime(23, 59, 59, (int)date('n'), (int)date('j') - 2, (int)date('Y'));

$db = new Database();
if($db->error){
	echo "Database connection failed.\n";
	exit(1);
}

// Yesterday's total error count
$result = $db->query("SELECT COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ?", [$yesterdayStart, $yesterdayEnd]);
$row = $result->fetch_assoc();
$yesterdayCount = intval($row['count']);

// Prior day's count
$result = $db->query("SELECT COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ?", [$priorDayStart, $priorDayEnd]);
$row = $result->fetch_assoc();
$priorDayCount = intval($row['count']);

// Top 10 errors by errorNumber (yesterday)
$result = $db->query("SELECT errorNumber, errorMessage, COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ? GROUP BY errorNumber, errorMessage ORDER BY count DESC LIMIT 10", [$yesterdayStart, $yesterdayEnd]);
$topErrors = array();
while($row = $result->fetch_assoc()){
	$topErrors[] = $row;
}

// Top 5 IPs (yesterday, excluding 127.0.0.1)
$result = $db->query("SELECT ipAddress, COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ? AND ipAddress != '127.0.0.1' GROUP BY ipAddress ORDER BY count DESC LIMIT 5", [$yesterdayStart, $yesterdayEnd]);
$topIPs = array();
while($row = $result->fetch_assoc()){
	$topIPs[] = $row;
}

// Query all unresolved errors from yesterday for Claude analysis
$result = $db->query("SELECT errorNumber, errorMessage, URI, ipAddress, timestamp, SUBSTRING(badData, 1, 200) AS badData FROM error_log WHERE resolved=0 AND timestamp BETWEEN ? AND ? ORDER BY timestamp", [$yesterdayStart, $yesterdayEnd]);
$errorRows = array();
while($row = $result->fetch_assoc()){
	$errorRows[] = $row;
}

$db->close();

// Claude AI Analysis
$claudeAnalysis = null;
if(!empty($errorRows) && defined('ANTHROPIC_API_KEY') && !empty(ANTHROPIC_API_KEY)){
	// Build CSV data
	$csvData = "errorNumber,errorMessage,URI,ipAddress,timestamp,badData\n";
	foreach($errorRows as $row){
		$csvData .= '"' . str_replace('"', '""', $row['errorNumber']) . '",';
		$csvData .= '"' . str_replace('"', '""', $row['errorMessage']) . '",';
		$csvData .= '"' . str_replace('"', '""', $row['URI']) . '",';
		$csvData .= '"' . str_replace('"', '""', $row['ipAddress']) . '",';
		$csvData .= '"' . date('Y-m-d H:i:s', $row['timestamp']) . '",';
		$csvData .= '"' . str_replace('"', '""', $row['badData'] ?? '') . '"' . "\n";
	}

	// Load system prompt from error-context.md
	$systemPrompt = file_get_contents(__DIR__ . '/error-context.md');

	// Build user message
	$unresolvedCount = count($errorRows);
	$userMessage = "Here are yesterday's ($yesterdayDate) unresolved errors from the Catalog.beer API error log.\n\n";
	$userMessage .= "Summary: " . number_format($yesterdayCount) . " total errors yesterday (" . number_format($unresolvedCount) . " unresolved), " . number_format($priorDayCount) . " the prior day.\n\n";
	$userMessage .= "Full error data — " . number_format($unresolvedCount) . " unresolved errors (CSV):\n" . $csvData;

	// Call Claude Messages API
	$requestBody = json_encode([
		'model' => 'claude-opus-4-6',
		'max_tokens' => 2048,
		'system' => $systemPrompt,
		'messages' => [
			['role' => 'user', 'content' => $userMessage]
		]
	], JSON_INVALID_UTF8_SUBSTITUTE);

	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 120,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_HTTPHEADER => [
			'x-api-key: ' . ANTHROPIC_API_KEY,
			'anthropic-version: 2023-06-01',
			'content-type: application/json'
		],
		CURLOPT_POSTFIELDS => $requestBody
	]);

	$response = curl_exec($curl);
	$curlError = curl_error($curl);
	$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	curl_close($curl);

	if($curlError){
		// Log cURL error but continue without analysis
		echo "Claude API cURL error: $curlError\n";
		$errorLog = new LogError();
		$errorLog->errorNumber = '253';
		$errorLog->errorMsg = 'Claude API cURL error';
		$errorLog->badData = $curlError;
		$errorLog->filename = 'cron/error-digest.php';
		$errorLog->write();
	}elseif($httpCode !== 200){
		// Log non-200 response but continue without analysis
		echo "Claude API returned HTTP $httpCode\n";
		$errorLog = new LogError();
		$errorLog->errorNumber = '254';
		$errorLog->errorMsg = 'Claude API non-200 response';
		$errorLog->badData = "HTTP $httpCode: " . substr($response, 0, 500);
		$errorLog->filename = 'cron/error-digest.php';
		$errorLog->write();
	}else{
		$decoded = json_decode($response, true);
		if(isset($decoded['content'][0]['text'])){
			$claudeAnalysis = $decoded['content'][0]['text'];
		}
	}
}

// Determine change direction
$change = $yesterdayCount - $priorDayCount;
if($change > 0){
	$changeText = "(+" . number_format($change) . " from prior day)";
}elseif($change < 0){
	$changeText = "(" . number_format($change) . " from prior day)";
}else{
	$changeText = "(no change from prior day)";
}

// Admin page URL
if($env === 'staging'){
	$adminUrl = 'https://staging.catalog.beer/admin/error-log';
}else{
	$adminUrl = 'https://catalog.beer/admin/error-log';
}

// Build plain text body
$textBody = "Error Digest for $yesterdayDate\r\n";
$textBody .= "================================\r\n\r\n";
$textBody .= "Total errors: " . number_format($yesterdayCount) . " $changeText\r\n\r\n";

if(!empty($topErrors)){
	$textBody .= "Top Errors:\r\n";
	foreach($topErrors as $err){
		$textBody .= "  #" . $err['errorNumber'] . " - " . $err['errorMessage'] . " (" . number_format($err['count']) . ")\r\n";
	}
	$textBody .= "\r\n";
}

if(!empty($topIPs)){
	$textBody .= "Top IPs:\r\n";
	foreach($topIPs as $ip){
		$textBody .= "  " . $ip['ipAddress'] . " (" . number_format($ip['count']) . ")\r\n";
	}
	$textBody .= "\r\n";
}

if(!empty($claudeAnalysis)){
	$textBody .= "AI Analysis:\r\n";
	$textBody .= "--------------------------------\r\n";
	$textBody .= $claudeAnalysis . "\r\n\r\n";
}

$textBody .= "View full report: $adminUrl\r\n";

// Build HTML content (for ##CONTENT## placeholder)
$htmlContent = '<h1>Error Digest</h1>';
$htmlContent .= '<p><strong>' . htmlspecialchars($yesterdayDate) . '</strong></p>';
$htmlContent .= '<p>Total errors: <strong>' . number_format($yesterdayCount) . '</strong> ' . htmlspecialchars($changeText) . '</p>';

if(!empty($claudeAnalysis)){
	$htmlContent .= '<div style="background-color: #f8f9fa; border-left: 4px solid #4a90d9; padding: 16px; margin: 20px 0;">';
	$htmlContent .= '<h2 style="margin-top: 0;">AI Analysis</h2>';
	$htmlContent .= markdownToHtml($claudeAnalysis);
	$htmlContent .= '</div>';
}

if(!empty($topErrors)){
	$htmlContent .= '<h2>Top Errors</h2>';
	$htmlContent .= '<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">';
	$htmlContent .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">#</th><th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">Message</th><th style="text-align: right; padding: 8px; border-bottom: 1px solid #ddd;">Count</th></tr>';
	foreach($topErrors as $err){
		$htmlContent .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars($err['errorNumber']) . '</td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars($err['errorMessage']) . '</td><td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;">' . number_format($err['count']) . '</td></tr>';
	}
	$htmlContent .= '</table>';
}

if(!empty($topIPs)){
	$htmlContent .= '<h2>Top IPs</h2>';
	$htmlContent .= '<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">';
	$htmlContent .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">IP Address</th><th style="text-align: right; padding: 8px; border-bottom: 1px solid #ddd;">Count</th></tr>';
	foreach($topIPs as $ip){
		$htmlContent .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars($ip['ipAddress']) . '</td><td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;">' . number_format($ip['count']) . '</td></tr>';
	}
	$htmlContent .= '</table>';
}

$htmlContent .= '<p style="margin-top: 20px;"><a href="' . $adminUrl . '">View Full Report</a></p>';

// Send email
$sendEmail = new SendEmail();
$sendEmail->errorDigest($htmlContent, $textBody, number_format($yesterdayCount), $yesterdayDate);

if($sendEmail->error){
	echo "Error sending digest: " . $sendEmail->errorMsg . "\n";
	exit(1);
}else{
	echo "Error digest sent for $yesterdayDate: " . number_format($yesterdayCount) . " errors\n";
}

// Purge resolved errors older than 90 days
$purgeThreshold = strtotime('-90 days');
$db = new Database();
if(!$db->error){
	$db->query("DELETE FROM error_log WHERE resolved=1 AND timestamp < ?", [$purgeThreshold]);
	$purgeCount = $db->getConnection()->affected_rows;
	if($purgeCount > 0){
		echo "Purged $purgeCount resolved errors older than 90 days\n";
	}
	$db->close();
}
?>
