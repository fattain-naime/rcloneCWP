<?php
/**
 * rcloneCWP Restore Engine Core Orchestrator
 *
 * Orchestrates snapshot download, integrity verification (SHA-256),
 * concurrency locking, and isolated component restoration.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore
 */

namespace CWP\RcloneCWP\Restore;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Restore\Restorers\AccountMetadataRestorer;
use CWP\RcloneCWP\Restore\Restorers\CronRestorer;
use CWP\RcloneCWP\Restore\Restorers\DatabaseRestorer;
use CWP\RcloneCWP\Restore\Restorers\DnsRestorer;
use CWP\RcloneCWP\Restore\Restorers\FilesRestorer;
use CWP\RcloneCWP\Restore\Restorers\MailRestorer;
use CWP\RcloneCWP\Restore\Restorers\SslRestorer;

class RestoreEngine
{
    /** @var Database */
    private $db;

    /** @var DestinationManager */
    private $dm;

    /** @var Logger */
    private $logger;

    /** @var SnapshotBrowser */
    private $browser;

    /** @var ComponentRestorerInterface[] */
    private $restorers = [];

    /** @var resource|null */
    private $lockHandle = null;

    /** @var string */
    private $stagingBase;

    public function __construct(
        Database $db = null,
        DestinationManager $dm = null,
        Logger $logger = null,
        SnapshotBrowser $browser = null
    ) {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
        $this->dm = $dm ?: new DestinationManager($this->db, null, $this->logger);
        $this->browser = $browser ?: new SnapshotBrowser($this->db, $this->dm, $this->logger);

        $this->stagingBase = sys_get_temp_dir() . '/.rclonecwp_restore';
        if (!is_dir($this->stagingBase)) {
            @mkdir($this->stagingBase, 0700, true);
        }

        // Register default restorers
        $this->registerRestorer(new DatabaseRestorer($this->db));
        $this->registerRestorer(new FilesRestorer());
        $this->registerRestorer(new DnsRestorer());
        $this->registerRestorer(new SslRestorer());
        $this->registerRestorer(new CronRestorer());
        $this->registerRestorer(new MailRestorer($this->db));
        $this->registerRestorer(new AccountMetadataRestorer($this->db));
    }

    /**
     * Register a component restorer.
     *
     * @param ComponentRestorerInterface $restorer
     */
    public function registerRestorer(ComponentRestorerInterface $restorer): void
    {
        $this->restorers[$restorer->getName()] = $restorer;
    }

    /**
     * Acquire concurrency lock for restore operations.
     *
     * @param int $destinationId
     * @param string $snapshotPath
     * @return bool
     */
    private function acquireLock(int $destinationId, string $snapshotPath): bool
    {
        // Validate inputs to prevent injection into lock file path
        if (!preg_match('/^[a-zA-Z0-9_\/-]+$/', $snapshotPath)) {
            return false;
        }

        $lockId = md5($destinationId . '|' . $snapshotPath);
        $lockFile = sys_get_temp_dir() . "/rclonecwp_restore_{$lockId}.lock";
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
     * Execute a restore operation.
     *
     * @param int $destinationId
     * @param string $snapshotPath Path to snapshot (rcloneCWP-backups/<job_slug>/<timestamp_username_type>)
     * @param string $username Target account username for restore
     * @param array $components Components to restore (empty = all)
     * @param array $options Additional options (databases, target_paths, create_account)
     * @return array ['ok' => bool, 'restored' => array, 'bytes' => int, 'error' => string|null]
     */
    public function executeRestore(
        int $destinationId,
        string $snapshotPath,
        string $username,
        array $components = [],
        array $options = []
    ): array {
        // Validate username
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || $username[0] === '-') {
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => 'Invalid username.'];
        }

