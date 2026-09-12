<?php
/**
 * CWP Pure-FTPd Virtual Accounts Collector
 *
 * Backs up virtual FTP accounts belonging to the user from /etc/pure-ftpd/pureftpd.passwd.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

class FtpCollector implements ComponentCollectorInterface
{
    public function getName(): string
    {
        return 'ftp';
    }

    public function getLabel(): string
    {
        return 'FTP Virtual Accounts';
    }

    public function collect(array $account, string $stagingDir, array $options = []): array
    {
        $username = $account['username'] ?? '';
        $homeDir = $account['home_dir'] ?? ('/home/' . $username);

        $passwdFile = '/etc/pure-ftpd/pureftpd.passwd';
        if (!is_file($passwdFile) || !is_readable($passwdFile)) {
            return [
                'ok'          => true,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => null,
            ];
        }

        $lines = file($passwdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $userFtp = [];

        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 6) {
                $ftpUser = $parts[0];
                $ftpHome = $parts[5];

                // Match if home directory is under user's home or starts with username_
                if (strpos($ftpHome, $homeDir) === 0 || strpos($ftpUser, $username . '_') === 0 || $ftpUser === $username) {
                    $userFtp[] = [
                        'account'  => $ftpUser,
                        'entry'    => $line,
                        'home_dir' => $ftpHome,
                    ];
                }
            }
        }

        if (empty($userFtp)) {
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

        $dest = $metaDir . '/ftp_accounts.json';
        $json = json_encode($userFtp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($dest, $json);
        $size = filesize($dest);

        return [
            'ok'          => true,
            'files_count' => 1,
            'bytes'       => $size,
            'items'       => $userFtp,
            'error'       => null,
        ];
    }
}
