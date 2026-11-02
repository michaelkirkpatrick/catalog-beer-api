<?php
/*--
backfill-audit.php -- the audit log's baseline (Claude Ideas/audit-log.md § 7,
step 2). One action='create', source='backfill' row per existing brewer, beer
and location, carrying the row as it stands today, stamped with the row's
createdAt. It gives every record a revision to revert TO and makes the first
live edit's diff readable.

    php cron/backfill-audit.php [staging|production] [brewer|beer|location|all] [--dry-run]

Idempotent and resumable: an entity that already has ANY audit row (a live
write since the table went in, or an earlier run) is skipped, so running it
after the API has been live for a while is correct -- the live row is the
better baseline. Reads in batches of 500 by primary key and writes each batch
in one INSERT. One-time; it is not scheduled. Expect ~230k rows and a few
minutes on production (2026-11-01 sizes).
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

if(!defined('ROOT')){
    define('ROOT', dirname(__DIR__) . '/public_html');
}

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--dry-run'));
$dryRun = in_array('--dry-run', $argv, true);
$env = $args[0] ?? 'production';
$only = $args[1] ?? 'all';
if(!in_array($env, ['staging', 'production']) || !in_array($only, ['brewer', 'beer', 'location', 'all'])){
    echo "Usage: php backfill-audit.php [staging|production] [brewer|beer|location|all] [--dry-run]\n";
    exit(1);
}
// tests/audit.php includes this file with the scratch database's constants
// already defined, so both are conditional.
if(!defined('ENVIRONMENT')){
    define('ENVIRONMENT', $env);
}
if(!defined('DB_NAME')){
    require_once dirname(__DIR__) . '/common/passwords.php';
}
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

Audit::setContext(null, null, 'backfill');
$batch = 500;
$types = $only === 'all' ? array_keys(Audit::TABLES) : array($only);

foreach($types as $type){
    $table = Audit::TABLES[$type];
    $db = new Database();
    if($db->error){
        echo "database connection failed\n";
        exit(1);
    }
    $total = intval($db->query("SELECT COUNT(*) AS n FROM $table")->fetch_assoc()['n']);
    $seen = 0; $written = 0; $skipped = 0;
    $lastID = '';
    while(true){
        $result = $db->query("SELECT * FROM $table WHERE id > ? ORDER BY id LIMIT ?", [$lastID, $batch]);
        if($db->error || $result === null){
            echo "[$type] read failed after $seen rows: " . $db->errorMsg . "\n";
            exit(1);
        }
        if($result->num_rows === 0){
            break;
        }
        $rows = array();
        while($row = $result->fetch_assoc()){
            $rows[$row['id']] = Audit::normalizeRow($result, $row);
            $lastID = $row['id'];
        }
        $seen += count($rows);

        // Entities with a row already (a live write, or an earlier run) keep it
        $placeholders = implode(', ', array_fill(0, count($rows), '?'));
        $existing = $db->query("SELECT DISTINCT entityID FROM audit_log WHERE entityType=? AND entityID IN ($placeholders)", array_merge([$type], array_keys($rows)));
        if($db->error){
            echo "[$type] audit_log read failed: " . $db->errorMsg . "\n";
            exit(1);
        }
        while($row = $existing->fetch_assoc()){
            unset($rows[$row['entityID']]);
            $skipped++;
        }
        if(!empty($rows) && !$dryRun){
            $n = Audit::backfill($type, array_values($rows));
            if($n === 0){
                echo "[$type] insert failed after $written rows written; see error_log (371)\n";
                exit(1);
            }
            $written += $n;
        }elseif($dryRun){
            $written += count($rows);
        }
        if($seen % 10000 === 0){
            echo "[$type] $seen / $total\n";
        }
    }
    $db->close();
    printf("[%s] %d rows: %d baseline rows %s, %d already had history\n", $type, $total, $written, $dryRun ? 'would be written' : 'written', $skipped);
}
