#!/usr/local/cwp/php71/bin/php
<?php
/**
 * rcloneCWP Retention Cleanup Cron Runner
 *
 * Enforces retention policies across all backup jobs, purging expired
 * database history records and cloud storage snapshots.
 * Recommended crontab frequency: daily (e.g. 2:00 AM):
 *   0 2 * * * root /usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cron/rclone_cleanup.php >> /var/log/rcloneCWP_cleanup.log 2>&1
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Cron
 */

// Strict CLI only guard
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: This script must be run from the command line interface.\n");
    exit(1);
}

// Locate and load bootstrap
$bootstrapPaths = [
    __DIR__ . '/../bootstrap.php',
    '/usr/local/cwp/rcloneCWP/bootstrap.php',
];

$loaded = false;
foreach ($bootstrapPaths as $p) {
    if (is_file($p)) {
        require_once $p;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Error: Unable to locate rcloneCWP bootstrap.php\n");
    exit(1);
}

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Scheduling\RetentionManager;

$lockFile = sys_get_temp_dir() . '/rclonecwp_cleanup_runner.lock';
$lockHandle = @fopen($lockFile, 'c+');

if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] rcloneCWP retention cleanup already running. Skipping.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting rcloneCWP retention cleanup...\n";

try {
    $db = Database::getInstance();
    $logger = new Logger(RCLONE_LOG_DIR, $db);
    $dm = new DestinationManager($db, null, $logger);
    $rm = new RetentionManager($db, $dm, $logger);

    $summary = $rm->pruneAllJobs();

    echo "[" . date('Y-m-d H:i:s') . "] Retention cleanup completed.\n";
    echo "  Jobs evaluated:      " . ($summary['jobs_checked'] ?? 0) . "\n";
    echo "  Old backups pruned:  " . ($summary['total_pruned'] ?? 0) . "\n";
    echo "  Total bytes freed:   " . ($summary['total_bytes_freed'] ?? 0) . "\n";
} catch (\Exception $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Error during retention cleanup: " . $e->getMessage() . "\n");
} finally {
    if ($lockHandle) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
