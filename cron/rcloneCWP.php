#!/usr/local/cwp/php71/bin/php
<?php
/**
 * rcloneCWP Main Cron Runner
 *
 * Checks for due scheduled backup jobs and executes them.
 * Recommended crontab frequency: every 5 minutes:
 *   * /5 * * * * root /usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php >> /var/log/rcloneCWP_cron.log 2>&1
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

use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Scheduling\ScheduleManager;

$lockFile = sys_get_temp_dir() . '/rclonecwp_cron_runner.lock';
$lockHandle = @fopen($lockFile, 'c+');

if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another runner instance is actively running; skip this iteration
    echo "[" . date('Y-m-d H:i:s') . "] rcloneCWP cron runner already in progress. Skipping.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting rcloneCWP scheduled backup check...\n";

try {
    $db = Database::getInstance();
    $logger = new Logger(RCLONE_LOG_DIR, $db);
    $dm = new DestinationManager($db, null, $logger);
    $bjm = new BackupJobManager($db, $dm, $logger);
    $sm = new ScheduleManager($db);

    $dueSchedules = $sm->getDueSchedules();

    if (empty($dueSchedules)) {
        echo "[" . date('Y-m-d H:i:s') . "] No backup schedules due at this time.\n";
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(0);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Found " . count($dueSchedules) . " due schedule(s) to execute.\n";

    foreach ($dueSchedules as $sched) {
        $schedId = (int) $sched['id'];
        $jobId   = (int) $sched['job_id'];

        echo "------------------------------------------------------------\n";
        echo "[" . date('Y-m-d H:i:s') . "] Executing Schedule #{$schedId} (Job #{$jobId})...\n";

        // Mark schedule running
        $sm->recordRun($schedId, 'running');

        $runResult = $bjm->runJobNow($jobId);

        if (!empty($runResult['ok'])) {
            echo "[" . date('Y-m-d H:i:s') . "] Schedule #{$schedId} completed successfully!\n";
            echo "  Accounts backed up: " . count($runResult['results'] ?? []) . "\n";
            echo "  Bytes transferred:  " . ($runResult['bytes_transferred'] ?? 0) . "\n";
            $sm->recordRun($schedId, 'completed');
        } else {
            $err = $runResult['error'] ?? 'Unknown error';
            echo "[" . date('Y-m-d H:i:s') . "] Schedule #{$schedId} failed: {$err}\n";
            $sm->recordRun($schedId, 'failed');
        }
    }

    echo "------------------------------------------------------------\n";
    echo "[" . date('Y-m-d H:i:s') . "] Scheduled backup runner finished.\n";
} catch (\Exception $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Fatal error in cron runner: " . $e->getMessage() . "\n");
} finally {
    if ($lockHandle) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
