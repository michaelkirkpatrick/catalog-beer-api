<?php

/**
 * Catalog health metrics.
 *
 * snapshot() writes one row per metric per day into metrics_daily. Everything
 * it records is a raw count as of the moment it runs — no composite scores, no
 * derived percentages. Ratios and "health scores" belong at display time, so
 * the formula can change without invalidating the stored history.
 *
 * Most of this cannot be reconstructed after the fact: cbVerified is a bit
 * flag with no audit trail, "beers with a description" is only knowable now,
 * and the api_logging rows behind the demand metrics are pruned at 3 months.
 * A day not snapshotted is a day lost. The exception is the size/growth
 * family, which createdAt makes replayable — see cron/backfill-metrics.php.
 */
class Metrics {

    // Validation
    public $error = false;
    public $errorMsg = null;

    // Run summary (CLI)
    public $rowsWritten = 0;
    public $snapshotDate = '';

    // Entities sharing the id/cbVerified/brewerVerified/createdAt/lastModified shape
    const ENTITIES = array('brewer', 'beer', 'location');

    const DAY = 86400;

    /**
     * Collect and store every metric as of now.
     */
    public function snapshot(){
        $db = new Database();
        if($db->error){
            $this->error = true;
            $this->errorMsg = 'Database connection error.';
            return;
        }

        // A missing table or column would poison the connection for the whole
        // run (Database::query() short-circuits once $error is set), so check
        // the migrations are in place before doing any real work.
        if(!$this->tableExists($db, 'metrics_daily')){
            $this->error = true;
            $this->errorMsg = 'metrics_daily is missing — apply migrations/2026-07-28-metrics-daily.sql.';
            $db->close();
            return;
        }
        if(!$this->columnExists($db, 'beer', 'createdAt')){
            $this->error = true;
            $this->errorMsg = 'createdAt is missing — apply migrations/2026-07-28-created-at.sql.';
            $db->close();
            return;
        }

        $asOf = time();
        $this->snapshotDate = date('Y-m-d', $asOf);

        $rows = $this->collect($db, $asOf);
        if($this->error){
            $db->close();
            return;
        }

        $rows = array_merge($rows, $this->churn($db, $this->snapshotDate, $rows));
        if($this->error){
            $db->close();
            return;
        }

        $this->write($db, $this->snapshotDate, $rows);
        $db->close();
    }

