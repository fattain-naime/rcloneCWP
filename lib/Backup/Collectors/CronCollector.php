<?php
/**
 * CWP User Crontab Collector
 *
 * Backs up scheduled cron jobs for the user from /var/spool/cron/<username>.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

class CronCollector implements ComponentCollectorInterface
{
    public function getName(): string
    {
        return 'cron';
    }

    public function getLabel(): string
    {
        return 'User Cron Jobs';
    }

    public function collect(array $account, string $stagingDir, array $options = []): array
    {
        $username = $account['username'] ?? '';
        if (empty($username)) {
            return [
                'ok'          => true,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => null,
            ];
        }

        $cronFile = '/var/spool/cron/' . $username;
        if (!is_file($cronFile) || !is_readable($cronFile)) {
            return [
                'ok'          => true,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => null,
            ];
        }

        $metaDir = $stagingDir . '/meta';
        if (!is_dir($metaDir) && !mkdir($metaDir, 0755, true) && !is_dir($metaDir)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to create meta directory: ' . $metaDir,
            ];
        }

        $destFile = $metaDir . '/cron.tab';
        if (!@copy($cronFile, $destFile)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to copy cron file from: ' . $cronFile,
            ];
        }

        $size = filesize($destFile);
        $lines = count(file($destFile, FILE_SKIP_EMPTY_LINES));

        return [
            'ok'          => true,
            'files_count' => 1,
            'bytes'       => $size,
            'items'       => [
                [
                    'file'    => 'cron.tab',
                    'entries' => $lines,
                    'bytes'   => $size,
                ]
            ],
            'error'       => null,
        ];
    }
}
