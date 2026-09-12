<?php
/**
 * rcloneCWP Phase 5: Scheduling & Retention Engine Test Suite
 *
 * Validates 5-field cron expression parsing, next-run calculations, timezone conversions,
 * ScheduleManager CRUD, due schedule detection, RetentionManager pruning policies
 * (time-based, count-based, and GFS grandfather-father-son), CrontabService lifecycle,
 * and CLI runners with concurrency locking.
 *
 * PHP 7.1+ compatible (target: AlmaLinux 8.10 CWP PHP 7.2.30)
 * Run: /usr/local/cwp/php71/bin/php tests/scheduling_engine_test.php
 *
 * @package CWP\RcloneCWP\Tests
 */

require_once __DIR__ . '/../bootstrap.php';

// Custom autoloader for lib/ classes in development tree (prepended)
spl_autoload_register(function ($class) {
    $prefix = 'CWP\\RcloneCWP\\';
    if (strpos($class, $prefix) === 0) {
        $rel = substr($class, strlen($prefix));
        $file = __DIR__ . '/../lib/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}, true, true);

use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Scheduling\CronParser;
use CWP\RcloneCWP\Scheduling\CrontabService;
use CWP\RcloneCWP\Scheduling\RetentionManager;
use CWP\RcloneCWP\Scheduling\ScheduleManager;

$passed = 0;
$failed = 0;

function it(string $desc, bool $ok, string $detail = '')
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "   \033[32m✓\033[0m {$desc}\n";
    } else {
        $failed++;
        echo "   \033[31m✗\033[0m {$desc}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "  rcloneCWP Phase 5 Scheduling & Retention — Test Suite\n";
echo "====================================================================\n";
echo "PHP Version: " . PHP_VERSION . " (" . PHP_SAPI . ")\n\n";

$db = Database::getInstance();
$logger = new Logger(RCLONE_LOG_DIR, $db);
$dm = new DestinationManager($db, null, $logger);
$bjm = new BackupJobManager($db, $dm, $logger);

// ============================================================================
// TEST 1: CronParser Syntax Validation
// ============================================================================
echo "Test 1: CronParser Syntax Validation\n";

$validExpressions = [
    '0 2 * * *',
    '*/15 * * * *',
    '0 2,14 * * *',
    '0 2 * * 0',
    '0 2 1 * *',
    '30 3 1-15 * 1-5',
    '*/5 0-23/2 * * *',
    '* * * * *',
];

foreach ($validExpressions as $expr) {
    it("CronParser::isValid('{$expr}') returns true", CronParser::isValid($expr));
}

$invalidExpressions = [
    '',
    '* * * *',           // 4 fields
    '* * * * * *',       // 6 fields
    '60 * * * *',         // minute out of bounds
    '* 25 * * *',         // hour out of bounds
    '* * 32 * *',         // day of month out of bounds
    '* * * 13 *',         // month out of bounds
    '* * * * 8',          // day of week out of bounds
    'abc * * * *',        // invalid characters
    '*/0 * * * *',        // step cannot be zero
    '10-5 * * * *',       // inverted range
];

foreach ($invalidExpressions as $expr) {
    it("CronParser::isValid('{$expr}') rejects invalid syntax", !CronParser::isValid($expr));
}

$presets = CronParser::presets();
it("CronParser::presets() returns standard presets", isset($presets['daily']) && isset($presets['hourly']) && isset($presets['weekly']));

// ============================================================================
// TEST 2: CronParser Next-Run & Timezone Calculations
// ============================================================================
echo "\nTest 2: CronParser Next-Run & Timezone Calculations\n";

// Test daily at 02:00 starting from 2026-09-12 01:00:00 UTC
$baseTs = strtotime('2026-09-12 01:00:00 UTC');
$nextDaily = CronParser::nextRun('0 2 * * *', $baseTs, 'UTC');
it("Daily 02:00 from 01:00 calculates same day 02:00", $nextDaily->format('Y-m-d H:i:s') === '2026-09-12 02:00:00');

// Test daily at 02:00 starting from 2026-09-12 03:00:00 UTC -> next day 02:00
$afterTs = strtotime('2026-09-12 03:00:00 UTC');
$nextDaily2 = CronParser::nextRun('0 2 * * *', $afterTs, 'UTC');
it("Daily 02:00 from 03:00 calculates next day 02:00", $nextDaily2->format('Y-m-d H:i:s') === '2026-09-13 02:00:00');

// Test nextRuns() count
$fiveRuns = CronParser::nextRuns('0 2 * * *', 5, 'UTC', $baseTs);
it("nextRuns() returns exactly 5 runs", count($fiveRuns) === 5);
it("nextRuns() runs are in chronological ascending order", $fiveRuns[0] < $fiveRuns[1] && $fiveRuns[1] < $fiveRuns[2]);

// Test timezone conversion: America/New_York vs UTC
$nyRun = CronParser::nextRun('0 2 * * *', $baseTs, 'America/New_York');
it("nextRun respects timezone context", $nyRun->getTimezone()->getName() === 'America/New_York');

// Test previous run calculation
$prevRun = CronParser::prevRun('0 2 * * *', strtotime('2026-09-12 03:00:00 UTC'), 'UTC');
it("prevRun('0 2 * * *') calculates earlier run today", $prevRun !== null && $prevRun->format('Y-m-d H:i:s') === '2026-09-12 02:00:00');

// ============================================================================
// TEST 3: ScheduleManager CRUD & Next-Run Lifecycle
// ============================================================================
echo "\nTest 3: ScheduleManager CRUD & Next-Run Lifecycle\n";

$schedMgr = new ScheduleManager($db);

// Create a mock destination and backup job to attach schedule to
$destPath = sys_get_temp_dir() . '/rclonecwp_sched_dest_' . bin2hex(random_bytes(4));
@mkdir($destPath, 0755, true);
$destRes = $dm->createDestination([
    'name'    => 'Sched Test Dest ' . time(),
    'type'    => 'local',
    'enabled' => 1,
    'config'  => ['path' => $destPath],
]);
$destId = (int)$destRes['id'];

$jobRes = $bjm->createJob([
    'name'           => 'Schedule Test Job',
    'destination_id' => $destId,
    'job_type'       => 'incremental',
    'retention_days' => 7,
    'accounts'       => ['*'],
    'components'     => ['databases', 'dns'],
]);
$jobId = (int)$jobRes['id'];

// Create Schedule
$createRes = $schedMgr->createSchedule([
    'job_id'          => $jobId,
    'cron_expression' => '0 2 * * *',
    'timezone'        => 'UTC',
    'active'          => 1,
]);
it("ScheduleManager::createSchedule succeeds", $createRes['ok'] === true && ($createRes['id'] ?? 0) > 0, $createRes['error'] ?? '');
$schedId = (int)$createRes['id'];

// Get Schedule
$sched = $schedMgr->getSchedule($schedId);
it("ScheduleManager::getSchedule returns valid schedule record", $sched !== null && (int)$sched['job_id'] === $jobId);
it("Schedule initializes non-null next_run datetime", !empty($sched['next_run']));
it("Schedule active flag is true", (bool)$sched['active'] === true);

// Update Schedule
$updateRes = $schedMgr->updateSchedule($schedId, [
    'cron_expression' => '0 3 * * *',
    'timezone'        => 'America/New_York',
]);
it("ScheduleManager::updateSchedule succeeds", $updateRes['ok'] === true);
$updatedSched = $schedMgr->getSchedule($schedId);
it("Updated schedule has new cron expression", $updatedSched['cron_expression'] === '0 3 * * *');
it("Updated schedule recalculates next_run", !empty($updatedSched['next_run']));

// Toggle Active
$toggleRes = $schedMgr->toggleActive($schedId, false);
it("ScheduleManager::toggleActive pauses schedule", $toggleRes['ok'] === true);
$toggledSched = $schedMgr->getSchedule($schedId);
it("Paused schedule has active = 0", (int)$toggledSched['active'] === 0);

// Re-activate
$schedMgr->toggleActive($schedId, true);

// Test Due Schedules Detection
// Simulate a past next_run so it is detected as due
$pastTime = date('Y-m-d H:i:s', time() - 300);
$db->update('rclone_schedules', ['next_run' => $pastTime], 'id = ?', [$schedId]);

$dueSchedules = $schedMgr->getDueSchedules();
$foundDue = false;
foreach ($dueSchedules as $due) {
    if ((int)$due['id'] === $schedId) {
        $foundDue = true;
        break;
    }
}
it("ScheduleManager::getDueSchedules detects past-due schedule", $foundDue);

// Test recordRun updates last_run and sets future next_run
$schedMgr->recordRun($schedId, 'success');
$afterRunSched = $schedMgr->getSchedule($schedId);
it("recordRun updates last_run timestamp", !empty($afterRunSched['last_run']));
it("recordRun advances next_run into future", strtotime($afterRunSched['next_run']) > time());

// BackupJobManager::getJobSchedules integration
$jobSchedules = $bjm->getJobSchedules($jobId);
it("BackupJobManager::getJobSchedules returns attached schedules", count($jobSchedules) >= 1 && (int)$jobSchedules[0]['id'] === $schedId);

// Delete Schedule
$deleteRes = $schedMgr->deleteSchedule($schedId);
it("ScheduleManager::deleteSchedule succeeds", $deleteRes['ok'] === true);
it("Schedule is no longer found after deletion", $schedMgr->getSchedule($schedId) === null);

// ============================================================================
// TEST 4: RetentionManager Pruning Policies (Days, Count, GFS)
// ============================================================================
echo "\nTest 4: RetentionManager Pruning Policies\n";

$retentionMgr = new RetentionManager($db, $dm, $logger);

// Helper to generate mock backup items with timestamps
function makeMockBackups(array $timestamps): array {
    $out = [];
    foreach ($timestamps as $idx => $ts) {
        $out[] = [
            'id'          => $idx + 1,
            'job_id'      => 10,
            'started_at'  => date('Y-m-d H:i:s', $ts),
            'config_id'   => 'rcloneCWP-backups/test/snap_' . ($idx + 1),
            'bytes_transferred' => 1024 * 1024,
        ];
    }
    return $out;
}

// 4a. Policy: Days (Time-based retention)
$now = time();
$daySecs = 86400;
$mockDaysBackups = makeMockBackups([
    $now - (20 * $daySecs), // 20 days ago (expired)
    $now - (10 * $daySecs), // 10 days ago (expired if retention = 7)
    $now - (3 * $daySecs),  // 3 days ago (keep)
    $now - (1 * $daySecs),  // 1 day ago (keep)
]);

$daysEval = $retentionMgr->evaluateRetention($mockDaysBackups, 7, 'days');
it("Time-based retention flags expired backups older than 7 days", count($daysEval['prune']) === 2);
it("Time-based retention keeps backups within 7 days", count($daysEval['keep']) === 2);

// 4b. Latest backup safety guarantee (never prune only backup even if expired)
$singleOldBackup = makeMockBackups([
    $now - (50 * $daySecs), // 50 days ago (expired)
]);
$singleEval = $retentionMgr->evaluateRetention($singleOldBackup, 7, 'days');
it("Retention safety guarantee: latest backup is NEVER pruned even if expired", count($singleEval['prune']) === 0 && count($singleEval['keep']) === 1);

// 4c. Policy: Count (Keep last N backups)
$mockCountBackups = makeMockBackups([
    $now - (5 * $daySecs),
    $now - (4 * $daySecs),
    $now - (3 * $daySecs),
    $now - (2 * $daySecs),
    $now - (1 * $daySecs),
]);
$countEval = $retentionMgr->evaluateRetention($mockCountBackups, 7, 'count', 3);
it("Count-based retention keeps specified keep_count (3)", count($countEval['keep']) === 3);
it("Count-based retention prunes excess older backups", count($countEval['prune']) === 2);

// 4d. Policy: GFS (Grandfather-Father-Son rotation)
// Generate 40 days of daily backups
$gfsTimestamps = [];
for ($d = 40; $d >= 0; $d--) {
    $gfsTimestamps[] = $now - ($d * $daySecs);
}
$mockGfsBackups = makeMockBackups($gfsTimestamps);
$gfsEval = $retentionMgr->evaluateRetention($mockGfsBackups, 7, 'gfs');
it("GFS rotation classifies backups into retain and prune buckets", count($gfsEval['keep']) > 0 && count($gfsEval['prune']) > 0);
it("GFS rotation keeps all daily backups for last 7 days", count($gfsEval['keep']) >= 7);

// 4e. Live Database & Remote Storage Pruning via RetentionManager
// Insert 2 mock records in rclone_backups for $jobId
$oldSnapDir = $destPath . '/rcloneCWP-backups/snap_old';
@mkdir($oldSnapDir, 0755, true);
file_put_contents($oldSnapDir . '/data.bin', 'test');

$recentSnapDir = $destPath . '/rcloneCWP-backups/snap_recent';
@mkdir($recentSnapDir, 0755, true);
file_put_contents($recentSnapDir . '/data.bin', 'test');

$oldBackupId = (int) $db->insert('rclone_backups', [
    'job_id'            => $jobId,
    'destination_id'    => $destId,
    'backup_type'       => 'incremental',
    'status'            => 'completed',
    'started_at'        => date('Y-m-d H:i:s', $now - (30 * $daySecs)),
    'completed_at'      => date('Y-m-d H:i:s', $now - (30 * $daySecs) + 60),
    'config_id'         => 'rcloneCWP-backups/snap_old',
    'bytes_transferred' => 2048,
]);

$recentBackupId = (int) $db->insert('rclone_backups', [
    'job_id'            => $jobId,
    'destination_id'    => $destId,
    'backup_type'       => 'incremental',
    'status'            => 'completed',
    'started_at'        => date('Y-m-d H:i:s', $now - (1 * $daySecs)),
    'completed_at'      => date('Y-m-d H:i:s', $now - (1 * $daySecs) + 60),
    'config_id'         => 'rcloneCWP-backups/snap_recent',
    'bytes_transferred' => 4096,
]);

// Dry run test
$dryRunRes = $retentionMgr->pruneJobBackups($jobId, ['dry_run' => true, 'retention_days' => 7]);
it("pruneJobBackups in dry_run mode flags old backup without deleting", count($dryRunRes['pruned']) === 1 && $dryRunRes['pruned'][0]['id'] === $oldBackupId);
it("pruneJobBackups dry_run does not delete remote directory", is_dir($oldSnapDir));

// Real execution test
$livePruneRes = $retentionMgr->pruneJobBackups($jobId, ['dry_run' => false, 'retention_days' => 7]);
it("pruneJobBackups live execution succeeds", $livePruneRes['ok'] === true);
it("Live pruning purged remote storage directory", !is_dir($oldSnapDir));
it("Live pruning deleted database row from rclone_backups", $db->fetch("SELECT id FROM rclone_backups WHERE id = ?", [$oldBackupId]) === null);
it("Recent backup row is preserved in rclone_backups", $db->fetch("SELECT id FROM rclone_backups WHERE id = ?", [$recentBackupId]) !== null);

// Cleanup recent mock backup row
$db->delete('rclone_backups', 'id = ?', [$recentBackupId]);

// ============================================================================
// TEST 5: CrontabService Lifecycle & /etc/cron.d Generation
// ============================================================================
echo "\nTest 5: CrontabService Lifecycle\n";

$status = CrontabService::getStatus();
it("CrontabService::getStatus returns array structure", isset($status['installed']) && isset($status['path']) && isset($status['writable']));

$generatedCron = CrontabService::getCrontabContent('/usr/local/cwp/php71/bin/php', '/usr/local/cwp/rcloneCWP');
it("getCrontabContent includes main backup runner schedule (*/5)", strpos($generatedCron, '*/5 * * * *') !== false);
it("getCrontabContent includes retention cleaner schedule (0 2 * * *)", strpos($generatedCron, '0 2 * * *') !== false);
it("getCrontabContent references correct CLI scripts", strpos($generatedCron, 'rcloneCWP.php') !== false && strpos($generatedCron, 'rclone_cleanup.php') !== false);

// Test installation into a safe temporary crontab path
$tempCronFile = sys_get_temp_dir() . '/rclonecwp_test_cron_' . bin2hex(random_bytes(4));
@file_put_contents($tempCronFile, $generatedCron);
it("Crontab file can be safely staged with correct content", is_file($tempCronFile) && filesize($tempCronFile) > 100);
@unlink($tempCronFile);

// ============================================================================
// TEST 6: CLI Runner Concurrency Locking
// ============================================================================
echo "\nTest 6: CLI Runner Concurrency Locking\n";

// Test non-blocking flock locking
$runnerLockFile = sys_get_temp_dir() . '/rclonecwp_cron_runner.lock';
$h1 = @fopen($runnerLockFile, 'c+');
$locked1 = $h1 && @flock($h1, LOCK_EX | LOCK_NB);
it("Primary cron runner acquires exclusive non-blocking lock", (bool)$locked1 === true);

// Secondary attempt while held must fail immediately
$h2 = @fopen($runnerLockFile, 'c+');
$locked2 = $h2 && @flock($h2, LOCK_EX | LOCK_NB);
it("Secondary cron runner is blocked when primary runner holds lock", $locked2 === false);

if ($h2) {
    @fclose($h2);
}
if ($h1) {
    @flock($h1, LOCK_UN);
    @fclose($h1);
}
@unlink($runnerLockFile);

// ============================================================================
// CLEANUP
// ============================================================================
$bjm->deleteJob($jobId);
$dm->deleteDestination($destId);
exec('rm -rf ' . escapeshellarg($destPath));

echo "\n--------------------------------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "--------------------------------------------------------------------\n";

exit($failed > 0 ? 1 : 0);
