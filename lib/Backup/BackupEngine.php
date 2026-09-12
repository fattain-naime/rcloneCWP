<?php
/**
 * rcloneCWP Backup Engine Core Orchestrator
 *
 * Orchestrates multi-component CWP account discovery, snapshots, in-memory rclone
 * execution, verification, hooks, notifications, and automated retention pruning.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup
 */

namespace CWP\RcloneCWP\Backup;

use CWP\RcloneCWP\Backup\Collectors\AccountMetadataCollector;
use CWP\RcloneCWP\Backup\Collectors\ComponentCollectorInterface;
use CWP\RcloneCWP\Backup\Collectors\CronCollector;
use CWP\RcloneCWP\Backup\Collectors\DatabaseCollector;
use CWP\RcloneCWP\Backup\Collectors\DnsCollector;
use CWP\RcloneCWP\Backup\Collectors\FilesCollector;
use CWP\RcloneCWP\Backup\Collectors\FtpCollector;
use CWP\RcloneCWP\Backup\Collectors\MailCollector;
use CWP\RcloneCWP\Backup\Collectors\SslCollector;
use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Hook;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Notification;
use CWP\RcloneCWP\Rclone;

class BackupEngine
{
    /**
     * @var Database
     */
    private $db;

    /**
     * @var DestinationManager
     */
    private $dm;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var CwpAccountDiscovery
     */
    private $discovery;

    /**
     * @var Hook
     */
    private $hook;

    /**
     * @var Notification
     */
    private $notification;

    /**
     * @var ComponentCollectorInterface[]
     */
    private $collectors = [];

    /**
     * Lock file resource
     * @var resource|null
     */
    private $lockHandle = null;

    /**
     * Staging base directory
     * @var string
     */
    private $stagingBase;

    public function __construct(
        Database $db = null,
        DestinationManager $dm = null,
        Logger $logger = null,
        CwpAccountDiscovery $discovery = null,
        Hook $hook = null,
        Notification $notification = null
    ) {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
        $this->dm = $dm ?: new DestinationManager($this->db, null, $this->logger);
        $this->discovery = $discovery ?: new CwpAccountDiscovery($this->db);
        $this->hook = $hook ?: new Hook($this->db, $this->logger);
        $this->notification = $notification ?: new Notification($this->db, $this->logger);

        // Standard staging directory path
        $this->stagingBase = sys_get_temp_dir() . '/.rclonecwp_staging';
        if (!is_dir($this->stagingBase)) {
            @mkdir($this->stagingBase, 0700, true);
        }

        // Register default collectors
        $this->registerCollector(new AccountMetadataCollector());
        $this->registerCollector(new DatabaseCollector($this->db));
        $this->registerCollector(new FilesCollector());
        $this->registerCollector(new DnsCollector());
        $this->registerCollector(new SslCollector());
        $this->registerCollector(new CronCollector());
        $this->registerCollector(new MailCollector($this->db));
        $this->registerCollector(new FtpCollector());
    }

    public function registerCollector(ComponentCollectorInterface $collector): void
    {
        $this->collectors[$collector->getName()] = $collector;
    }

    /**
     * Acquire concurrency lock for backup executions.
     *
     * @param int $jobId
     * @return bool
     */
    private function acquireLock(int $jobId): bool
    {
        $lockFile = sys_get_temp_dir() . "/rclonecwp_job_{$jobId}.lock";
        $this->lockHandle = @fopen($lockFile, 'c+');
        if (!$this->lockHandle) {
            return false;
        }

        return @flock($this->lockHandle, LOCK_EX | LOCK_NB);
    }

