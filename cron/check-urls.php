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
$result = $db->query("SELECT id, name, url, urlFailCount, urlDomainRegistered, createdAt FROM brewer WHERE url IS NOT NULL AND url != '' ORDER BY (urlCheckedAt IS NULL) DESC, urlCheckedAt ASC LIMIT ?", [$limit]);
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
$upgraded = array();    // http:// promoted to the site's own https:// redirect
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
            // Where the URL actually lands — kept (not NULLed) so an ok row
            // still records its redirect target. check() sets final_url only
            // when it differs from the stored URL.
            $urlFinal = !empty($check['final_url']) ? substr($check['final_url'], 0, 255) : null;

            // The one write this report-only cron makes to the URL itself:
            // promote http:// to the https:// the site's own redirect landed
            // on. Same host (www aside), so domainName and staff permissions
            // can't shift — we're recording the operator's decision, not
            // making one. Everything broader stays in the report.
            $promoted = ($urlFinal !== null) ? $urlCheck->httpsUpgrade($brewer['url'], $urlFinal) : null;
            if($promoted !== null){
                $db->query("UPDATE brewer SET url=?, urlStatus='ok', urlCheckedAt=?, urlLastOkAt=?, urlFailCount=0, urlFinal=NULL, lastModified=? WHERE id=?", [$promoted, $now, $now, $now, $brewer['id']]);
                if(!$db->error){
                    // The Algolia brewer record carries url — keep it in step
                    Brewer::refreshSearchObject($brewer['id']);
                    // Every URL change goes in the history, including this one —
                    // it is the only write this cron makes to the URL itself.
                    Brewer::logURLChange($brewer['id'], $brewer['url'], $promoted, 'ok', 'cron', 'Promoted to the https:// the site redirects to');
                    $upgraded[] = array('brewer' => $brewer, 'to' => $promoted);
                }
            }else{
                $db->query("UPDATE brewer SET urlStatus='ok', urlCheckedAt=?, urlLastOkAt=?, urlFailCount=0, urlFinal=? WHERE id=?", [$now, $now, $urlFinal, $brewer['id']]);
            }
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

    /*--
    Registration date of the domain, for the statuses where "did this brewery
    close, or did someone else take the domain?" is the open question. A date
    later than the brewer's createdAt means the domain lapsed after we catalogued
    it and was re-registered by a third party — which is the difference between a
    closure and a hijack, and the one signal urlLastOkAt cannot supply
    retroactively.

    Looked up once and kept: registration dates do not change, and RDAP is a
    courtesy service. Refreshed only when we have no value yet.
    --*/
    $domainRegistered = $brewer['urlDomainRegistered'];
    if($domainRegistered === null && in_array($status, ['moved', 'parked', 'gone', 'no_answer'])){
        $host = parse_url($brewer['url'], PHP_URL_HOST);
        if(!empty($host)){
            $domainRegistered = $urlCheck->rdapRegistrationDate($urlCheck->registrableDomain($host));
            if($domainRegistered !== null){
                $db->query("UPDATE brewer SET urlDomainRegistered=? WHERE id=?", [$domainRegistered, $brewer['id']]);
            }
        }
    }
    $brewer['urlDomainRegistered'] = $domainRegistered;

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

if(!empty($upgraded)){
    echo "\n===== https upgrades applied =====\n";
    foreach($upgraded as $entry){
        echo "- " . $entry['brewer']['name'] . "\n";
        echo "  " . $entry['brewer']['url'] . " -> " . $entry['to'] . "\n";
    }
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
        // Looked up and stored in the main loop above — a registration date after
        // the brewer was catalogued means the domain changed hands since.
        $registered = $brewer['urlDomainRegistered'];
        if($registered !== null){
            echo "  domain registered: $registered";
            if(!empty($brewer['createdAt']) && strtotime($registered) > intval($brewer['createdAt'])){
                echo "  <-- RE-REGISTERED since we catalogued this brewer";
            }
            echo "\n";
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
