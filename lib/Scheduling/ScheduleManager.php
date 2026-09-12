<?php
/**
 * rcloneCWP Schedule Manager
 *
 * CRUD management for backup schedules, due detection, and next/last run tracking.
 * PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Scheduling
 */

namespace CWP\RcloneCWP\Scheduling;

use CWP\RcloneCWP\Database;

class ScheduleManager
{
    /** @var Database */
    private $db;

    /** @var CronParser */
    private $parser;

    public function __construct(Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->parser = new CronParser();
    }

    /**
     * List all schedules with job info and computed next run.
     *
     * @param bool $activeOnly
     * @return array
     */
    public function listSchedules(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE s.active = 1 ' : '';
        $sql = "SELECT s.*, j.name AS job_name, j.source_path, j.retention_days, d.name AS destination_name " .
               "FROM rclone_schedules s " .
               "JOIN rclone_jobs j ON s.job_id = j.id " .
               "LEFT JOIN rclone_destinations d ON j.destination_id = d.id " .
               $where .
               "ORDER BY s.id DESC";

        $rows = $this->db->fetchAll($sql);
        $results = [];

        foreach ($rows as $row) {
            $nextRun = $this->computeNextRun($row);
            $results[] = [
                'id'                 => (int) $row['id'],
                'job_id'             => (int) $row['job_id'],
                'job_name'           => $row['job_name'],
                'cron_expression'    => $row['cron_expression'],
                'timezone'           => $row['timezone'] ?? 'UTC',
                'active'             => (bool) $row['active'],
                'next_run'           => $nextRun ? $nextRun->format('Y-m-d H:i:s') : null,
                'last_run'           => $row['last_run'],
                'created_at'         => $row['created_at'],
                'destination_name'   => $row['destination_name'] ?? 'Unknown',
                'retention_days'     => (int) ($row['retention_days'] ?? 7),
            ];
        }

        return $results;
    }

    /**
     * Get a single schedule by ID with full details.
     *
     * @param int $id
     * @return array|null
     */
    public function getSchedule(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM rclone_schedules WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }

        $job = $this->db->fetch(
            'SELECT j.*, d.name AS destination_name FROM rclone_jobs j LEFT JOIN rclone_destinations d ON j.destination_id = d.id WHERE j.id = ?',
            [$row['job_id']]
        );

        $nextRun = $this->computeNextRun($row);

