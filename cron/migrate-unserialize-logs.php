<?php
// One-shot migration: unwrap serialize()-wrapped values in error_log.badData and api_logging.body.
// Safe to re-run; rows that don't look serialized are skipped.
//
// Usage: php cron/migrate-unserialize-logs.php [staging|production] [--dry-run]

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__));

$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php migrate-unserialize-logs.php [staging|production] [--dry-run]\n";
    exit(1);
}
define('ENVIRONMENT', $env);

$dryRun = in_array('--dry-run', $argv, true);

require_once ROOT . '/common/passwords.php';
date_default_timezone_set('America/Los_Angeles');

spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

// Detect a PHP-serialized scalar/array/object string cheaply.
// Real payloads in these columns are always s:N:"..."; (from LogError)
// or O:8:"stdClass":... (from apiLogging), with rare a:N:{...} possible.
function looksSerialized(string $raw): bool {
    if($raw === '' || strlen($raw) < 4){
        return false;
    }
    return (bool)preg_match('/^(s|a|O|i|d|b|N):/', $raw);
}

// Safely coerce a value back to a string suitable for the migrated column.
// For apiLogging.body, originally a stdClass; json_encode is the clean form.
// For error_log.badData, originally always a string per every caller.
function toStorableString($value): ?string {
    if(is_string($value)){
        return $value;
    }
    if(is_scalar($value) || $value === null){
        return (string)$value;
    }
    // Objects/arrays — JSON is the readable form.
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $json === false ? null : $json;
}

// After the Database utf8mb4 switch, rows containing invalid UTF-8 bytes
// will fail to re-insert. Sanitize to replacement chars so we can migrate them.
function sanitizeUtf8(string $s): string {
    if(mb_check_encoding($s, 'UTF-8')){
        return $s;
    }
    return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
}

function migrateTable(Database $db, string $table, string $column, string $idColumn, int $batchSize, bool $dryRun): void {
    echo "\n=== Migrating {$table}.{$column} ===\n";

    $result = $db->query("SELECT COUNT(*) AS c FROM {$table}");
    $total = $result ? (int)$result->fetch_assoc()['c'] : 0;
    echo "Total rows: {$total}\n";

    $scanned = 0;
    $migrated = 0;
    $skippedUnserializable = 0;
    $skippedAlreadyPlain = 0;
    $lastId = '';

    while(true){
        // Cursor-paginate by id to avoid OFFSET scanning.
        $sql = $lastId === ''
            ? "SELECT {$idColumn}, {$column} FROM {$table} ORDER BY {$idColumn} ASC LIMIT ?"
            : "SELECT {$idColumn}, {$column} FROM {$table} WHERE {$idColumn} > ? ORDER BY {$idColumn} ASC LIMIT ?";
        $params = $lastId === '' ? [$batchSize] : [$lastId, $batchSize];

        $result = $db->query($sql, $params);
        if(!$result || $result->num_rows === 0){
            break;
        }

        while($row = $result->fetch_assoc()){
            $scanned++;
            $lastId = $row[$idColumn];
            $raw = $row[$column];

            if($raw === null || $raw === ''){
                $skippedAlreadyPlain++;
                continue;
            }
            if(!looksSerialized($raw)){
                $skippedAlreadyPlain++;
                continue;
            }

            // Suppress unserialize notices for malformed data.
            $unserialized = @unserialize($raw);
            if($unserialized === false && $raw !== 'b:0;'){
                // Not valid serialized data — looks like it, but isn't. Leave alone.
                $skippedUnserializable++;
                continue;
            }

            $clean = toStorableString($unserialized);
            if($clean === null){
                $skippedUnserializable++;
                continue;
            }
            $clean = sanitizeUtf8($clean);

            if(!$dryRun){
                $db->query("UPDATE {$table} SET {$column}=? WHERE {$idColumn}=?", [$clean, $lastId]);
            }
            $migrated++;
        }

        echo "  ...scanned {$scanned}/{$total}, migrated {$migrated}\n";
        if($result->num_rows < $batchSize){
            break;
        }
    }

    echo "Done: scanned={$scanned}, migrated={$migrated}, skipped_plain={$skippedAlreadyPlain}, skipped_unserializable={$skippedUnserializable}\n";
}

echo "Environment: " . ENVIRONMENT . ($dryRun ? " (DRY RUN)" : "") . "\n";

$db = new Database();
if($db->error){
    echo "Database connection error\n";
    exit(1);
}

migrateTable($db, 'error_log', 'badData', 'id', 1000, $dryRun);
migrateTable($db, 'api_logging', 'body', 'id', 1000, $dryRun);

$db->close();
echo "\nMigration complete.\n";
?>
