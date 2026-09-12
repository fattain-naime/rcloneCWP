<?php
/**
 * rcloneCWP System Crontab Service
 *
 * Safely inspects, installs, and removes the /etc/cron.d/rclonecwp file.
 * Completely isolates rcloneCWP schedules from CWP core crontabs and system crontabs.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Scheduling
 */

namespace CWP\RcloneCWP\Scheduling;

class CrontabService
{
    const CRON_FILE = '/etc/cron.d/rclonecwp';

    /**
     * Check if the rclonecwp crontab is currently installed in /etc/cron.d/.
     *
     * @return bool
     */
    public static function isInstalled(): bool
    {
        return is_file(self::CRON_FILE);
    }

    /**
     * Get the current status and details of the crontab service.
     *
     * @return array ['installed' => bool, 'path' => string, 'content' => string|null, 'writable' => bool]
     */
    public static function getStatus(): array
    {
        $installed = self::isInstalled();
        $cronDir = dirname(self::CRON_FILE);

        return [
            'installed' => $installed,
            'path'      => self::CRON_FILE,
            'content'   => $installed ? @file_get_contents(self::CRON_FILE) : null,
            'writable'  => is_writable($cronDir) || (is_file(self::CRON_FILE) && is_writable(self::CRON_FILE)),
        ];
    }

    /**
     * Generate the standardized /etc/cron.d/rclonecwp content.
     *
     * @param string|null $phpBinary
     * @param string|null $runtimeDir
     * @return string
     */
    public static function getCrontabContent(string $phpBinary = null, string $runtimeDir = null): string
    {
        $php = $phpBinary ?: (is_executable('/usr/local/cwp/php71/bin/php') ? '/usr/local/cwp/php71/bin/php' : PHP_BINARY);
        $runtime = $runtimeDir ?: (defined('RCLONE_HOME') ? RCLONE_HOME : '/usr/local/cwp/rcloneCWP');

        $runnerPath = $runtime . '/cron/rcloneCWP.php';
        $cleanupPath = $runtime . '/cron/rclone_cleanup.php';

        return "# rcloneCWP Automated Backup & Maintenance Schedule\n" .
               "# Generated automatically by rcloneCWP. Do not edit manually.\n" .
               "SHELL=/bin/bash\n" .
               "PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/local/cwp/php71/bin\n\n" .
               "# Main backup runner: checks due schedules every 5 minutes\n" .
               "*/5 * * * * root {$php} {$runnerPath} >> /var/log/rcloneCWP_cron.log 2>&1\n\n" .
               "# Retention cleaner: prunes expired backups daily at 2:00 AM\n" .
               "0 2 * * * root {$php} {$cleanupPath} >> /var/log/rcloneCWP_cleanup.log 2>&1\n";
    }

    /**
     * Install or update /etc/cron.d/rclonecwp.
     *
     * @param string|null $phpBinary
     * @param string|null $runtimeDir
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public static function install(string $phpBinary = null, string $runtimeDir = null): array
    {
        $cronDir = dirname(self::CRON_FILE);
        if (!is_dir($cronDir)) {
            return ['ok' => false, 'error' => "System directory {$cronDir} does not exist."];
        }

        $content = self::getCrontabContent($phpBinary, $runtimeDir);

        if (@file_put_contents(self::CRON_FILE, $content) === false) {
            return [
                'ok'    => false,
                'error' => "Failed to write crontab file to " . self::CRON_FILE . ". Please check root permissions.",
            ];
        }

        // Standard permissions for /etc/cron.d/ files: 0644 root:root
        @chmod(self::CRON_FILE, 0644);
        @chown(self::CRON_FILE, 'root');

        return ['ok' => true, 'path' => self::CRON_FILE];
    }

    /**
     * Remove /etc/cron.d/rclonecwp.
     *
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public static function uninstall(): array
    {
        if (!is_file(self::CRON_FILE)) {
            return ['ok' => true, 'message' => 'Crontab file was not present.'];
        }

        if (@unlink(self::CRON_FILE)) {
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => "Failed to delete " . self::CRON_FILE];
    }
}
