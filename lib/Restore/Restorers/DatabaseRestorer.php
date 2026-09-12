<?php
/**
 * rcloneCWP MySQL/MariaDB Database Restorer
 *
 * Restores user databases from compressed .sql.gz dumps using secure
 * temporary credential configs (mode 0600, preventing process table leaks).
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore\Restorers
 */

namespace CWP\RcloneCWP\Restore\Restorers;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Restore\ComponentRestorerInterface;

class DatabaseRestorer implements ComponentRestorerInterface
{
    /** @var Database */
    private $db;

    public function __construct(Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public function getName(): string
    {
        return 'databases';
    }

    public function getLabel(): string
    {
        return 'MySQL / MariaDB Databases';
    }

    /**
     * Restore user databases.
     *
     * @param array $account
     * @param string $stagedDir
     * @param array $options ['databases' => array of specific db names to restore]
     * @return array
     */
    public function restore(array $account, string $stagedDir, array $options = []): array
    {
        $dbDir = $stagedDir . '/databases';
        if (!is_dir($dbDir)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        $dumpFiles = glob($dbDir . '/*.sql.gz');
        if (empty($dumpFiles)) {
            // Also check for uncompressed .sql
            $dumpFiles = glob($dbDir . '/*.sql');
        }

        if (empty($dumpFiles)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        $selectedDbs = isset($options['databases']) && is_array($options['databases'])
            ? $options['databases']
            : null;

        $dbConfig = $this->db->getConfig();
        $tempCnf = $this->createTempMysqlConfig($dbConfig);

        $restored = [];
        $totalBytes = 0;
        $errors = [];

        try {
            foreach ($dumpFiles as $file) {
                $basename = basename($file);
                $dbName = preg_replace('/(\.sql|\.sql\.gz)$/', '', $basename);

                // Security: validate database name to prevent command injection
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
                    $errors[] = "Invalid database name rejected: {$dbName}";
                    continue;
                }

                // If specific databases requested, filter
                if ($selectedDbs !== null && !in_array($dbName, $selectedDbs, true)) {
                    continue;
                }

                // 1. Ensure the database exists in MariaDB
                try {
                    $pdo = $this->db->getConnection();
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (\Exception $e) {
                    $errors[] = "Failed to create database {$dbName}: " . $e->getMessage();
                    continue;
                }

                // 2. Import dump into database via secure temporary config
                $isGz = (substr($file, -3) === '.gz');
                $escFile = escapeshellarg($file);
                $escCnf = escapeshellarg($tempCnf);
                $escDb = escapeshellarg($dbName);

                if ($isGz) {
                    $cmd = "zcat {$escFile} | mysql --defaults-extra-file={$escCnf} {$escDb} 2>&1";
                } else {
                    $cmd = "mysql --defaults-extra-file={$escCnf} {$escDb} < {$escFile} 2>&1";
                }

                exec($cmd, $output, $exitCode);

                if ($exitCode !== 0) {
                    $errText = trim(implode("\n", $output));
                    $errors[] = "Failed to restore database {$dbName}: {$errText}";
                } else {
                    $fileBytes = (int)@filesize($file);
                    $totalBytes += $fileBytes;
                    $restored[] = [
                        'database' => $dbName,
                        'file'     => $basename,
                        'bytes'    => $fileBytes,
                    ];
                }
            }
        } finally {
            if (is_file($tempCnf)) {
                @unlink($tempCnf);
            }
        }

        $ok = empty($errors);
        return [
            'ok'       => $ok,
            'restored' => $restored,
            'bytes'    => $totalBytes,
            'error'    => $ok ? null : implode('; ', $errors),
        ];
    }

    /**
     * Create temporary MySQL client config file with credentials.
     *
     * @param array $config
     * @return string
     */
    private function createTempMysqlConfig(array $config): string
    {
        $tempFile = sys_get_temp_dir() . '/.rclone_mysql_restore_' . bin2hex(random_bytes(8)) . '.cnf';
        $content = "[client]\n" .
                   "user=" . addcslashes($config['user'], "\"'\\") . "\n" .
                   "password=" . addcslashes($config['pass'], "\"'\\") . "\n" .
                   "host=" . addcslashes($config['host'], "\"'\\") . "\n";

        file_put_contents($tempFile, $content);
        chmod($tempFile, 0600);

        return $tempFile;
    }
}
