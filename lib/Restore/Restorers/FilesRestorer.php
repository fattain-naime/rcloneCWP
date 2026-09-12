<?php
/**
 * rcloneCWP User Files Restorer
 *
 * Restores files from home.tar.gz or individual component archives into
 * /home/<username>/, strictly preserving user-owned file ownership.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore\Restorers
 */

namespace CWP\RcloneCWP\Restore\Restorers;

use CWP\RcloneCWP\Restore\ComponentRestorerInterface;

class FilesRestorer implements ComponentRestorerInterface
{
    public function getName(): string
    {
        return 'files';
    }

    public function getLabel(): string
    {
        return 'User Home Files';
    }

    /**
     * Restore user files.
     *
     * @param array $account
     * @param string $stagedDir
     * @param array $options ['target_paths' => array of dirs to restore like public_html]
     * @return array
     */
    public function restore(array $account, string $stagedDir, array $options = []): array
    {
        $username = $account['username'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || $username[0] === '-') {
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => 'Invalid username.'];
        }

        $homeDir = '/home/' . $username;
        $filesDir = $stagedDir . '/files';

        if (!is_dir($filesDir)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        // Verify home directory exists
        if (!is_dir($homeDir)) {
            return [
                'ok'       => false,
                'restored' => [],
                'bytes'    => 0,
                'error'    => "Home directory {$homeDir} does not exist.",
            ];
        }

        // Check UID/GID consistency
        $homeUid = (int)posix_getuid(); // Should ideally look up actual user UID

        $restored = [];
        $totalBytes = 0;
        $errors = [];

        $targetPaths = isset($options['target_paths']) && is_array($options['target_paths'])
            ? $options['target_paths']
            : null;

        try {
            // Check if we have home.tar.gz
            $tarPath = $filesDir . '/home.tar.gz';
            if (is_file($tarPath)) {
                $tarBytes = (int)@filesize($tarPath);
                $totalBytes += $tarBytes;

                $escTar = escapeshellarg($tarPath);
                $escHome = escapeshellarg($homeDir);

                // Pre-scan tar archive for path traversal or dangerous entries
                $listCmd = "tar -tzf {$escTar} 2>&1";
                exec($listCmd, $listOutput, $listExit);
                if ($listExit !== 0) {
                    throw new \Exception('Failed to inspect archive contents: ' . implode("\n", $listOutput));
                }

                foreach ($listOutput as $archiveEntry) {
                    $entry = trim($archiveEntry);
                    if ($entry === '') {
                        continue;
                    }
                    // Reject absolute paths, directory traversal, or leading flags
                    if ($entry[0] === '/' || strpos($entry, '..') !== false || $entry[0] === '-') {
                        throw new \Exception("Unsafe path in archive entry: {$entry}");
                    }
                }

                // Safe extraction to home directory
                $cmd = "tar xzf {$escTar} -C {$escHome} --no-same-owner --no-same-permissions 2>&1";
                exec($cmd, $output, $exitCode);

                if ($exitCode !== 0) {
                    $errors[] = 'tar extraction failed: ' . implode("\n", $output);
                } else {
                    // CRITICAL: Restore user ownership on all extracted files
                    $escUser = escapeshellarg($username);
                    $chownCmd = "chown -R {$escUser}:{$escUser} {$escHome} 2>&1";
                    exec($chownCmd, $chownOut, $chownExit);

                    if ($chownExit === 0) {
                        $restored[] = [
                            'type'   => 'full_archive',
                            'bytes'  => $tarBytes,
                            'target' => $homeDir,
                        ];
                    } else {
                        $errors[] = 'User ownership restoration failed: ' . implode("\n", $chownOut);
                    }
                }
            } else {
                // Extract individual files/directories
                $entries = scandir($filesDir);
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    $srcPath = $filesDir . '/' . $entry;
                    $destPath = $homeDir . '/' . $entry;

                    // If target_paths filter is set, check if entry matches
                    if ($targetPaths !== null) {
                        $inTarget = false;
                        foreach ($targetPaths as $tp) {
                            $tp = trim($tp, '/');
                            if ($entry === $tp) {
                                $inTarget = true;
                                break;
                            }
                        }
                        if (!$inTarget) {
                            continue;
                        }
                    }

                    if (is_dir($srcPath)) {
                        $cmd = "cp -rT " . escapeshellarg($srcPath) . " " . escapeshellarg($destPath) . " 2>&1";
                    } else {
                        $cmd = "cp " . escapeshellarg($srcPath) . " " . escapeshellarg($destPath) . " 2>&1";
                    }

                    exec($cmd, $output, $exitCode);

                    if ($exitCode === 0) {
                        $entryBytes = (int)@filesize($srcPath);
                        $totalBytes += $entryBytes;
                        $restored[] = [
                            'type'   => 'individual',
                            'file'   => $entry,
                            'bytes'  => $entryBytes,
                        ];
                    } else {
                        $errors[] = "Failed to restore {$entry}: " . implode("\n", $output);
                    }
                }
            }

            // Ensure ownership is preserved for all restored content
            $escUser = escapeshellarg($username);
            $finalCmd = "chown -R {$escUser}:{$escUser} " . escapeshellarg($homeDir) . " 2>&1";
            exec($finalCmd, $out, $ret);
            if ($ret !== 0) {
                $errors[] = 'Final chown failed: ' . implode("\n", $out);
            }

            $ok = empty($errors);
            return [
                'ok'       => $ok,
                'restored' => $restored,
                'bytes'    => $totalBytes,
                'error'    => $ok ? null : implode('; ', $errors),
            ];
        } catch (\Exception $e) {
            return [
                'ok'       => false,
                'restored' => $restored,
                'bytes'    => $totalBytes,
                'error'    => $e->getMessage(),
            ];
        }
    }
}