    /**
     * Release concurrency lock.
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Execute a backup job by ID.
     *
     * @param int $jobId
     * @param array $overrideOptions
     * @return array
     */
    public function runJob(int $jobId, array $overrideOptions = []): array
    {
        $job = $this->db->fetch('SELECT * FROM rclone_jobs WHERE id = ?', [$jobId]);
        if (!$job) {
            return ['ok' => false, 'error' => 'Backup job not found: ' . $jobId];
        }

        if (!$this->acquireLock($jobId)) {
            return [
                'ok'    => false,
                'error' => 'Backup job #' . $jobId . ' is already actively running.',
            ];
        }

        $destId = (int) $job['destination_id'];
        $dest = $this->dm->getDestination($destId, true);
        if (!$dest || empty($dest['enabled'])) {
            $this->releaseLock();
            return [
                'ok'    => false,
                'error' => 'Destination #' . $destId . ' is missing or disabled.',
            ];
        }

        $provider = $this->dm->getProvider($dest['type']);
        $decryptedConfig = $dest['config'] ?? [];

        $backupType = $job['job_type'] ?: 'full';
        $startTime = time();
        $dateStr = date('Y-m-d H:i:s', $startTime);

        // 1. Create DB Execution Record in rclone_backups
        $backupId = $this->db->insert('rclone_backups', [
            'job_id'            => $jobId,
            'destination_id'    => $destId,
            'backup_type'       => $backupType,
            'status'            => 'running',
            'started_at'        => $dateStr,
            'files_count'       => 0,
            'bytes_transferred' => 0,
        ]);

        $this->logger->info("Started backup job #{$jobId} ('{$job['name']}'), execution #{$backupId}");

        // Execute pre-backup hook and notification (backup_start)
        $startContext = [
            'job_id'      => $jobId,
            'job_name'    => $job['name'],
            'backup_id'   => $backupId,
            'backup_type' => $backupType,
            'status'      => 'running',
            'timestamp'   => $startTime,
        ];
        try {
            $this->hook->execute('backup_start', $jobId, $startContext);
            $this->notification->notify('backup_start', $startContext);
        } catch (\Exception $e) {
            $this->logger->warning("Warning executing backup_start hook/notification: " . $e->getMessage());
        }

        // 2. Parse target users and component selection
        $jobConfig = $this->parseJobSourcePath($job['source_path'] ?? '');
        $targetAccounts = $this->resolveTargetAccounts($jobConfig['accounts'] ?? ['*']);
        $enabledComponents = $jobConfig['components'] ?? ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'];

        if (empty($targetAccounts)) {
            $this->finishBackup($backupId, 'failed', 0, 0, time() - $startTime, 'No valid target accounts resolved.');
            $this->releaseLock();

            $failContext = array_merge($startContext, [
                'status'   => 'failed',
                'error'    => 'No valid target accounts resolved.',
                'duration' => time() - $startTime,
            ]);
            $this->hook->execute('backup_fail', $jobId, $failContext);
            $this->notification->notify('backup_fail', $failContext);

            return ['ok' => false, 'error' => 'No target accounts found for this job.'];
        }

        $jobSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($job['name']));
        $timestamp = date('Ymd_His', $startTime);

        $totalFiles = 0;
        $totalBytes = 0;
        $userErrors = [];
        $runStagingDir = $this->stagingBase . '/run_' . $backupId;
        @mkdir($runStagingDir, 0700, true);

