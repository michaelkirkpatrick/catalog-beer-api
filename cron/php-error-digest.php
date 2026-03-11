<?php
// Weekly PHP Error Digest
// Reads /var/log/php/error.log, groups errors, sends to Claude for analysis,
// and emails a digest. Covers all PHP sites on the server.
//
// Usage: php php-error-digest.php [staging|production]
// Cron:  0 7 * * 1 php /var/www/html/api.catalog.beer/public_html/cron/php-error-digest.php production

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php php-error-digest.php [staging|production]\n";
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

// PHP error log path
$logBasePath = '/var/log/php/error.log';

// This week's date range (past 7 days ending yesterday)
$weekStart = mktime(0, 0, 0, (int)date('n'), (int)date('j') - 7, (int)date('Y'));
$weekEnd = mktime(23, 59, 59, (int)date('n'), (int)date('j') - 1, (int)date('Y'));
$weekLabel = date('M j', $weekStart) . ' – ' . date('M j', $weekEnd);

// Read log files covering the past week
// error.log (current), error.log.1 (previous rotation, plain text),
// error.log.2.gz through error.log.7.gz (older, gzip compressed)
$allLines = [];

// Read plain text log files
foreach([$logBasePath, $logBasePath . '.1'] as $file){
    if(file_exists($file) && is_readable($file)){
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if($lines !== false){
            $allLines = array_merge($allLines, $lines);
        }
    }
}

// Read gzipped log files
for($i = 2; $i <= 7; $i++){
    $file = $logBasePath . '.' . $i . '.gz';
    if(file_exists($file) && is_readable($file)){
        $lines = gzfile($file);
        if($lines !== false){
            $allLines = array_merge($allLines, array_map('rtrim', $lines));
        }
    }
}

if(empty($allLines)){
    echo "No log files found or readable at $logBasePath\n";
    exit(1);
}

// Parse log entries (multi-line entries: stack traces don't start with [)
$entries = [];
$currentEntry = null;
foreach($allLines as $line){
    if(preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}) (\S+)\] (.+)$/', $line, $matches)){
        // Save previous entry
        if($currentEntry !== null){
            $entries[] = $currentEntry;
        }
        // Parse timestamp with timezone from the log line
        try {
            $tz = new DateTimeZone($matches[2]);
        } catch(Exception $e) {
            $tz = new DateTimeZone('UTC');
        }
        $dt = DateTime::createFromFormat('d-M-Y H:i:s', $matches[1], $tz);
        $currentEntry = [
            'timestamp' => $dt ? $dt->getTimestamp() : 0,
            'datetime' => $matches[1] . ' ' . $matches[2],
            'message' => $matches[3],
            'stack_trace' => ''
        ];
    }elseif($currentEntry !== null){
        // Continuation line (stack trace)
        $currentEntry['stack_trace'] .= ($currentEntry['stack_trace'] ? "\n" : '') . $line;
    }
}
// Don't forget the last entry
if($currentEntry !== null){
    $entries[] = $currentEntry;
}

// Filter to date range
$entries = array_filter($entries, function($entry) use ($weekStart, $weekEnd){
    return $entry['timestamp'] >= $weekStart && $entry['timestamp'] <= $weekEnd;
});
$entries = array_values($entries);

// Known noise patterns to filter out
$noisePatterns = [
    '/imagick module already loaded/i',
];

// Separate noise from real errors
$noiseCount = 0;
$filteredEntries = [];
foreach($entries as $entry){
    $isNoise = false;
    foreach($noisePatterns as $pattern){
        if(preg_match($pattern, $entry['message'])){
            $isNoise = true;
            $noiseCount++;
            break;
        }
    }
    if(!$isNoise){
        $filteredEntries[] = $entry;
    }
}
$totalCount = count($entries);
$filteredCount = count($filteredEntries);

// Identify which site each error belongs to based on file path
function identifySite($message){
    if(preg_match('/\/var\/www\/html\/([^\/]+)\//', $message, $matches)){
        return $matches[1];
    }
    return 'unknown';
}

// Normalize error messages for grouping (strip file paths and line numbers)
function normalizeMessage($message){
    // Remove "in /path/to/file.php on line N" suffix
    $normalized = preg_replace('/ in \/\S+ on line \d+$/', '', $message);
    // Remove "thrown in /path/to/file.php:N" suffix
    $normalized = preg_replace('/ thrown in \/\S+:\d+$/', '', $normalized);
    return $normalized;
}

