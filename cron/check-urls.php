<?php
// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Usage: php check-urls.php [staging|production] [limit] [--llm]
//   limit  brewers to check this run (default 160 ≈ full catalog / 30 days)
//   --llm  ask Claude for an advisory verdict on ambiguous/flagged pages
$args = array_slice($argv, 1);
$useLLM = in_array('--llm', $args);
$args = array_values(array_filter($args, function($a){ return $a !== '--llm'; }));

$env = $args[0] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php check-urls.php [staging|production] [limit] [--llm]\n";
    exit(1);
}
define('ENVIRONMENT', $env);

$limit = isset($args[1]) ? intval($args[1]) : 160;
if($limit < 1){
    echo "Usage: php check-urls.php [staging|production] [limit] [--llm]\n";
    exit(1);
}

// Load Passwords
require_once ROOT . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

// Advisory Claude verdicts are capped per run to bound cost and runtime
const MAX_LLM_CALLS = 25;

$db = new Database();
if($db->error){
    echo "Database connection failed.\n";
    exit(1);
}

// Oldest-checked first; never-checked brewers before all of them
$result = $db->query("SELECT id, name, url, urlFailCount FROM brewer WHERE url IS NOT NULL AND url != '' ORDER BY (urlCheckedAt IS NULL) DESC, urlCheckedAt ASC LIMIT ?", [$limit]);
if($db->error || $result === null){
    // Database::query() already logged the details. The most likely cause is
    // the url-status migration not being applied yet.
    echo "Query failed — has migrations/2026-07-28-brewer-url-status.sql been applied?\n";
    exit(1);
}

$brewers = array();
while($row = $result->fetch_assoc()){
    $brewers[] = $row;
}

if(empty($brewers)){
    echo "No brewers with URLs to check.\n";
    $db->close();
    exit(0);
}

echo "Checking " . count($brewers) . " brewer URLs (" . ENVIRONMENT . ")...\n\n";

$urlCheck = new UrlCheck();
$statusCounts = array();
$flagged = array();     // moved/parked — surface after one clean detection
$escalated = array();   // no_answer/gone with failCount >= 3 — review queue
$ambiguous = array();   // ok, but brewery name absent from page text
$llmCalls = 0;

foreach($brewers as $brewer){
    $check = $urlCheck->check($brewer['url'], $brewer['name']);
    $status = $check['status'];
    $now = time();

    // Escalation rules: only no_answer and gone increment the failure
    // counter; ok clears it; blocked/server_error/url_wrong never count as
    // failures (the server answered). Nothing is ever auto-deleted.
    $failCount = intval($brewer['urlFailCount']);
    switch($status){
        case 'ok':
            $failCount = 0;
            $db->query("UPDATE brewer SET urlStatus='ok', urlCheckedAt=?, urlLastOkAt=?, urlFailCount=0, urlFinal=NULL WHERE id=?", [$now, $now, $brewer['id']]);
            break;
        case 'no_answer':
        case 'gone':
            $failCount++;
            $db->query("UPDATE brewer SET urlStatus=?, urlCheckedAt=?, urlFailCount=urlFailCount+1 WHERE id=?", [$status, $now, $brewer['id']]);
            break;
        case 'moved':
        case 'parked':
            $urlFinal = !empty($check['final_url']) ? substr($check['final_url'], 0, 255) : null;
            $db->query("UPDATE brewer SET urlStatus=?, urlCheckedAt=?, urlFinal=? WHERE id=?", [$status, $now, $urlFinal, $brewer['id']]);
            break;
        default: // blocked, server_error, url_wrong
            $db->query("UPDATE brewer SET urlStatus=?, urlCheckedAt=? WHERE id=?", [$status, $now, $brewer['id']]);
    }
    if($db->error){
        echo "Database write failed — stopping. (Details logged.)\n";
        exit(1);
    }

    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    echo "[" . str_pad($status, 12) . "] " . $brewer['name'] . " — " . $brewer['url'];
    if($check['detail'] !== ''){
        echo " (" . $check['detail'] . ")";
    }
    echo "\n";

    $entry = array('brewer' => $brewer, 'check' => $check, 'fail_count' => $failCount);
    if($status === 'moved' || $status === 'parked'){
        $flagged[] = $entry;
    }elseif(($status === 'no_answer' || $status === 'gone') && $failCount >= 3){
        $escalated[] = $entry;
    }elseif($status === 'ok' && $check['name_found'] === false){
        $ambiguous[] = $entry;
    }

    // Be gentle: the Nanode serves the website and the API too
    usleep(500000);
}

// ----- Report -----

echo "\n===== Summary =====\n";
ksort($statusCounts);
foreach($statusCounts as $status => $count){
    echo str_pad($status, 14) . $count . "\n";
}

if(!empty($flagged)){
    echo "\n===== Flagged for review (moved/parked) =====\n";
    foreach($flagged as $entry){
        $brewer = $entry['brewer'];
        $check = $entry['check'];
        echo "- " . $brewer['name'] . " [" . $check['status'] . "]\n";
        echo "  stored: " . $brewer['url'] . "\n";
        if(!empty($check['final_url'])){
            echo "  final:  " . $check['final_url'] . "\n";
        }
        echo "  detail: " . $check['detail'] . "\n";
        // Registration date on the flagged domain: a recent date means the
        // domain lapsed and was re-registered by someone else
        $host = parse_url($brewer['url'], PHP_URL_HOST);
        if(!empty($host)){
            $registered = $urlCheck->rdapRegistrationDate($urlCheck->registrableDomain($host));
            if($registered !== null){
                echo "  domain registered: $registered\n";
            }
        }
    }
}

if(!empty($escalated)){
    echo "\n===== Sustained failures (fail count >= 3) =====\n";
    foreach($escalated as $entry){
        $brewer = $entry['brewer'];
        echo "- " . $brewer['name'] . " [" . $entry['check']['status'] . ", " . $entry['fail_count'] . " consecutive failures]\n";
        echo "  stored: " . $brewer['url'] . "\n";
    }
}

if(!empty($ambiguous)){
    echo "\n===== Ambiguous (alive, but brewery name absent from page) =====\n";
    foreach($ambiguous as $entry){
        echo "- " . $entry['brewer']['name'] . " — " . $entry['brewer']['url'] . "\n";
    }
}

// ----- Optional Claude tier (advisory only — never written to the DB) -----

if($useLLM){
    $candidates = array_merge($flagged, $ambiguous);
    if(empty($candidates)){
        echo "\nNo candidates for Claude classification this run.\n";
    }else{
        echo "\n===== Claude verdicts (advisory) =====\n";
        foreach($candidates as $entry){
            if($llmCalls >= MAX_LLM_CALLS){
                echo "(Cap of " . MAX_LLM_CALLS . " Claude calls reached — " . (count($candidates) - $llmCalls) . " candidates skipped this run.)\n";
                break;
            }
            $brewer = $entry['brewer'];
            $check = $entry['check'];
            $verdict = $urlCheck->classifyWithClaude(
                $brewer['name'],
                $brewer['url'],
                $check['final_url'] ?? $brewer['url'],
                $check['title'],
                $check['text_excerpt']
            );
            $llmCalls++;
            if($verdict === null){
                echo "- " . $brewer['name'] . ": (classification unavailable)\n";
            }else{
                echo "- " . $brewer['name'] . ": " . $verdict['verdict'] . " — " . $verdict['reason'] . "\n";
            }
        }
    }
}

$db->close();
echo "\nDone.\n";
?>