    /**
     * Every metric, as an array of [metric, dimension, value] triples.
     */
    private function collect($db, $asOf){
        $m = array();
        $totals = array();

        foreach(self::ENTITIES as $entity){

            // ----- Size -----
            $total = $this->scalar($db, "SELECT COUNT(*) FROM `$entity`");
            $totals[$entity] = $total;
            $m[] = array("total_$entity", '', $total);

            // ----- Growth -----
            foreach(array(1, 7, 30, 365) as $window){
                $m[] = array("created_{$entity}_{$window}d", '', $this->scalar($db,
                    "SELECT COUNT(*) FROM `$entity` WHERE createdAt > ?", array($asOf - $window * self::DAY)));
            }

            // ----- Freshness -----
            foreach(array(30, 90, 365) as $window){
                $m[] = array("touched_{$entity}_{$window}d", '', $this->scalar($db,
                    "SELECT COUNT(*) FROM `$entity` WHERE lastModified > ?", array($asOf - $window * self::DAY)));
            }
            $m[] = array("stale_{$entity}_2yr", '', $this->scalar($db,
                "SELECT COUNT(*) FROM `$entity` WHERE lastModified <= ?", array($asOf - 730 * self::DAY)));

            // Age of the record set in days. p50 is the typical record; p90 is
            // the tail we have not looked at in a very long time.
            $m[] = array("age_{$entity}_p50_days", '', $this->ageAtPercentile($db, $entity, $asOf, $total, 0.5));
            $m[] = array("age_{$entity}_p90_days", '', $this->ageAtPercentile($db, $entity, $asOf, $total, 0.9));

            // ----- Verification -----
            $m[] = array("cb_verified_$entity", '', $this->scalar($db,
                "SELECT COUNT(*) FROM `$entity` WHERE cbVerified = 1"));
            $m[] = array("brewer_verified_$entity", '', $this->scalar($db,
                "SELECT COUNT(*) FROM `$entity` WHERE brewerVerified = 1"));
        }

        // ----- Brewer-level catalog staleness -----
        // A brewery whose newest beer is two years old is stale data, not a
        // stable record — breweries ship new beer constantly.
        $m[] = array('brewer_stale_catalog_2yr', '', $this->scalar($db,
            "SELECT COUNT(*) FROM (SELECT brewerID FROM beer GROUP BY brewerID HAVING MAX(lastModified) <= ?) x",
            array($asOf - 730 * self::DAY)));

        // ----- Brewer engagement -----
        // How many distinct breweries have touched anything of their own.
        $m[] = array('brewers_engaged', '', $this->scalar($db,
            "SELECT COUNT(*) FROM (
                SELECT id AS brewerID FROM brewer WHERE brewerVerified = 1
                UNION SELECT brewerID FROM beer WHERE brewerVerified = 1
                UNION SELECT brewerID FROM location WHERE brewerVerified = 1
             ) x"));
        $m[] = array('privileges_total', '', $this->scalar($db, "SELECT COUNT(*) FROM privileges"));
        $m[] = array('privileges_users', '', $this->scalar($db, "SELECT COUNT(DISTINCT userID) FROM privileges"));
        $m[] = array('users_total', '', $this->scalar($db, "SELECT COUNT(*) FROM users"));
        $m[] = array('users_email_verified', '', $this->scalar($db, "SELECT COUNT(*) FROM users WHERE emailVerified = 1"));

        // ----- API demand -----
        // Snapshotting these nightly is what makes them permanent: the
        // underlying api_logging rows are pruned at 3 months, and master-key
        // requests are never logged at all, so these are floors.
        $since = $asOf - 30 * self::DAY;
        $m[] = array('api_get_30d', '', $this->scalar($db,
            "SELECT COUNT(*) FROM api_logging WHERE method = 'GET' AND timestamp > ?", array($since)));
        $m[] = array('api_write_30d', '', $this->scalar($db,
            "SELECT COUNT(*) FROM api_logging WHERE method IN ('POST','PUT','PATCH','DELETE') AND timestamp > ?", array($since)));
        $m[] = array('api_keys_active_30d', '', $this->scalar($db,
            "SELECT COUNT(DISTINCT apiKey) FROM api_logging WHERE timestamp > ?", array($since)));

        // ----- Completeness -----
        $m[] = array('brewer_with_url', '', $this->scalar($db,
            "SELECT COUNT(*) FROM brewer WHERE url IS NOT NULL AND url != ''"));
        $m[] = array('brewer_with_domain', '', $this->scalar($db,
            "SELECT COUNT(*) FROM brewer WHERE domainName IS NOT NULL AND domainName != ''"));
        $m[] = array('brewer_with_description', '', $this->scalar($db,
            "SELECT COUNT(*) FROM brewer WHERE description IS NOT NULL AND description != ''"));
        $m[] = array('brewer_with_short_description', '', $this->scalar($db,
            "SELECT COUNT(*) FROM brewer WHERE shortDescription IS NOT NULL AND shortDescription != ''"));
        $m[] = array('brewer_with_beer', '', $this->scalar($db,
            "SELECT COUNT(DISTINCT brewerID) FROM beer"));
        $m[] = array('brewer_with_location', '', $this->scalar($db,
            "SELECT COUNT(DISTINCT brewerID) FROM location"));

        $m[] = array('beer_with_description', '', $this->scalar($db,
            "SELECT COUNT(*) FROM beer WHERE description IS NOT NULL AND description != ''"));
        // IS NOT NULL, not ibu > 0. The 2020 import filled tens of thousands of
        // rows with a 0 sentinel, so this used to have to skip zero to stay
        // honest; migrations/2026-08-04-ibu-zero-to-null.sql converted those
        // sentinels to NULL, and 0 is now a storable IBU meaning "no measurable
        // bitterness". The two definitions agree on the migrated data, so the
        // history stays continuous across the change.
        $m[] = array('beer_with_ibu', '', $this->scalar($db, "SELECT COUNT(*) FROM beer WHERE ibu IS NOT NULL"));
        // Now a real count of no-bitterness beers rather than a backlog gauge.
        $m[] = array('beer_ibu_zero', '', $this->scalar($db, "SELECT COUNT(*) FROM beer WHERE ibu = 0"));
        // abv has no such distinction to make: the column is NOT NULL, so a 0
        // is either a genuine non-alcoholic beer or an unfilled placeholder and
        // nothing in the schema separates them. beer_abv_zero is the gauge for
        // that ambiguity — see the cleanup note in the same migration.
        $m[] = array('beer_with_abv', '', $this->scalar($db, "SELECT COUNT(*) FROM beer WHERE abv > 0"));
        $m[] = array('beer_abv_zero', '', $this->scalar($db, "SELECT COUNT(*) FROM beer WHERE abv = 0"));

        $m[] = array('location_with_name', '', $this->scalar($db,
            "SELECT COUNT(*) FROM location WHERE name IS NOT NULL AND name != ''"));
        $m[] = array('location_with_url', '', $this->scalar($db,
            "SELECT COUNT(*) FROM location WHERE url IS NOT NULL AND url != ''"));
        $m[] = array('location_with_latlng', '', $this->scalar($db,
            "SELECT COUNT(*) FROM location WHERE latitude IS NOT NULL AND longitude IS NOT NULL"));
        $m[] = array('location_with_address', '', $this->scalar($db, "SELECT COUNT(*) FROM US_addresses"));

        // ----- Style classification -----
        $m[] = array('beer_style_id_resolved', '', $this->scalar($db, "SELECT COUNT(*) FROM beer WHERE style_id IS NOT NULL"));
        $m[] = array('beer_parent_resolved', '', $this->scalar($db, "SELECT COUNT(*) FROM beer WHERE parent IS NOT NULL"));
        $m[] = array('beer_class_resolved', '', $this->scalar($db, "SELECT COUNT(*) FROM beer WHERE class IS NOT NULL"));

        foreach($this->grouped($db, "SELECT COALESCE(style_confidence, 'none') AS d, COUNT(*) FROM beer GROUP BY d") as $dim => $count){
            $m[] = array('beer_style_confidence', $dim, $count);
        }
        foreach($this->grouped($db, "SELECT beverage_type AS d, COUNT(*) FROM beer GROUP BY d") as $dim => $count){
            $m[] = array('beer_beverage_type', $dim, $count);
        }

        // ----- URL health -----
        // Optional: the check-urls monitoring columns may not be migrated yet
        // on every environment. Probe first — a failed query would abort the
        // whole snapshot.
        if($this->columnExists($db, 'brewer', 'urlStatus')){
            foreach($this->grouped($db, "SELECT urlStatus AS d, COUNT(*) FROM brewer GROUP BY d") as $dim => $count){
                $m[] = array('brewer_url_status', $dim, $count);
            }
        }

        return $m;
    }

    /**
     * Deletions, inferred by differencing against yesterday's snapshot:
     *   deleted = (yesterday's total + created since) - today's total
     *
     * Beer, brewer and location deletes are hard deletes with no tombstone, so
     * this is the only record we get. It undercounts a row created and deleted
     * inside the same window (it is absent from all three terms) and is only
     * emitted when yesterday's snapshot exists, since a gap in the series
     * would silently attribute several days of deletions to one.
     */
    private function churn($db, $date, $current){
        $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));

