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

	// Tables: convert consecutive lines starting with | into <table>
	$html = preg_replace_callback('/(?:^\|.+\|$\n?)+/m', function($match){
		$lines = explode("\n", trim($match[0]));
		if(count($lines) < 2) return $match[0];
		$table = '<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">';
		$isHeader = true;
		foreach($lines as $line){
			if(preg_match('/^\|[\s\-:]+\|$/', $line)) continue;
			$cells = array_map('trim', explode('|', trim($line, '|')));
			$tag = $isHeader ? 'th' : 'td';
			$style = $isHeader
				? 'text-align: left; padding: 8px; border-bottom: 1px solid #ddd; background-color: #f0f0f0;'
				: 'padding: 8px; border-bottom: 1px solid #eee;';
			$table .= '<tr>';
			foreach($cells as $cell){
				$table .= '<' . $tag . ' style="' . $style . '">' . $cell . '</' . $tag . '>';
			}
			$table .= '</tr>';
			$isHeader = false;
		}
		$table .= '</table>';
		return $table;
	}, $html);

	// Paragraphs: double newlines become paragraph breaks
	$html = preg_replace('/\n{2,}/', '</p><p>', $html);

	// Single newlines to <br> (but not inside list/heading tags)
	$html = preg_replace('/(?<!\>)\n(?!\<)/', '<br>', $html);

	return '<p>' . $html . '</p>';
}

// This week's date range (past 7 days ending yesterday)
$weekStart = mktime(0, 0, 0, (int)date('n'), (int)date('j') - 7, (int)date('Y'));
$weekEnd = mktime(23, 59, 59, (int)date('n'), (int)date('j') - 1, (int)date('Y'));
$weekLabel = date('M j', $weekStart) . ' – ' . date('M j', $weekEnd);

// Prior week's date range (for comparison)
$priorWeekStart = mktime(0, 0, 0, (int)date('n'), (int)date('j') - 14, (int)date('Y'));
$priorWeekEnd = mktime(23, 59, 59, (int)date('n'), (int)date('j') - 8, (int)date('Y'));

$db = new Database();
if($db->error){
	echo "Database connection failed.\n";
	exit(1);
}

// This week's total error count
$result = $db->query("SELECT COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ?", [$weekStart, $weekEnd]);
$row = $result->fetch_assoc();
$weekCount = intval($row['count']);

// Prior week's count
$result = $db->query("SELECT COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ?", [$priorWeekStart, $priorWeekEnd]);
$row = $result->fetch_assoc();
$priorWeekCount = intval($row['count']);

// Top 10 errors by errorNumber (this week)
$result = $db->query("SELECT errorNumber, errorMessage, COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ? GROUP BY errorNumber, errorMessage ORDER BY count DESC LIMIT 10", [$weekStart, $weekEnd]);
$topErrors = array();
while($row = $result->fetch_assoc()){
	$topErrors[] = $row;
}

// Top 5 IPs (this week, excluding 127.0.0.1)
$result = $db->query("SELECT ipAddress, COUNT(*) AS count FROM error_log WHERE timestamp BETWEEN ? AND ? AND ipAddress != '127.0.0.1' GROUP BY ipAddress ORDER BY count DESC LIMIT 5", [$weekStart, $weekEnd]);
$topIPs = array();
while($row = $result->fetch_assoc()){
	$topIPs[] = $row;
}

// Count unresolved errors this week
$result = $db->query("SELECT COUNT(*) AS count FROM error_log WHERE resolved=0 AND timestamp BETWEEN ? AND ?", [$weekStart, $weekEnd]);
$row = $result->fetch_assoc();
$unresolvedCount = intval($row['count']);

