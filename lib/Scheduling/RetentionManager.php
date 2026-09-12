<?php
/**
 * rcloneCWP Retention Lifecycle Manager
 *
 * Enforces retention policies (days, count, and GFS rotation) on backup snapshots.
 * Performs dual cleanup: database records (rclone_backups) and remote cloud
 * storage directories (via rclone purge).
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Scheduling
 */

namespace CWP\RcloneCWP\Scheduling;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Rclone;

class RetentionManager
{
    /** @var Database */
    private $db;

    /** @var DestinationManager */
    private $dm;

    /** @var Logger */
    private $logger;

    public function __construct(
        Database $db = null,
        DestinationManager $dm = null,
        Logger $logger = null
    ) {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
        $this->dm = $dm ?: new DestinationManager($this->db, null, $this->logger);
    }

    /**
     * Prune expired backups for all configured backup jobs.
     *
     * @param array $options ['dry_run' => bool]
     * @return array Summary of pruned backups across all jobs
     */
    public function pruneAllJobs(array $options = []): array
    {
        $jobs = $this->db->fetchAll('SELECT id, name, retention_days FROM rclone_jobs');
        $totalPruned = 0;
        $totalBytesFreed = 0;
        $jobSummaries = [];

        foreach ($jobs as $job) {
            $res = $this->pruneJobBackups((int) $job['id'], $options);
            $totalPruned += count($res['pruned'] ?? []);
            $totalBytesFreed += (int) ($res['bytes_freed'] ?? 0);
            $jobSummaries[$job['id']] = $res;
        }

        $this->logger->info(
            "Retention prune completed for " . count($jobs) . " jobs. Total pruned: {$totalPruned}, bytes freed: {$totalBytesFreed}"
        );

        return [
            'ok'               => true,
            'jobs_checked'     => count($jobs),
            'total_pruned'     => $totalPruned,
            'total_bytes_freed'=> $totalBytesFreed,
            'details'          => $jobSummaries,
        ];
    }

    /**
     * Prune expired backups for a specific job.
     *
     * @param int $jobId
     * @param array $options ['policy' => 'days'|'count'|'gfs', 'retention_days' => int, 'keep_count' => int, 'dry_run' => bool]
     * @return array ['ok' => bool, 'pruned' => array, 'kept' => array, 'bytes_freed' => int, 'errors' => array]
     */
    public function pruneJobBackups(int $jobId, array $options = []): array
    {
        $job = $this->db->fetch(
            'SELECT j.*, d.type AS dest_type, d.config AS dest_config FROM rclone_jobs j ' .
            'LEFT JOIN rclone_destinations d ON j.destination_id = d.id WHERE j.id = ?',
            [$jobId]
        );

        if (!$job) {
            return ['ok' => false, 'error' => 'Job not found.', 'pruned' => [], 'kept' => [], 'bytes_freed' => 0];
        }

        $retentionDays = isset($options['retention_days'])
            ? max(1, (int) $options['retention_days'])
            : max(1, (int) ($job['retention_days'] ?? 7));

        $policy = $options['policy'] ?? 'days';
        $keepCount = isset($options['keep_count']) ? max(1, (int) $options['keep_count']) : 0;
        $dryRun = !empty($options['dry_run']);

        // Fetch completed backup runs for this job
        $backups = $this->db->fetchAll(
            "SELECT * FROM rclone_backups WHERE job_id = ? AND status = 'completed' ORDER BY started_at DESC",
            [$jobId]
        );

        if (empty($backups)) {
            return ['ok' => true, 'pruned' => [], 'kept' => [], 'bytes_freed' => 0];
        }

        $evaluation = $this->evaluateRetention($backups, $retentionDays, $policy, $keepCount);
        $toPrune = $evaluation['prune'];
        $kept = $evaluation['keep'];

        if ($dryRun || empty($toPrune)) {
            return [
                'ok'          => true,
                'dry_run'     => $dryRun,
                'pruned'      => $toPrune,
                'kept'        => $kept,
                'bytes_freed' => array_sum(array_column($toPrune, 'bytes_transferred')),
                'errors'      => [],
            ];
        }

        $destId = (int) $job['destination_id'];
        $dest = $this->dm->getDestination($destId, true);
        $errors = [];
        $prunedSuccess = [];
        $bytesFreed = 0;

        foreach ($toPrune as $backup) {
            $backupId = (int) $backup['id'];
            $snapshotPath = $backup['config_id'] ?? '';

            // 1. Physically purge from remote destination if path is present
            if (!empty($snapshotPath) && $dest && !empty($dest['enabled'])) {
                $purgeOk = $this->purgeRemoteSnapshot($dest, $snapshotPath);
                if (!$purgeOk['ok']) {
                    $errors[] = "Failed to purge remote snapshot #{$backupId} ({$snapshotPath}): " . ($purgeOk['error'] ?? 'Unknown');
                }
            }

            // 2. Remove record from database
            try {
                $this->db->delete('rclone_backups', 'id = ?', [$backupId]);
                $prunedSuccess[] = $backup;
                $bytesFreed += (int) ($backup['bytes_transferred'] ?? 0);
            } catch (\Exception $e) {
                $errors[] = "Failed to delete DB record for backup #{$backupId}: " . $e->getMessage();
            }
        }

        $this->logger->info(
            "Job #{$jobId} ('{$job['name']}'): retention pruned " . count($prunedSuccess) . " backups, kept " . count($kept)
        );

        return [
            'ok'          => empty($errors),
            'pruned'      => $prunedSuccess,
            'kept'        => $kept,
            'bytes_freed' => $bytesFreed,
            'errors'      => $errors,
        ];
    }