        $result = $db->query("SELECT metric, value FROM metrics_daily WHERE snapshotDate = ? AND dimension = ''", array($previousDate));
        if($db->error || $result === null){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            return array();
        }

        $previous = array();
        while($row = $result->fetch_assoc()){
            $previous[$row['metric']] = (int)$row['value'];
        }
        if(empty($previous)){
            return array();
        }

        // Index today's values for the arithmetic
        $today = array();
        foreach($current as $row){
            if($row[1] === ''){
                $today[$row[0]] = $row[2];
            }
        }

        $m = array();
        foreach(self::ENTITIES as $entity){
            if(!isset($previous["total_$entity"])){
                continue;
            }
            $deleted = $previous["total_$entity"] + $today["created_{$entity}_1d"] - $today["total_$entity"];
            // Clamps a negative, which means rows arrived without going
            // through add() — a direct SQL insert or a restored backup.
            $m[] = array("deleted_{$entity}_1d", '', max(0, $deleted));
        }

        return $m;
    }

    /**
     * Age in days of the record at the given percentile of lastModified.
     * Ordered newest-first, so a higher percentile is an older record.
     */
    private function ageAtPercentile($db, $table, $asOf, $total, $percentile){
        if($this->error || $total < 1){
            return 0;
        }

        $offset = (int)floor(($total - 1) * $percentile);
        $result = $db->query("SELECT lastModified FROM `$table` ORDER BY lastModified DESC LIMIT 1 OFFSET ?", array($offset));
        if($db->error || $result === null){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            return 0;
        }

        $row = $result->fetch_row();
        if($row === null){
            return 0;
        }

        return (int)floor(($asOf - (int)$row[0]) / self::DAY);
    }

    /**
     * Single integer result. Returns 0 and latches $error on failure — the
     * connection is unusable after the first error anyway, so every later
     * call short-circuits and snapshot() abandons the run before writing.
     */
    private function scalar($db, $sql, $params = array()){
        if($this->error){
            return 0;
        }

        $result = $db->query($sql, $params);
        if($db->error || $result === null){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            return 0;
        }

        $row = $result->fetch_row();
        return $row === null ? 0 : (int)$row[0];
    }

    /**
     * Two-column "bucket, count" result as a dimension => value map.
     */
    private function grouped($db, $sql, $params = array()){
        if($this->error){
            return array();
        }

        $result = $db->query($sql, $params);
        if($db->error || $result === null){
            $this->error = true;
            $this->errorMsg = $db->errorMsg;
            return array();
        }

        $buckets = array();
        while($row = $result->fetch_row()){
            $buckets[(string)$row[0]] = (int)$row[1];
        }
        return $buckets;
    }

    private function tableExists($db, $table){
        $result = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", array($table));
        if($db->error || $result === null){
            return false;
        }
        $row = $result->fetch_row();
        return $row !== null && (int)$row[0] > 0;
    }

    private function columnExists($db, $table, $column){
        $result = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", array($table, $column));
        if($db->error || $result === null){
            return false;
        }
        $row = $result->fetch_row();
        return $row !== null && (int)$row[0] > 0;
    }

    /**
     * Upsert one day's [metric, dimension, value] triples. Re-running on the
     * same day overwrites in place, so the cron is safe to retry by hand.
     */
    private function write($db, $date, $rows){
        $dated = array();
        foreach($rows as $row){
            $dated[] = array($date, $row[0], $row[1], $row[2]);
        }
        $this->writeRows($db, $dated);
    }

    /**
     * Upsert [date, metric, dimension, value] quads, batched across days.
     *
     * $ignore leaves any existing row untouched, which is what the historical
     * backfill wants: its totals count only records that still exist today, so
     * they run slightly low for any day where something was later deleted, and
     * must never overwrite a snapshot taken live on the day itself.
     */
    public function writeRows($db, $rows, $ignore = false){
        $verb = $ignore ? 'INSERT IGNORE INTO' : 'INSERT INTO';
        $tail = $ignore ? '' : ' ON DUPLICATE KEY UPDATE value=VALUES(value)';

        foreach(array_chunk($rows, 200) as $chunk){
            $placeholders = array();
            $params = array();
            foreach($chunk as $row){
                $placeholders[] = '(?, ?, ?, ?)';
                $params[] = $row[0];
                $params[] = $row[1];
                $params[] = $row[2];
                $params[] = (int)$row[3];
            }

            $db->query("$verb metrics_daily (snapshotDate, metric, dimension, value) VALUES " . implode(', ', $placeholders) . $tail, $params);
            if($db->error){
                $this->error = true;
                $this->errorMsg = $db->errorMsg;
                return;
            }

            $this->rowsWritten += count($chunk);
        }
    }
}
?>
