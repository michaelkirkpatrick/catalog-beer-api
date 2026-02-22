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

$db->close();

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

$textBody .= "View full report: $adminUrl\r\n";

// Build HTML content (for ##CONTENT## placeholder)
$htmlContent = '<h1>Error Digest</h1>';
$htmlContent .= '<p><strong>' . htmlspecialchars($yesterdayDate) . '</strong></p>';
$htmlContent .= '<p>Total errors: <strong>' . number_format($yesterdayCount) . '</strong> ' . htmlspecialchars($changeText) . '</p>';

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
