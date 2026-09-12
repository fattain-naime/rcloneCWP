<?php
/**
 * rcloneCWP Backup Job Manager
 *
 * CRUD management for backup jobs, validation, source account mapping,
 * execution dispatching, and run history reporting.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup
 */

namespace CWP\RcloneCWP\Backup;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Validator;

class BackupJobManager
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
     * @var BackupEngine
     */
    private $engine;

    public function __construct(
        Database $db = null,
        DestinationManager $dm = null,
        Logger $logger = null,
        BackupEngine $engine = null
    ) {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
        $this->dm = $dm ?: new DestinationManager($this->db, null, $this->logger);
        $this->engine = $engine ?: new BackupEngine($this->db, $this->dm, $this->logger);
    }

    /**
     * List all backup jobs with destination names and latest execution metrics.
     *
     * @return array
     */
    public function listJobs(): array
    {
        $sql = "SELECT j.*, d.name AS destination_name, d.type AS destination_type, d.enabled AS destination_enabled " .
               "FROM rclone_jobs j " .
               "LEFT JOIN rclone_destinations d ON j.destination_id = d.id " .
               "ORDER BY j.id DESC";

        $jobs = $this->db->fetchAll($sql);
        $results = [];

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];

            // Fetch latest execution run
            $lastRun = $this->db->fetch(
                "SELECT id, status, started_at, completed_at, duration_seconds, bytes_transferred, files_count, error_message " .
                "FROM rclone_backups WHERE job_id = ? ORDER BY id DESC LIMIT 1",
                [$jobId]
            );

            // Parse source config
            $parsedSource = $this->parseSourceConfig($job['source_path'] ?? '');

            $results[] = [
                'id'                  => $jobId,
                'name'                => $job['name'],
                'destination_id'      => (int) $job['destination_id'],
                'destination_name'    => $job['destination_name'] ?: 'Unknown Destination',
                'destination_type'    => $job['destination_type'] ?: '',
                'destination_enabled' => (bool) $job['destination_enabled'],
                'job_type'            => $job['job_type'] ?: 'incremental',
                'retention_days'      => (int) ($job['retention_days'] ?? 7),
                'compression'         => (bool) ($job['compression'] ?? 1),
                'notify_on_success'   => (bool) ($job['notify_on_success'] ?? 0),
                'notify_on_failure'   => (bool) ($job['notify_on_failure'] ?? 1),
                'accounts'            => $parsedSource['accounts'],
                'components'          => $parsedSource['components'],
                'created_at'          => $job['created_at'],
                'last_run'            => $lastRun ?: null,
            ];
        }

        return $results;
    }

    /**
     * Get a single backup job by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getJob(int $id): ?array
    {
        $job = $this->db->fetch('SELECT * FROM rclone_jobs WHERE id = ?', [$id]);
        if (!$job) {
            return null;
        }

        $parsed = $this->parseSourceConfig($job['source_path'] ?? '');
        $job['accounts'] = $parsed['accounts'];
        $job['components'] = $parsed['components'];

        return $job;
    }

    /**
     * Create a new backup job.
     *
     * @param array $data
     * @return array ['ok' => bool, 'id' => int|null, 'error' => string|null]
     */
    public function createJob(array $data): array
    {
        $name = Validator::string($data['name'] ?? '', 100, 'name');
        if (!$name) {
            return ['ok' => false, 'error' => 'Job name is required (max 100 characters).'];
        }

        $destinationId = (int) ($data['destination_id'] ?? 0);
        if ($destinationId <= 0) {
            return ['ok' => false, 'error' => 'A valid backup storage destination is required.'];
        }

        // Verify destination exists
        $dest = $this->dm->getDestination($destinationId, false);
        if (!$dest) {
            return ['ok' => false, 'error' => 'Selected destination does not exist.'];
        }

        $jobType = $data['job_type'] ?? 'incremental';
        if (!in_array($jobType, ['full', 'incremental', 'selective'], true)) {
            $jobType = 'incremental';
        }

        $retentionDays = max(1, min(3650, (int) ($data['retention_days'] ?? 7)));
        $compression = !empty($data['compression']) ? 1 : 0;
        $notifySuccess = !empty($data['notify_on_success']) ? 1 : 0;
        $notifyFailure = !empty($data['notify_on_failure']) ? 1 : 0;

        $accounts = isset($data['accounts']) && is_array($data['accounts']) ? $data['accounts'] : ['*'];
        $components = isset($data['components']) && is_array($data['components'])
            ? $data['components']
            : ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'];

        $sourceJson = json_encode([
            'accounts'   => array_values($accounts),
            'components' => array_values($components),
        ], JSON_UNESCAPED_SLASHES);

        try {
            $id = $this->db->insert('rclone_jobs', [
                'name'              => $name,
                'source_path'       => $sourceJson,
                'destination_id'    => $destinationId,
                'job_type'          => $jobType,
                'retention_days'    => $retentionDays,
                'compression'       => $compression,
                'notify_on_success' => $notifySuccess,
                'notify_on_failure' => $notifyFailure,
            ]);

            $this->logger->info("Created backup job #{$id} ('{$name}')");
            return ['ok' => true, 'id' => (int) $id];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Update an existing backup job.
     *
     * @param int $id
     * @param array $data
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public function updateJob(int $id, array $data): array
    {
        $existing = $this->db->fetch('SELECT id FROM rclone_jobs WHERE id = ?', [$id]);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Backup job not found.'];
        }

        $fields = [];
        if (isset($data['name'])) {
            $name = Validator::string($data['name'], 100, 'name');
            if (!$name) {
                return ['ok' => false, 'error' => 'Job name cannot be empty.'];
            }
            $fields['name'] = $name;
        }

        if (isset($data['destination_id'])) {
            $destinationId = (int) $data['destination_id'];
            $dest = $this->dm->getDestination($destinationId, false);
            if (!$dest) {
                return ['ok' => false, 'error' => 'Selected destination does not exist.'];
            }
            $fields['destination_id'] = $destinationId;
        }

        if (isset($data['job_type']) && in_array($data['job_type'], ['full', 'incremental', 'selective'], true)) {
            $fields['job_type'] = $data['job_type'];
        }

        if (isset($data['retention_days'])) {
            $fields['retention_days'] = max(1, min(3650, (int) $data['retention_days']));
        }

        if (isset($data['compression'])) {
            $fields['compression'] = !empty($data['compression']) ? 1 : 0;
        }

        if (isset($data['notify_on_success'])) {
            $fields['notify_on_success'] = !empty($data['notify_on_success']) ? 1 : 0;
        }

        if (isset($data['notify_on_failure'])) {
            $fields['notify_on_failure'] = !empty($data['notify_on_failure']) ? 1 : 0;
        }

        if (isset($data['accounts']) || isset($data['components'])) {
            $current = $this->getJob($id);
            $accounts = isset($data['accounts']) && is_array($data['accounts'])
                ? $data['accounts']
                : ($current['accounts'] ?? ['*']);
            $components = isset($data['components']) && is_array($data['components'])
                ? $data['components']
                : ($current['components'] ?? ['files', 'databases', 'dns', 'ssl', 'cron', 'mail']);

            $fields['source_path'] = json_encode([
                'accounts'   => array_values($accounts),
                'components' => array_values($components),
            ], JSON_UNESCAPED_SLASHES);
        }

        if (empty($fields)) {
            return ['ok' => true];
        }

        try {
            $this->db->update('rclone_jobs', $fields, 'id = ?', [$id]);
            $this->logger->info("Updated backup job #{$id}");
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a backup job.
     *
     * @param int $id
     * @return array
     */
    public function deleteJob(int $id): array
    {
        try {
            $this->db->delete('rclone_jobs', 'id = ?', [$id]);
            $this->logger->info("Deleted backup job #{$id}");
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Trigger execution of a backup job immediately.
     *
     * @param int $id
     * @return array
     */
    public function runJobNow(int $id): array
    {
        return $this->engine->runJob($id);
    }

    /**
     * List historical backup runs.
     *
     * @param int $jobId Optional filter by job ID
     * @param int $limit
     * @return array
     */
    public function listHistory(int $jobId = 0, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $params = [];
        $where = '';

        if ($jobId > 0) {
            $where = 'WHERE b.job_id = ? ';
            $params[] = $jobId;
        }

        $sql = "SELECT b.*, j.name AS job_name, d.name AS destination_name, d.type AS destination_type " .
               "FROM rclone_backups b " .
               "LEFT JOIN rclone_jobs j ON b.job_id = j.id " .
               "LEFT JOIN rclone_destinations d ON b.destination_id = d.id " .
               $where .
               "ORDER BY b.id DESC LIMIT " . $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Helper to safely parse source_path into accounts and components.
     *
     * @param string $sourcePath
     * @return array
     */
    private function parseSourceConfig(string $sourcePath): array
    {
        $decoded = json_decode($sourcePath, true);
        if (is_array($decoded)) {
            return [
                'accounts'   => $decoded['accounts'] ?? ['*'],
                'components' => $decoded['components'] ?? ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'],
            ];
        }

        if (trim($sourcePath) === '*' || trim($sourcePath) === '') {
            return [
                'accounts'   => ['*'],
                'components' => ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'],
            ];
        }

        return [
            'accounts'   => array_filter(array_map('trim', explode(',', $sourcePath))),
            'components' => ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'],
        ];
    }
}
