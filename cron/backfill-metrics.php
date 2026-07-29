<?php
// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

/*
 * Replays historical daily snapshots of the size and growth metrics from the
 * createdAt columns, so the trend lines start in 2017 instead of the day the
 * nightly cron was first installed.
 *
 * Only the size/growth family can be reconstructed, and only approximately:
 *
 *   - Totals count records that still exist today. Anything created and later
 *     deleted is invisible, so historical totals run slightly low and every
 *     historical day appears to have zero deletions. deleted_* is therefore
 *     not written at all here — a real zero and an unknowable zero should not
 *     look the same in the series.
 *
 *   - Verification and completeness are current-state only. There is no record
 *     of when cbVerified was set or when a description was added, so those
 *     metrics necessarily begin on the first live snapshot.
 *
 *   - Freshness (touched_*) is not replayable either. lastModified holds only
 *     the *most recent* edit, so a row edited twice looks untouched in the
 *     earlier window, and the counts would be silently low.
 *
 * Each backfilled day is measured at 23:59:59 local, where the live cron
 * measures at whatever time it runs. The seam between the two is a few hours
 * wide on the first live day; at this catalog's rate of change that is noise.
 *
 * Usage: php backfill-metrics.php [staging|production] [--overwrite]
 *   --overwrite  replace existing metrics_daily rows (default leaves them be,
 *                so a re-run can never clobber a real snapshot)
 */

$args = array_slice($argv, 1);
$overwrite = in_array('--overwrite', $args);
$args = array_values(array_filter($args, function($a){ return $a !== '--overwrite'; }));

$env = $args[0] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php backfill-metrics.php [staging|production] [--overwrite]\n";
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

const DAY = 86400;
const ENTITIES = ['brewer', 'beer', 'location'];
const WINDOWS = [1, 7, 30, 365];

$db = new Database();
if($db->error){
    echo "Database connection failed.\n";
    exit(1);
}

// Pull every creation timestamp, sorted, so each day's counts are a pair of
// binary searches rather than a query. The whole catalog is ~68k integers.
$created = array();
$earliest = null;
foreach(ENTITIES as $entity){
    $result = $db->query("SELECT createdAt FROM `$entity` WHERE createdAt > 0 ORDER BY createdAt ASC");
    if($db->error || $result === null){
        echo "Query failed — have migrations/2026-07-28-created-at.sql and 2026-07-28-metrics-daily.sql been applied?\n";
        $db->close();
        exit(1);
    }

    $created[$entity] = array();
    while($row = $result->fetch_row()){
        $created[$entity][] = (int)$row[0];
    }

    if(empty($created[$entity])){
        echo "No createdAt values on `$entity` — run the backfill UPDATE in migrations/2026-07-28-created-at.sql first.\n";
        $db->close();
        exit(1);
    }

    $first = $created[$entity][0];
    if($earliest === null || $first < $earliest){
        $earliest = $first;
    }
}

// First day with any record, through yesterday. Today belongs to the live cron.
// Days are stepped by calendar arithmetic rather than by adding 86400, which
// would drift an hour at each DST change and eventually skip or repeat a date.
$startMonth = (int)date('n', $earliest);
$startDate = (int)date('j', $earliest);
$startYear = (int)date('Y', $earliest);

$firstDay = mktime(23, 59, 59, $startMonth, $startDate, $startYear);
$lastDay = mktime(23, 59, 59, (int)date('n'), (int)date('j') - 1, (int)date('Y'));

if($firstDay > $lastDay){
    echo "Nothing to backfill.\n";
    $db->close();
    exit(0);
}

echo "Backfilling " . date('Y-m-d', $firstDay) . " through " . date('Y-m-d', $lastDay);
echo $overwrite ? " (overwriting existing rows).\n" : " (leaving existing rows alone).\n";

$metrics = new Metrics();
$rows = array();
$days = 0;
$year = null;

for($offset = 0; ; $offset++){
    $day = mktime(23, 59, 59, $startMonth, $startDate + $offset, $startYear);
    if($day > $lastDay){
        break;
    }
    $date = date('Y-m-d', $day);

    foreach(ENTITIES as $entity){
        $total = countAtOrBefore($created[$entity], $day);
        $rows[] = array($date, "total_$entity", '', $total);

        foreach(WINDOWS as $window){
            $rows[] = array($date, "created_{$entity}_{$window}d", '',
                $total - countAtOrBefore($created[$entity], $day - $window * DAY));
        }
    }

    // Flush periodically so memory stays flat across ~3,200 days
    if(count($rows) >= 2000){
        $metrics->writeRows($db, $rows, !$overwrite);
        if($metrics->error){
            echo "Write failed: " . $metrics->errorMsg . "\n";
            $db->close();
            exit(1);
        }
        $rows = array();
    }

    $thisYear = date('Y', $day);
    if($thisYear !== $year){
        $year = $thisYear;
        echo "  $year\n";
    }

    $days++;
}

if(!empty($rows)){
    $metrics->writeRows($db, $rows, !$overwrite);
    if($metrics->error){
        echo "Write failed: " . $metrics->errorMsg . "\n";
        $db->close();
        exit(1);
    }
}

$db->close();

echo "Backfilled $days days, {$metrics->rowsWritten} rows written.\n";

/**
 * Number of entries in a sorted array at or before $timestamp (upper bound).
 */
function countAtOrBefore(array $sorted, int $timestamp): int {
    $low = 0;
    $high = count($sorted);
    while($low < $high){
        $mid = intdiv($low + $high, 2);
        if($sorted[$mid] <= $timestamp){
            $low = $mid + 1;
        }else{
            $high = $mid;
        }
    }
    return $low;
}
?>