// Group errors by normalized message
$grouped = [];
foreach($filteredEntries as $entry){
    $key = normalizeMessage($entry['message']);
    if(!isset($grouped[$key])){
        $grouped[$key] = [
            'normalized' => $key,
            'count' => 0,
            'sites' => [],
            'first_seen' => $entry['datetime'],
            'last_seen' => $entry['datetime'],
            'sample_message' => $entry['message'],
            'has_stack_trace' => false,
            'sample_stack_trace' => ''
        ];
    }
    $grouped[$key]['count']++;
    $site = identifySite($entry['message']);
    if(!in_array($site, $grouped[$key]['sites'])){
        $grouped[$key]['sites'][] = $site;
    }
    $grouped[$key]['last_seen'] = $entry['datetime'];
    if(!empty($entry['stack_trace']) && !$grouped[$key]['has_stack_trace']){
        $grouped[$key]['has_stack_trace'] = true;
        $grouped[$key]['sample_stack_trace'] = $entry['stack_trace'];
    }
}

// Sort by count descending
usort($grouped, function($a, $b){
    return $b['count'] - $a['count'];
});

// Site breakdown
$siteCounts = [];
foreach($filteredEntries as $entry){
    $site = identifySite($entry['message']);
    $siteCounts[$site] = ($siteCounts[$site] ?? 0) + 1;
}
arsort($siteCounts);

// Claude AI Analysis
$claudeAnalysis = null;
if(!empty($filteredEntries) && defined('ANTHROPIC_API_KEY') && !empty(ANTHROPIC_API_KEY)){
    // Build CSV data from grouped errors (top 50)
    $topGrouped = array_slice($grouped, 0, 50);
    $csvData = "count,normalized_message,sites,first_seen,last_seen,has_stack_trace,sample_full_message\n";
    foreach($topGrouped as $group){
        $csvData .= $group['count'] . ',';
        $csvData .= '"' . str_replace('"', '""', substr($group['normalized'], 0, 500)) . '",';
        $csvData .= '"' . implode('; ', $group['sites']) . '",';
        $csvData .= '"' . $group['first_seen'] . '",';
        $csvData .= '"' . $group['last_seen'] . '",';
        $csvData .= ($group['has_stack_trace'] ? 'yes' : 'no') . ',';
        $csvData .= '"' . str_replace('"', '""', substr($group['sample_message'], 0, 500)) . '"' . "\n";
    }

    // Load system prompt
    $systemPrompt = file_get_contents(__DIR__ . '/php-error-context.md');

    // Build user message
    $userMessage = "Here are this week's ($weekLabel) PHP errors from the server error log.\n\n";
    $userMessage .= "Summary: " . number_format($totalCount) . " total entries (" . number_format($noiseCount) . " filtered as known noise, " . number_format($filteredCount) . " remaining across " . count($grouped) . " unique error types).\n\n";
    $userMessage .= "Site breakdown:\n";
    foreach($siteCounts as $site => $count){
        $userMessage .= "  $site: " . number_format($count) . "\n";
    }
    $userMessage .= "\nGrouped errors — top " . count($topGrouped) . " of " . count($grouped) . " types (CSV):\n" . $csvData;

    // Include up to 5 stack traces for context
    $traceCount = 0;
    foreach($topGrouped as $group){
        if($group['has_stack_trace'] && $traceCount < 5){
            if($traceCount === 0){
                $userMessage .= "\nSample stack traces:\n";
            }
            $userMessage .= "\n--- " . substr($group['normalized'], 0, 200) . " ---\n";
            $userMessage .= substr($group['sample_stack_trace'], 0, 1000) . "\n";
            $traceCount++;
        }
    }

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
        echo "Claude API cURL error: $curlError\n";
        $errorLog = new LogError();
        $errorLog->errorNumber = '255';
        $errorLog->errorMsg = 'Claude API cURL error (php-error-digest)';
        $errorLog->badData = $curlError;
        $errorLog->filename = 'cron/php-error-digest.php';
        $errorLog->write();
    }elseif($httpCode !== 200){
        echo "Claude API returned HTTP $httpCode\n";
        $errorLog = new LogError();
        $errorLog->errorNumber = '256';
        $errorLog->errorMsg = 'Claude API non-200 response (php-error-digest)';
        $errorLog->badData = "HTTP $httpCode: " . substr($response, 0, 500);
        $errorLog->filename = 'cron/php-error-digest.php';
        $errorLog->write();
    }else{
        $decoded = json_decode($response, true);
        if(isset($decoded['content'][0]['text'])){
            $claudeAnalysis = $decoded['content'][0]['text'];
        }
    }
}