        return [
            'id'                 => (int) $row['id'],
            'job_id'             => (int) $row['job_id'],
            'job_name'           => $job['name'] ?? '',
            'cron_expression'    => $row['cron_expression'],
            'timezone'           => $row['timezone'] ?? 'UTC',
            'active'             => (bool) $row['active'],
            'next_run'           => $nextRun ? $nextRun->format('Y-m-d H:i:s') : null,
            'last_run'           => $row['last_run'],
            'created_at'         => $row['created_at'],
            'destination_name'   => $job['destination_name'] ?? 'Unknown',
            'retention_days'     => (int) ($job['retention_days'] ?? 7),
            'next_runs_preview'  => $this->getNextRunsPreview($row),
        ];
    }

    /**
     * Create a new schedule.
     *
     * @param array $data
     * @return array ['ok' => bool, 'id' => int|null, 'error' => string|null]
     */
    public function createSchedule(array $data): array
    {
        $jobId = (int) ($data['job_id'] ?? 0);
        if ($jobId <= 0) {
            return ['ok' => false, 'error' => 'Valid job_id is required.'];
        }

        $job = $this->db->fetch('SELECT id FROM rclone_jobs WHERE id = ?', [$jobId]);
        if (!$job) {
            return ['ok' => false, 'error' => 'Backup job does not exist.'];
        }

        $cron = trim($data['cron_expression'] ?? '');
        if (!CronParser::isValid($cron)) {
            return ['ok' => false, 'error' => 'Invalid cron expression format (5 fields required).'];
        }

        $timezone = trim($data['timezone'] ?? 'UTC');
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Invalid timezone.'];
        }

        $active = !empty($data['active']) ? 1 : 0;

        try {
            $id = $this->db->insert('rclone_schedules', [
                'job_id'           => $jobId,
                'cron_expression'  => $cron,
                'timezone'         => $timezone,
                'active'           => $active,
                'next_run'         => null, // will be computed
            ]);

            // Compute and store initial next_run
            $this->updateNextRun($id);

            return ['ok' => true, 'id' => (int) $id];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Update an existing schedule.
     *
     * @param int $id
     * @param array $data
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public function updateSchedule(int $id, array $data): array
    {
        $existing = $this->db->fetch('SELECT * FROM rclone_schedules WHERE id = ?', [$id]);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Schedule not found.'];
        }

        $fields = [];

        if (isset($data['cron_expression'])) {
            $cron = trim($data['cron_expression']);
            if (!CronParser::isValid($cron)) {
                return ['ok' => false, 'error' => 'Invalid cron expression format (5 fields required).'];
            }
            $fields['cron_expression'] = $cron;
        }

        if (isset($data['timezone'])) {
            $timezone = trim($data['timezone']);
            try {
                new \DateTimeZone($timezone);
            } catch (\Exception $e) {
                return ['ok' => false, 'error' => 'Invalid timezone.'];
            }
            $fields['timezone'] = $timezone;
        }

        if (isset($data['active'])) {
            $fields['active'] = !empty($data['active']) ? 1 : 0;
        }

        if (empty($fields)) {
            return ['ok' => true];
        }

        try {
            $this->db->update('rclone_schedules', $fields, 'id = ?', [$id]);
            // Recompute next_run if cron or timezone changed
            if (isset($fields['cron_expression']) || isset($fields['timezone'])) {
                $this->updateNextRun($id);
            }
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a schedule.
     *
     * @param int $id
     * @return array
     */
    public function deleteSchedule(int $id): array
    {
        try {
            $this->db->delete('rclone_schedules', 'id = ?', [$id]);
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Toggle schedule active state.
     *
     * @param int $id
     * @param bool $active
     * @return array
     */
    public function toggleActive(int $id, bool $active): array
    {
        try {
            $this->db->update('rclone_schedules', ['active' => $active ? 1 : 0], 'id = ?', [$id]);
            if ($active) {
                $this->updateNextRun($id);
            }
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Get all active schedules that are currently due (next_run <= now).
     *
     * @return array Each entry has schedule_id, job_id, cron_expression, timezone
     */
    public function getDueSchedules(): array
    {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT s.*, j.source_path, j.retention_days, d.config AS dest_config, d.type AS dest_type " .
               "FROM rclone_schedules s " .
               "JOIN rclone_jobs j ON s.job_id = j.id " .
               "LEFT JOIN rclone_destinations d ON j.destination_id = d.id " .
               "WHERE s.active = 1 AND s.next_run IS NOT NULL AND s.next_run <= ? " .
               "ORDER BY s.next_run ASC";

        return $this->db->fetchAll($sql, [$now]);
    }

    /**
     * Update the next_run timestamp for a schedule.
     *
     * @param int $scheduleId
     * @param int|null $fromTimestamp Optional reference timestamp (defaults to now)
     */
    public function updateNextRun(int $scheduleId, int $fromTimestamp = null): void
    {
        $row = $this->db->fetch('SELECT * FROM rclone_schedules WHERE id = ?', [$scheduleId]);
        if (!$row || !$row['active']) {
            return;
        }

        try {
            $next = CronParser::nextRun($row['cron_expression'], $fromTimestamp, $row['timezone'] ?? 'UTC');
            $this->db->update(
                'rclone_schedules',
                ['next_run' => $next->format('Y-m-d H:i:s')],
                'id = ?',
                [$scheduleId]
            );
        } catch (\Exception $e) {
            // Silent failure; next_run stays stale
        }
    }

    /**
     * Update last_run timestamp after a job execution.
     *
     * @param int $scheduleId
     * @param string $status 'completed' | 'failed' | 'running'
     */
    public function recordRun(int $scheduleId, string $status): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            if ($status === 'completed' || $status === 'failed') {
                $this->db->update(
                    'rclone_schedules',
                    ['last_run' => $now],
                    'id = ?',
                    [$scheduleId]
                );
                // After completion, schedule next run
                $this->updateNextRun($scheduleId);
            } else {
                // Running: just mark last_run, don't advance next_run yet
                $this->db->update(
                    'rclone_schedules',
                    ['last_run' => $now],
                    'id = ?',
                    [$scheduleId]
                );
            }
        } catch (\Exception $e) {
            // Silent
        }
    }

    /**
     * Compute the next run DateTime for a schedule row.
     *
     * @param array $row Schedule row from DB
     * @return \DateTime|null
     */
    private function computeNextRun(array $row): ?\DateTime
    {
        if (empty($row['active']) || empty($row['cron_expression'])) {
            return null;
        }

        try {
            $from = $row['next_run'] ? strtotime($row['next_run']) : time();
            return CronParser::nextRun($row['cron_expression'], $from, $row['timezone'] ?? 'UTC');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get next N runs preview for UI.
     *
     * @param array $row
     * @return array
     */
    private function getNextRunsPreview(array $row): array
    {
        if (empty($row['active']) || empty($row['cron_expression'])) {
            return [];
        }

        try {
            $from = time();
            $runs = CronParser::nextRuns($row['cron_expression'], 5, $row['timezone'] ?? 'UTC');
            $formatted = [];
            foreach ($runs as $dt) {
                $formatted[] = $dt->format('Y-m-d H:i:s');
            }
            return $formatted;
        } catch (\Exception $e) {
            return [];
        }
    }
}