        // Validate snapshot path (prevent path traversal and command injection)
        if (empty($snapshotPath)
            || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $snapshotPath)
            || strpos($snapshotPath, '..') !== false
            || $snapshotPath[0] === '-'
        ) {
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => 'Invalid snapshot path.'];
        }

        if (!$this->acquireLock($destinationId, $snapshotPath)) {
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => 'A restore is already in progress for this snapshot.'];
        }

        $startTime = time();
        $restoreId = $this->logRestoreStart($destinationId, $snapshotPath, $username, $components, $options);

        $dest = $this->dm->getDestination($destinationId, true);
        if (!$dest || empty($dest['enabled'])) {
            $this->releaseLock();
            $this->logRestoreComplete($restoreId, 'failed', 0, 0, 'Destination not found or disabled.');
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => 'Destination not found or disabled.'];
        }

        $provider = $this->dm->getProvider($dest['type']);
        $config = $dest['config'] ?? [];
        $remoteName = 'restore_' . $destinationId . '_' . substr(md5(uniqid('', true)), 0, 8);
        $env = $provider->getRcloneEnv($config, $remoteName);

        $restoreDir = $this->stagingBase . '/restore_' . $restoreId . '_' . bin2hex(random_bytes(4));
        $extractDir = $restoreDir . '/extract';
        @mkdir($extractDir, 0700, true);

        $totalBytes = 0;
        $restored = [];
        $errors = [];

        try {
            // 1. Download snapshot manifest
            $manifestTarget = $provider->getRemoteTarget($config, $remoteName, $snapshotPath . '/manifest.json');
            $safeManifest = trim($manifestTarget);
            if ($safeManifest === '' || $safeManifest[0] === '-') {
                throw new \InvalidArgumentException('Invalid manifest remote target.');
            }

            $manifestRes = \CWP\RcloneCWP\Rclone::execute('cat', [$manifestTarget], [], [], 120, $env);
            if ($manifestRes['exit'] !== 0) {
                throw new \Exception('Failed to download manifest.json: ' . trim($manifestRes['stderr']));
            }

            $manifest = json_decode(trim($manifestRes['stdout']), true);
            if (!is_array($manifest)) {
                throw new \Exception('Invalid manifest.json format.');
            }

            // 2. Enforce manifest username matches request username (IDOR defense)
            $manifestUser = $manifest['username'] ?? '';
            if (empty($manifestUser)) {
                throw new \Exception('Snapshot manifest has no username field.');
            }
            if ($manifestUser !== $username) {
                $this->logger->warning(
                    "Restore username mismatch: request={$username} manifest={$manifestUser}"
                );
                throw new \Exception('Username mismatch between request and snapshot manifest.');
            }
            $targetUser = $manifestUser;

            // 3. Resolve account data from CWP for the target user
            $account = $this->resolveAccount($targetUser);
            if (!$account) {
                throw new \Exception("Target account {$targetUser} not found on this server.");
            }

            // 4. Determine which components to restore
            $manifestComponents = array_keys($manifest['components'] ?? []);
            $componentsToRestore = empty($components)
                ? array_filter($manifestComponents, function ($c) { return $c !== 'metadata'; })
                : array_intersect($components, $manifestComponents);

            if (empty($componentsToRestore)) {
                throw new \Exception('No valid components to restore.');
            }

            // 5. Download and verify components
            foreach ($componentsToRestore as $compName) {
                $remotePrefix = $this->getComponentRemotePrefix($compName);
                $stagedCompDir = $extractDir . '/' . $remotePrefix;
                @mkdir($stagedCompDir, 0755, true);

                $manifestComp = $manifest['components'][$compName] ?? [];
                $items = $manifestComp['items'] ?? [];

                $compTarget = $provider->getRemoteTarget($config, $remoteName, $snapshotPath . '/' . $remotePrefix);
                $safeCompTarget = trim($compTarget);
                if ($safeCompTarget === '' || $safeCompTarget[0] === '-'
                    || preg_match('/[\x00-\x1f\x7f`$;|&><\s]/', $safeCompTarget)) {
                    $errors[] = "Invalid remote path for component: {$compName}";
                    continue;
                }

                $timeout = ($compName === 'files' || $compName === 'databases') ? 3600 : 300;
                $transfers = ($compName === 'files' || $compName === 'databases') ? 4 : 2;

                $copyRes = \CWP\RcloneCWP\Rclone::execute(
                    'copy',
                    [$compTarget, $stagedCompDir],
                    ['transfers', 'retries'],
                    ['transfers' => $transfers, 'retries' => 3],
                    $timeout,
                    $env
                );

                if ($copyRes['exit'] !== 0) {
                    $this->logger->warning("rclone copy warning for {$compName}: " . trim($copyRes['stderr']));
                }

                // Verify checksums if available in manifest
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $itemFile = $item['file'] ?? '';
                        if (empty($itemFile)) {
                            continue;
                        }
                        $localFile = $stagedCompDir . '/' . basename($itemFile);
                        $expectedMd5 = $item['checksum'] ?? '';
                        if (strpos($expectedMd5, 'md5:') === 0) {
                            $expectedHash = substr($expectedMd5, 4);
                        } else {
                            $expectedHash = $expectedMd5;
                        }

                        if ($expectedHash !== '' && is_file($localFile)) {
                            $actualMd5 = md5_file($localFile);
                            if ($actualMd5 !== $expectedHash) {
                                $errors[] = "Checksum mismatch for {$compName}/{$itemFile}";
                            }
                        }
                    }
                }
            }

            // 6. Execute component restorers
            foreach ($componentsToRestore as $compName) {
                $stagedCompDir = $extractDir . '/' . $compName;

                // For metadata components, use meta directory
                if ($compName === 'metadata') {
                    $stagedCompDir = $extractDir;
                }

                // Map component name to restorer
                $restorerKey = $compName;
                if ($compName === 'files') {
                    $restorerKey = 'files';
                }

                if (!isset($this->restorers[$restorerKey])) {
                    $errors[] = "No restorer registered for component: {$compName}";
                    continue;
                }

                $this->logger->info("Restoring component '{$compName}' for user '{$targetUser}'");

                $restoreResult = $this->restorers[$restorerKey]->restore(
                    $account,
                    $extractDir,
                    $options
                );

                if (!$restoreResult['ok']) {
                    $errors[] = "{$compName}: " . ($restoreResult['error'] ?? 'Unknown error');
                }

                $restored[$compName] = $restoreResult['restored'] ?? [];
                $totalBytes += (int)($restoreResult['bytes'] ?? 0);
            }

            $duration = time() - $startTime;
            $ok = empty($errors);

            $logFields = [
                'status'        => $ok ? 'completed' : 'completed_with_errors',
                'completed_at'  => date('Y-m-d H:i:s'),
                'bytes_restored'=> $totalBytes,
                'duration_sec'  => $duration,
                'error_message' => $ok ? null : implode('; ', $errors),
            ];

            $this->logRestoreComplete($restoreId, $logFields['status'], $totalBytes, $duration, $logFields['error_message']);

            $this->logger->info("Restore #{$restoreId} completed for user '{$targetUser}', status: {$logFields['status']}");

            return [
                'ok'          => $ok,
                'restored'    => $restored,
                'bytes'       => $totalBytes,
                'duration'    => $duration,
                'restore_id'  => $restoreId,
                'error'       => $ok ? null : implode('; ', $errors),
            ];
        } catch (\Exception $e) {
            $duration = time() - $startTime;
            $this->logRestoreComplete($restoreId, 'failed', $totalBytes, $duration, $e->getMessage());
            $this->logger->error("Restore #{$restoreId} failed: " . $e->getMessage());

            return [
                'ok'          => false,
                'restored'    => $restored,
                'bytes'       => $totalBytes,
                'duration'    => $duration,
                'restore_id'  => $restoreId,
                'error'       => $e->getMessage(),
            ];
        } finally {
            $this->cleanupDir($restoreDir);
            $this->releaseLock();
        }
    }

    /**
     * Resolve account data for a given username.
     *
     * @param string $username
     * @return array|null
     */
    private function resolveAccount(string $username): ?array
    {
        $discovery = new \CWP\RcloneCWP\Backup\CwpAccountDiscovery($this->db);
        return $discovery->getAccount($username);
    }

    /**
     * Get the remote path prefix for a component.
     *
     * @param string $compName
     * @return string
     */
    private function getComponentRemotePrefix(string $compName): string
    {
        switch ($compName) {
            case 'dns':
                return 'meta/dns';
            case 'ssl':
                return 'meta/ssl';
            case 'cron':
                return 'meta/cron';
            case 'mail':
                return 'meta/mail';
            case 'metadata':
                return 'meta';
            default:
                return $compName;
        }
    }

    /**
     * Log restore start and return restore ID.
     *
     * @param int $destinationId
     * @param string $snapshotPath
     * @param string $username
     * @param array $components
     * @param array $options
     * @return int
     */
    private function logRestoreStart(
        int $destinationId,
        string $snapshotPath,
        string $username,
        array $components,
        array $options
    ): int {
        return (int)$this->db->insert('rclone_backups', [
            'job_id'            => null,
            'destination_id'    => $destinationId,
            'backup_type'       => 'restore',
            'status'            => 'running',
            'started_at'        => date('Y-m-d H:i:s'),
            'completed_at'      => null,
            'files_count'       => 0,
            'bytes_transferred' => 0,
            'duration_seconds'  => 0,
            'error_message'     => null,
            'config_id'         => $snapshotPath,
            'notes'             => json_encode([
                'username'   => $username,
                'components' => $components,
                'options'    => $options,
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Update restore record on completion.
     *
     * @param int $restoreId
     * @param string $status
     * @param int $bytes
     * @param int $duration
     * @param string|null $error
     */
    private function logRestoreComplete(
        int $restoreId,
        string $status,
        int $bytes,
        int $duration,
        ?string $error
    ): void {
        $this->db->update('rclone_backups', [
            'status'            => $status,
            'completed_at'      => date('Y-m-d H:i:s'),
            'bytes_transferred' => $bytes,
            'files_count'       => (int)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM rclone_backups WHERE id = ?",
                [$restoreId]
            ) ?: 0),
            'duration_seconds'  => $duration,
            'error_message'     => $error,
        ], 'id = ?', [$restoreId]);
    }

    /**
     * Safely clean up a temporary staging directory.
     *
     * @param string $dir
     */
    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $real = realpath($dir);
        $realBase = realpath($this->stagingBase);
        $realTemp = realpath(sys_get_temp_dir());

        if (!$real || (strpos($real, $realBase) !== 0 && strpos($real, $realTemp) !== 0)) {
            return;
        }

        exec('rm -rf ' . escapeshellarg($real));
    }
}