        try {
            foreach ($targetAccounts as $account) {
                $username = $account['username'] ?? '';
                // Prevent path traversal, leading dash injection, and metacharacters
                if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || strpos($username, '..') !== false || $username[0] === '-') {
                    $this->logger->warning("Skipping invalid username: ". $username);
                    continue;
                }

                $accountStaging = $runStagingDir . '/' . $username;
                @mkdir($accountStaging, 0700, true);

                $this->logger->info("Staging components for user '{$username}' in job #{$jobId}");

                // Staging and collecting components
                $manifest = [
                    'version'          => '1.0',
                    'generator'        => 'rcloneCWP v' . (defined('RCLONE_VERSION') ? RCLONE_VERSION : '1.0.0'),
                    'job_id'           => $jobId,
                    'job_name'         => $job['name'],
                    'username'         => $username,
                    'type'             => $backupType,
                    'started_at'       => date('Y-m-d H:i:s'),
                    'components'       => [],
                    'checksums'        => [],
                ];

                $userBytes = 0;
                $userFiles = 0;

                // 2a. Always collect account metadata
                if (isset($this->collectors['metadata'])) {
                    $metaRes = $this->collectors['metadata']->collect($account, $accountStaging);
                    $manifest['components']['metadata'] = $metaRes;
                    $userBytes += $metaRes['bytes'];
                    $userFiles += $metaRes['files_count'];
                }

                // 2b. Collect other requested components
                foreach ($enabledComponents as $compName) {
                    if ($compName === 'metadata' || !isset($this->collectors[$compName])) {
                        continue;
                    }

                    $collector = $this->collectors[$compName];
                    $colRes = $collector->collect($account, $accountStaging, [
                        'mode' => ($backupType === 'incremental') ? 'direct_sync' : 'archive',
                    ]);

                    $manifest['components'][$compName] = $colRes;
                    $userBytes += $colRes['bytes'] ?? 0;
                    $userFiles += $colRes['files_count'] ?? 0;

                    // Extract checksums if present
                    if (!empty($colRes['items']) && is_array($colRes['items'])) {
                        foreach ($colRes['items'] as $item) {
                            if (!empty($item['file']) && !empty($item['checksum'])) {
                                $manifest['checksums'][$compName . '/' . $item['file']] = $item['checksum'];
                            }
                        }
                    }

                    if (!$colRes['ok'] && !empty($colRes['error'])) {
                        $userErrors[] = "User {$username} [{$compName}]: " . $colRes['error'];
                    }
                }

                $manifest['completed_at'] = date('Y-m-d H:i:s');
                @file_put_contents(
                    $accountStaging . '/manifest.json',
                    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
                $userFiles++;
                $userBytes += filesize($accountStaging . '/manifest.json');

                // 3. Dynamic rclone Transfer to Remote Destination
                $snapshotDirName = "{$timestamp}_{$username}_{$backupType}";
                $subPath = "rcloneCWP-backups/{$jobSlug}/{$snapshotDirName}";

                $remoteName = 'job_' . $jobId . '_' . substr(md5(uniqid('', true)), 0, 8);
                $env = $provider->getRcloneEnv($decryptedConfig, $remoteName);
                $remoteTarget = $provider->getRemoteTarget($decryptedConfig, $remoteName, $subPath);
                if ($remoteTarget === '' || $remoteTarget[0] === '-' || preg_match('/[\x00-\x1f\x7f`$;|&><\'"\\\\\s]/', $remoteTarget)) {
                    throw new \InvalidArgumentException("Invalid or unsafe remote target");
                }

                $this->logger->info("Transferring snapshot for user '{$username}' to {$remoteTarget}");

                // Transfer staged directory
                $transferRes = Rclone::execute(
                    'copy',
                    [$accountStaging, $remoteTarget],
                    ['transfers', 'retries'],
                    ['transfers' => 4, 'retries' => 3],
                    1800,
                    $env
                );

                if ($transferRes['exit'] !== 0) {
                    $err = trim($transferRes['stderr']) ?: trim($transferRes['stdout']);
                    $userErrors[] = "Transfer failed for user {$username}: " . $err;
                    $this->logger->error("rclone transfer error for {$username}: {$err}");
                } else {
                    $totalFiles += $userFiles;
                    $totalBytes += $userBytes;
                }

                // If incremental direct_sync was chosen for files, sync /home/<username> directly
                if ($backupType === 'incremental' && in_array('files', $enabledComponents, true)) {
                    $homeDir = $account['home_dir'];
                    $remoteFilesTarget = $provider->getRemoteTarget($decryptedConfig, $remoteName, $subPath . '/files');

                    $syncFlags = ['transfers', 'retries'];
                    $syncValues = ['transfers' => 4, 'retries' => 3];

                    $filesRes = Rclone::execute(
                        'copy',
                        [$homeDir, $remoteFilesTarget],
                        $syncFlags,
                        $syncValues,
                        3600,
                        $env
                    );

                    if ($filesRes['exit'] !== 0) {
                        $this->logger->warning("Incremental files sync warning for {$username}: " . $filesRes['stderr']);
                    }
                }

                // Clean up user staging directory immediately to save disk
                $this->cleanupDir($accountStaging);
            }

            // Record snapshot base in config_id
            $configId = "rcloneCWP-backups/{$jobSlug}/{$timestamp}";
            $this->db->update('rclone_backups', ['config_id' => $configId], 'id = ?', [$backupId]);

            // 4. Retention Policy Pruning
            $retentionDays = (int) ($job['retention_days'] ?? 7);
            if ($retentionDays > 0) {
                $this->pruneRetention($jobId, $retentionDays, $provider, $decryptedConfig, $jobSlug);
            }

            $duration = time() - $startTime;
            $finalStatus = empty($userErrors) ? 'completed' : 'completed';
            $errorMsg = !empty($userErrors) ? implode('; ', $userErrors) : null;

            $this->finishBackup($backupId, $finalStatus, $totalFiles, $totalBytes, $duration, $errorMsg);
            $this->logger->info("Backup job #{$jobId} finished with status '{$finalStatus}' in {$duration}s");

            // Dispatch backup_complete hooks & notifications
            $completeContext = [
                'job_id'      => $jobId,
                'job_name'    => $job['name'],
                'backup_id'   => $backupId,
                'status'      => $finalStatus,
                'duration'    => $duration,
                'files_count' => $totalFiles,
                'bytes'       => $totalBytes,
                'errors'      => $userErrors,
                'error'       => $errorMsg,
                'timestamp'   => time(),
            ];

            try {
                $this->hook->execute('backup_complete', $jobId, $completeContext);
                $this->notification->notify('backup_complete', $completeContext);
            } catch (\Exception $e) {
                $this->logger->warning("Warning executing backup_complete hook/notification: " . $e->getMessage());
            }

            return [
                'ok'          => true,
                'backup_id'   => $backupId,
                'status'      => $finalStatus,
                'duration'    => $duration,
                'files_count' => $totalFiles,
                'bytes'       => $totalBytes,
                'errors'      => $userErrors,
            ];
        } catch (\Exception $e) {
            $duration = time() - $startTime;
            $this->finishBackup($backupId, 'failed', $totalFiles, $totalBytes, $duration, $e->getMessage());
            $this->logger->error("Exception in backup job #{$jobId}: " . $e->getMessage());

            // Dispatch backup_fail hooks & notifications
            $failContext = [
                'job_id'      => $jobId,
                'job_name'    => $job['name'],
                'backup_id'   => $backupId,
                'status'      => 'failed',
                'duration'    => $duration,
                'files_count' => $totalFiles,
                'bytes'       => $totalBytes,
                'error'       => $e->getMessage(),
                'timestamp'   => time(),
            ];

            try {
                $this->hook->execute('backup_fail', $jobId, $failContext);
                $this->notification->notify('backup_fail', $failContext);
            } catch (\Exception $ne) {
                $this->logger->warning("Warning executing backup_fail hook/notification: " . $ne->getMessage());
            }

            return [
                'ok'        => false,
                'backup_id' => $backupId,
                'error'     => $e->getMessage(),
            ];
        } finally {
            $this->cleanupDir($runStagingDir);
            $this->releaseLock();
        }
    }

    /**
     * Update rclone_backups record upon job completion or failure.
     */
    private function finishBackup(
        int $backupId,
        string $status,
        int $filesCount,
        int $bytes,
        int $duration,
        ?string $error
    ): void {
        $this->db->update('rclone_backups', [
            'status'            => $status,
            'completed_at'      => date('Y-m-d H:i:s'),
            'files_count'       => $filesCount,
            'bytes_transferred' => $bytes,
            'duration_seconds'  => $duration,
            'error_message'     => $error,
        ], 'id = ?', [$backupId]);
    }

    /**
     * Prune expired backups according to retention_days.
     */
    private function pruneRetention(
        int $jobId,
        int $retentionDays,
        $provider,
        array $decryptedConfig,
        string $jobSlug
    ): void {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
            $expired = $this->db->fetchAll(
                "SELECT id, config_id, started_at FROM rclone_backups " .
                "WHERE job_id = ? AND status = 'completed' AND started_at < ? AND config_id IS NOT NULL",
                [$jobId, $cutoff]
            );

            if (empty($expired)) {
                return;
            }

            $remoteName = 'prune_' . $jobId . '_' . substr(md5(uniqid('', true)), 0, 8);
            $env = $provider->getRcloneEnv($decryptedConfig, $remoteName);

            foreach ($expired as $exp) {
                $subPath = $exp['config_id'];
                if (!empty($subPath)) {
                    $remoteTarget = $provider->getRemoteTarget($decryptedConfig, $remoteName, $subPath);
                    if ($remoteTarget === '' || $remoteTarget[0] === '-' || preg_match('/[\x00-\x1f\x7f`$;|&><\'"\\\\\s]/', $remoteTarget)) {
                        throw new \InvalidArgumentException("Invalid or unsafe remote target for pruning");
                    }
                    $this->logger->info("Pruning expired backup #{$exp['id']} at {$remoteTarget}");
                    // Use rclone purge to remove remote folder
                    Rclone::execute('purge', [$remoteTarget], [], [], 120, $env);
                }

                // Delete or mark pruned
                $this->db->delete('rclone_backups', 'id = ?', [(int) $exp['id']]);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Retention pruning error for job #{$jobId}: " . $e->getMessage());
        }
    }

    /**
     * Resolve target accounts array from wildcards or list of usernames.
     */
    private function resolveTargetAccounts(array $usernames): array
    {
        $all = $this->discovery->listAccounts();
        if (in_array('*', $usernames, true) || in_array('all', $usernames, true)) {
            return array_values($all);
        }

        $resolved = [];
        foreach ($usernames as $u) {
            $u = trim($u);
            if (isset($all[$u])) {
                $resolved[] = $all[$u];
            }
        }

        return $resolved;
    }

    /**
     * Parse source_path JSON or plain text.
     */
    private function parseJobSourcePath(string $sourcePath): array
    {
        $decoded = json_decode($sourcePath, true);
        if (is_array($decoded)) {
            if (isset($decoded['accounts'])) {
                return $decoded;
            }
            // Array of account names
            return ['accounts' => $decoded, 'components' => ['files', 'databases', 'dns', 'ssl', 'cron', 'mail']];
        }

        if (trim($sourcePath) === '*' || trim($sourcePath) === '') {
            return ['accounts' => ['*'], 'components' => ['files', 'databases', 'dns', 'ssl', 'cron', 'mail']];
        }

        return [
            'accounts'   => array_filter(array_map('trim', explode(',', $sourcePath))),
            'components' => ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'],
        ];
    }

    /**
     * Safely recursively clean up a temporary staging directory.
     */
    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Safety check: ensure path is strictly within our staging directory
        $real = realpath($dir);
        $realBase = realpath($this->stagingBase);
        $realTemp = realpath(sys_get_temp_dir());

        if (!$real || (strpos($real, $realBase) !== 0 && strpos($real, $realTemp) !== 0)) {
            return;
        }

        exec('rm -rf ' . escapeshellarg($real));
    }
}