// Build plain text body
$textBody = "Weekly PHP Error Digest: $weekLabel\r\n";
$textBody .= "================================\r\n\r\n";
$textBody .= "Total entries: " . number_format($totalCount) . "\r\n";
$textBody .= "Known noise filtered: " . number_format($noiseCount) . "\r\n";
$textBody .= "Unique error types: " . count($grouped) . "\r\n\r\n";

if(!empty($siteCounts)){
    $textBody .= "By Site:\r\n";
    foreach($siteCounts as $site => $count){
        $textBody .= "  $site: " . number_format($count) . "\r\n";
    }
    $textBody .= "\r\n";
}

if(!empty($grouped)){
    $textBody .= "Top Errors:\r\n";
    $i = 0;
    foreach($grouped as $group){
        if($i >= 10) break;
        $textBody .= "  [" . number_format($group['count']) . "x] " . $group['normalized'] . "\r\n";
        $textBody .= "    Sites: " . implode(', ', $group['sites']) . "\r\n";
        $i++;
    }
    $textBody .= "\r\n";
}

if(!empty($claudeAnalysis)){
    $textBody .= "AI Analysis:\r\n";
    $textBody .= "--------------------------------\r\n";
    $textBody .= $claudeAnalysis . "\r\n\r\n";
}

// Build HTML content
$htmlContent = '<h1>Weekly PHP Error Digest</h1>';
$htmlContent .= '<p><strong>' . htmlspecialchars($weekLabel) . '</strong></p>';
$htmlContent .= '<p>Total entries: <strong>' . number_format($totalCount) . '</strong>';
$htmlContent .= ' | Known noise filtered: ' . number_format($noiseCount);
$htmlContent .= ' | Unique error types: ' . count($grouped) . '</p>';

if(!empty($claudeAnalysis)){
    $htmlContent .= '<div style="background-color: #f8f9fa; border-left: 4px solid #4a90d9; padding: 16px; margin: 20px 0;">';
    $htmlContent .= '<h2 style="margin-top: 0;">AI Analysis</h2>';
    $htmlContent .= markdownToHtml($claudeAnalysis);
    $htmlContent .= '</div>';
}

if(!empty($siteCounts)){
    $htmlContent .= '<h2>By Site</h2>';
    $htmlContent .= '<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">';
    $htmlContent .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">Site</th><th style="text-align: right; padding: 8px; border-bottom: 1px solid #ddd;">Count</th></tr>';
    foreach($siteCounts as $site => $count){
        $htmlContent .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars($site) . '</td><td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;">' . number_format($count) . '</td></tr>';
    }
    $htmlContent .= '</table>';
}

if(!empty($grouped)){
    $htmlContent .= '<h2>Top Errors</h2>';
    $htmlContent .= '<table width="100%" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">';
    $htmlContent .= '<tr style="background-color: #f0f0f0;"><th style="text-align: right; padding: 8px; border-bottom: 1px solid #ddd;">Count</th><th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">Error</th><th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">Sites</th></tr>';
    $i = 0;
    foreach($grouped as $group){
        if($i >= 15) break;
        $htmlContent .= '<tr>';
        $htmlContent .= '<td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee; white-space: nowrap;">' . number_format($group['count']) . '</td>';
        $htmlContent .= '<td style="padding: 8px; border-bottom: 1px solid #eee; font-family: monospace; font-size: 12px; word-break: break-all;">' . htmlspecialchars($group['normalized']) . '</td>';
        $htmlContent .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars(implode(', ', $group['sites'])) . '</td>';
        $htmlContent .= '</tr>';
        $i++;
    }
    $htmlContent .= '</table>';
}

// Send email
$sendEmail = new SendEmail();
$sendEmail->phpErrorDigest($htmlContent, $textBody, number_format($filteredCount), $weekLabel);

if($sendEmail->error){
    echo "Error sending digest: " . $sendEmail->errorMsg . "\n";
    exit(1);
}else{
    echo "Weekly PHP error digest sent for $weekLabel: " . number_format($totalCount) . " total entries, " . number_format($noiseCount) . " noise filtered, " . count($grouped) . " unique error types\n";
}
?>
