<?php
/**
 * CWP MariaDB / MySQL Database Collector
 *
 * Dumps all databases belonging to the user account using mysqldump with
 * secure in-memory/temp config file (preventing password exposure in ps aux),
 * compressed with gzip and verified with SHA-256 checksums.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

use CWP\RcloneCWP\Database;

class DatabaseCollector implements ComponentCollectorInterface
{
    /**
     * @var Database
     */
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
        return 'MariaDB / MySQL Databases';
    }

    public function collect(array $account, string $stagingDir, array $options = []): array
    {
        $databases = $account['databases'] ?? [];
        if (empty($databases)) {
            return [
                'ok'          => true,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => null,
            ];
        }

        $dbDir = $stagingDir . '/databases';
        if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to create database staging directory: ' . $dbDir,
            ];
        }

        $dbConfig = $this->db->getConfig();
        $tempCnf = $this->createTempMysqlConfig($dbConfig);

        $dumpedItems = [];
        $totalBytes = 0;
        $errors = [];

        try {
            foreach ($databases as $dbName) {
                // Strict validation: MariaDB database names can only contain alphanumeric and underscores
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
                    $errors[] = "Invalid database name skipped: " . htmlspecialchars($dbName);
                    continue;
                }

                $dumpFile = $dbDir . '/' . $dbName . '.sql.gz';
                $result = $this->dumpDatabase($dbName, $dumpFile, $tempCnf);

                if ($result['ok']) {
                    $dumpedItems[] = [
                        'database'  => $dbName,
                        'file'      => basename($dumpFile),
                        'bytes'     => $result['bytes'],
                        'checksum'  => $result['checksum'],
                    ];
                    $totalBytes += $result['bytes'];
                } else {
                    $errors[] = "Database {$dbName} dump failed: " . $result['error'];
                }
            }
        } finally {
            if (is_file($tempCnf)) {
                @unlink($tempCnf);
            }
        }

        $hasError = !empty($errors);
        return [
            'ok'          => !$hasError || count($dumpedItems) > 0,
            'files_count' => count($dumpedItems),
            'bytes'       => $totalBytes,
            'items'       => $dumpedItems,
            'error'       => $hasError ? implode('; ', $errors) : null,
        ];
    }

    /**
     * Create a temporary client config file with mode 0600 so credentials never appear in ps aux.
     * Uses tempnam() for guaranteed unique filename to prevent collisions.
     *
     * @param array $config
     * @return string Path to temporary .cnf file
     */
    private function createTempMysqlConfig(array $config): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'rclone_mysql_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary MySQL config file');
        }
        // Ensure .cnf extension
        $tempFile .= '.cnf';

        $content = "[client]\n";
        if (!empty($config['user'])) {
            $content .= "user=" . addcslashes($config['user'], "\"'\\") . "\n";
        }
        if (!empty($config['pass'])) {
            $content .= "password=" . addcslashes($config['pass'], "\"'\\") . "\n";
        }
        if (!empty($config['host'])) {
            $content .= "host=" . $config['host'] . "\n";
        }
        if (!empty($config['port'])) {
            $content .= "port=" . (int) $config['port'] . "\n";
        }
        if (!empty($config['sock']) && is_file($config['sock'])) {
            $content .= "socket=" . $config['sock'] . "\n";
        }

        file_put_contents($tempFile, $content);
        chmod($tempFile, 0600);

        return $tempFile;
    }

    /**
     * Dump and gzip a single database safely.
     *
     * @param string $dbName
     * @param string $outputFile
     * @param string $tempCnf
     * @return array
     */
    private function dumpDatabase(string $dbName, string $outputFile, string $tempCnf): array
    {
        $mysqldump = is_file('/usr/bin/mysqldump') ? '/usr/bin/mysqldump' : 'mysqldump';

        // Flags: single-transaction for InnoDB consistency, routines and triggers
        $cmd = sprintf(
            '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s | gzip -c > %s',
            escapeshellarg($mysqldump),
            escapeshellarg($tempCnf),
            escapeshellarg($dbName),
            escapeshellarg($outputFile)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Failed to spawn mysqldump process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_file($outputFile) || filesize($outputFile) === 0) {
            $err = trim($stderr) ?: 'mysqldump exited with status ' . $exitCode;
            return ['ok' => false, 'error' => $err];
        }

        $size = filesize($outputFile);
        $hash = hash_file('sha256', $outputFile);

        return [
            'ok'       => true,
            'bytes'    => $size,
            'checksum' => 'sha256:' . $hash,
        ];
    }
}
