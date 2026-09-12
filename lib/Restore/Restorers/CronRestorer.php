<?php
/**
 * rcloneCWP User Crontab Restorer
 *
 * Restores user crontabs from backup to /var/spool/cron/<username>.
 * Enforces mode 0600, root-owned.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore\Restorers
 */

namespace CWP\RcloneCWP\Restore\Restorers;

use CWP\RcloneCWP\Restore\ComponentRestorerInterface;

class CronRestorer implements ComponentRestorerInterface
{
    public function getName(): string
    {
        return 'cron';
    }

    public function getLabel(): string
    {
        return 'User Scheduled Crontab';
    }

    /**
     * Restore user crontab.
     *
     * @param array $account
     * @param string $stagedDir
     * @param array $options
     * @return array
     */
    public function restore(array $account, string $stagedDir, array $options = []): array
    {
        $username = $account['username'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || $username[0] === '-') {
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => 'Invalid username.'];
        }

        $cronFile = $stagedDir . '/meta/cron/' . $username . '.cron';
        if (!is_file($cronFile)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        $dest = '/var/spool/cron/' . $username;
        if (@copy($cronFile, $dest)) {
            @chown($dest, 'root');
            @chgrp($dest, 'root');
            @chmod($dest, 0600);

            $bytes = (int)@filesize($cronFile);
            return [
                'ok'       => true,
                'restored' => [
                    'type'  => 'cron',
                    'user'  => $username,
                    'file'  => $dest,
                    'bytes' => $bytes,
                ],
                'bytes'    => $bytes,
                'error'    => null,
            ];
        }

        return [
            'ok'       => false,
            'restored' => [],
            'bytes'    => 0,
            'error'    => "Failed to write crontab to {$dest}",
        ];
    }
}