// Query grouped unresolved errors for Claude analysis (top 50 by frequency)
$result = $db->query("SELECT errorNumber, errorMessage, COUNT(*) AS count, COUNT(DISTINCT ipAddress) AS unique_ips, COUNT(DISTINCT URI) AS unique_uris, MIN(timestamp) AS first_seen, MAX(timestamp) AS last_seen, SUBSTRING(MIN(badData), 1, 200) AS sample_bad_data FROM error_log WHERE resolved=0 AND timestamp BETWEEN ? AND ? GROUP BY errorNumber, errorMessage ORDER BY count DESC LIMIT 50", [$weekStart, $weekEnd]);
$groupedErrors = array();
while($row = $result->fetch_assoc()){
	$groupedErrors[] = $row;
}

$db->close();

// Claude AI Analysis
$claudeAnalysis = null;
if(!empty($groupedErrors) && defined('ANTHROPIC_API_KEY') && !empty(ANTHROPIC_API_KEY)){
	// Build CSV data from grouped errors
	$csvData = "errorNumber,errorMessage,count,unique_ips,unique_uris,first_seen,last_seen,sample_bad_data\n";
	foreach($groupedErrors as $row){
		$csvData .= '"' . str_replace('"', '""', $row['errorNumber']) . '",';
		$csvData .= '"' . str_replace('"', '""', substr($row['errorMessage'], 0, 500)) . '",';
		$csvData .= $row['count'] . ',';
		$csvData .= $row['unique_ips'] . ',';
		$csvData .= $row['unique_uris'] . ',';
		$csvData .= '"' . date('Y-m-d H:i:s', $row['first_seen']) . '",';
		$csvData .= '"' . date('Y-m-d H:i:s', $row['last_seen']) . '",';
		$csvData .= '"' . str_replace('"', '""', $row['sample_bad_data'] ?? '') . '"' . "\n";
	}

	// Load system prompt from error-context.md
	$systemPrompt = file_get_contents(__DIR__ . '/error-context.md');

	// Build user message
	$userMessage = "Here are this week's ($weekLabel) unresolved errors from the Catalog.beer API error log.\n\n";
	$userMessage .= "Summary: " . number_format($weekCount) . " total errors this week (" . number_format($unresolvedCount) . " unresolved), " . number_format($priorWeekCount) . " the prior week.\n\n";
	$userMessage .= "Grouped errors — top " . count($groupedErrors) . " of " . number_format($unresolvedCount) . " unresolved (CSV):\n" . $csvData;

	// Safety check: truncate if message exceeds ~400KB to stay under token limits
	if(strlen($userMessage) > 400000){
		$userMessage = substr($userMessage, 0, 400000) . "\n\n[Data truncated due to size]";
	}

	// Call Claude Messages API
	$requestBody = json_encode([
		'model' => 'claude-haiku-4-5-20251001',
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
$change = $weekCount - $priorWeekCount;
if($change > 0){
	$changeText = "(+" . number_format($change) . " from prior week)";
}elseif($change < 0){
	$changeText = "(" . number_format($change) . " from prior week)";
}else{
	$changeText = "(no change from prior week)";
}

// Admin page URL
if($env === 'staging'){
	$adminUrl = 'https://staging.catalog.beer/admin/error-log';
}else{
	$adminUrl = 'https://catalog.beer/admin/error-log';
}

// Build plain text body
$textBody = "Weekly Error Digest: $weekLabel\r\n";
$textBody .= "================================\r\n\r\n";
$textBody .= "Total errors: " . number_format($weekCount) . " $changeText\r\n\r\n";

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
$htmlContent = '<h1>Weekly Error Digest</h1>';
$htmlContent .= '<p><strong>' . htmlspecialchars($weekLabel) . '</strong></p>';
$htmlContent .= '<p>Total errors: <strong>' . number_format($weekCount) . '</strong> ' . htmlspecialchars($changeText) . '</p>';

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
$sendEmail->errorDigest($htmlContent, $textBody, number_format($weekCount), $weekLabel);

if($sendEmail->error){
	echo "Error sending digest: " . $sendEmail->errorMsg . "\n";
	exit(1);
}else{
	echo "Weekly error digest sent for $weekLabel: " . number_format($weekCount) . " errors\n";
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