    /**
     * Evaluate a list of backups against a retention policy.
     * Pure function for easy testing and predictable behavior.
     *
     * @param array $backups Sorted descending by started_at
     * @param int $retentionDays
     * @param string $policy 'days' | 'count' | 'gfs'
     * @param int $keepCount Only used for 'count' policy
     * @return array ['keep' => array, 'prune' => array]
     */
    public function evaluateRetention(
        array $backups,
        int $retentionDays = 7,
        string $policy = 'days',
        int $keepCount = 0
    ): array {
        if (empty($backups)) {
            return ['keep' => [], 'prune' => []];
        }

        // Ensure backups are sorted descending by started_at
        usort($backups, function ($a, $b) {
            return strtotime($b['started_at'] ?? '0') <=> strtotime($a['started_at'] ?? '0');
        });

        // Always keep at least the most recent backup, regardless of age!
        $mostRecent = $backups[0];
        $remaining = array_slice($backups, 1);

        $keep = [$mostRecent];
        $prune = [];

        switch ($policy) {
            case 'count':
                $targetCount = max(1, $keepCount ?: $retentionDays);
                foreach ($remaining as $b) {
                    if (count($keep) < $targetCount) {
                        $keep[] = $b;
                    } else {
                        $prune[] = $b;
                    }
                }
                break;

            case 'gfs':
                // Grandfather-Father-Son:
                // Keep daily backups for 7 days
                // Keep weekly backups (Sundays) for 4 weeks (28 days)
                // Keep monthly backups (1st of month) for 12 months (365 days)
                $now = time();
                $seenDays = [];
                $seenWeeks = [];
                $seenMonths = [];

                foreach ($backups as $b) {
                    $ts = strtotime($b['started_at']);
                    $ageDays = ($now - $ts) / 86400;

                    $dayKey = date('Y-m-d', $ts);
                    $weekKey = date('o-W', $ts); // ISO-8601 year-week
                    $monthKey = date('Y-m', $ts);

                    $shouldKeep = false;

                    // Daily tier: last 7 days (1 per day)
                    if ($ageDays <= 7) {
                        if (!isset($seenDays[$dayKey])) {
                            $shouldKeep = true;
                            $seenDays[$dayKey] = true;
                        }
                    }
                    // Weekly tier: last 28 days (1 per week, preferably Sunday)
                    elseif ($ageDays <= 28) {
                        if (!isset($seenWeeks[$weekKey])) {
                            $shouldKeep = true;
                            $seenWeeks[$weekKey] = true;
                        }
                    }
                    // Monthly tier: last 365 days (1 per month)
                    elseif ($ageDays <= 365) {
                        if (!isset($seenMonths[$monthKey])) {
                            $shouldKeep = true;
                            $seenMonths[$monthKey] = true;
                        }
                    }

                    if ($shouldKeep) {
                        if ($b['id'] !== $mostRecent['id']) {
                            $keep[] = $b;
                        }
                    } else {
                        if ($b['id'] !== $mostRecent['id']) {
                            $prune[] = $b;
                        }
                    }
                }
                break;

            case 'days':
            default:
                $cutoff = time() - ($retentionDays * 86400);
                foreach ($remaining as $b) {
                    $bTime = strtotime($b['started_at']);
                    if ($bTime >= $cutoff) {
                        $keep[] = $b;
                    } else {
                        $prune[] = $b;
                    }
                }
                break;
        }

        return ['keep' => $keep, 'prune' => $prune];
    }

    /**
     * Safely purge a remote snapshot directory using rclone.
     *
     * @param array $dest Destination record with decrypted config
     * @param string $snapshotPath e.g. "rcloneCWP-backups/daily_backup/2026-09-12_120000_user_incremental"
     * @return array ['ok' => bool, 'error' => string|null]
     */
    private function purgeRemoteSnapshot(array $dest, string $snapshotPath): array
    {
        // Sanity check: prevent purging root or arbitrary paths
        if (empty($snapshotPath)
            || strpos($snapshotPath, 'rcloneCWP-backups/') !== 0
            || strpos($snapshotPath, '..') !== false
            || $snapshotPath[0] === '-'
        ) {
            return ['ok' => false, 'error' => 'Invalid snapshot path: ' . $snapshotPath];
        }

        $type = $dest['type'] ?? '';
        $provider = $this->dm->getProvider($type);
        $config = $dest['config'] ?? [];
        $remoteName = 'purge_' . $dest['id'] . '_' . substr(md5(uniqid('', true)), 0, 8);
        $env = $provider->getRcloneEnv($config, $remoteName);

        $remoteTarget = $provider->getRemoteTarget($config, $remoteName, $snapshotPath);
        $safeTarget = trim($remoteTarget);
        if ($safeTarget === '' || $safeTarget[0] === '-' || preg_match('/[\x00-\x1f\x7f`$;|&><\s]/', $safeTarget)) {
            return ['ok' => false, 'error' => 'Invalid remote purge target.'];
        }

        $this->logger->info("Purging remote snapshot directory: {$safeTarget}");

        $res = Rclone::execute('purge', [$safeTarget], [], [], 300, $env);
        if ($res['exit'] !== 0) {
            return ['ok' => false, 'error' => trim($res['stderr'] ?: $res['stdout'])];
        }

        return ['ok' => true, 'error' => null];
    }
}
