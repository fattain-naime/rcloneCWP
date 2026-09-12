<?php
/**
 * rcloneCWP Mail Restorer
 *
 * Restores Postfix virtual mailboxes and aliases, system mail spool,
 * and user Maildir directories.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore\Restorers
 */

namespace CWP\RcloneCWP\Restore\Restorers;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Restore\ComponentRestorerInterface;

class MailRestorer implements ComponentRestorerInterface
{
    /** @var Database */
    private $db;

    public function __construct(Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public function getName(): string
    {
        return 'mail';
    }

    public function getLabel(): string
    {
        return 'Email Accounts & Messages';
    }

    /**
     * Restore mail data.
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

        // Prohibit system/privileged users from mail spool/maildir restoration
        $systemUsers = ['root', 'bin', 'daemon', 'sys', 'adm', 'mail', 'postfix', 'nobody', 'systemd', 'named', 'mysql', 'apache', 'nginx', 'cwp'];
        if (in_array(strtolower($username), $systemUsers, true)) {
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => "Cannot restore mail for system user '{$username}'."];
        }

        $mailDir = $stagedDir . '/meta/mail';
        $mailArchive = $stagedDir . '/mail/mail.tar.gz';
        $restored = [];
        $totalBytes = 0;
        $errors = [];

        try {
            // 1. Restore Postfix virtual mailboxes (re-insert into MySQL)
            $mailboxFile = $mailDir . '/mailboxes.json';
            if (is_file($mailboxFile)) {
                $mailboxes = json_decode(@file_get_contents($mailboxFile), true);
                if (is_array($mailboxes) && !empty($mailboxes)) {
                    $pdo = $this->db->getConnection();
                    foreach ($mailboxes as $mb) {
                        $sql = "INSERT INTO postfix.mailbox (username, name, maildir, quota, local_part, domain, created, active) " .
                               "VALUES (?, ?, ?, ?, ?, ?, ?, ?) " .
                               "ON DUPLICATE KEY UPDATE " .
                               "maildir=VALUES(maildir), quota=VALUES(quota), active=VALUES(active)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            $mb['username'] ?? '',
                            $mb['name'] ?? '',
                            $mb['maildir'] ?? '',
                            $mb['quota'] ?? 0,
                            $mb['local_part'] ?? '',
                            $mb['domain'] ?? '',
                            $mb['created'] ?? date('Y-m-d H:i:s'),
                            $mb['active'] ?? 1,
                        ]);
                        $totalBytes += (int)($mb['bytes'] ?? 0);
                    }
                    $restored[] = ['type' => 'mailboxes', 'count' => count($mailboxes)];
                }
            }

            // 2. Restore Aliases
            $aliasFile = $mailDir . '/aliases.json';
            if (is_file($aliasFile)) {
                $aliases = json_decode(@file_get_contents($aliasFile), true);
                if (is_array($aliases) && !empty($aliases)) {
                    $pdo = $this->db->getConnection();
                    foreach ($aliases as $al) {
                        $sql = "INSERT INTO postfix.alias (address, goto, domain, created, active) " .
                               "VALUES (?, ?, ?, ?, ?) " .
                               "ON DUPLICATE KEY UPDATE goto=VALUES(goto), active=VALUES(active)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            $al['address'] ?? '',
                            $al['goto'] ?? '',
                            $al['domain'] ?? '',
                            $al['created'] ?? date('Y-m-d H:i:s'),
                            $al['active'] ?? 1,
                        ]);
                    }
                    $restored[] = ['type' => 'aliases', 'count' => count($aliases)];
                }
            }

            // 3. Restore System Mail Spool (only for the account owner)
            $spoolFile = $mailDir . '/spool_' . $username;
            if (is_file($spoolFile)) {
                $destSpool = '/var/spool/mail/' . $username;
                // Verify the spool file content does not appear to be a symlink to a system path
                if (is_link($destSpool)) {
                    $errors[] = "Refusing to overwrite symlinked spool at {$destSpool}";
                } elseif (@copy($spoolFile, $destSpool)) {
                    @chown($destSpool, $username);
                    @chmod($destSpool, 0600);
                    $fileBytes = (int)@filesize($spoolFile);
                    $totalBytes += $fileBytes;
                    $restored[] = [
                        'type' => 'spool',
                        'file' => 'spool_' . $username,
                        'bytes' => $fileBytes,
                    ];
                } else {
                    $errors[] = "Failed to restore system mail spool for {$username}";
                }
            }

            // 4. Restore Maildir archive
            $archiveFile = $stagedDir . '/mail/mail.tar.gz';
            if (is_file($archiveFile)) {
                $homeUser = '/home/' . $username;
                $escTar = escapeshellarg($archiveFile);
                $escHome = escapeshellarg($homeUser);

                // Pre-scan tar archive for path traversal or dangerous entries
                $listCmd = "tar -tzf {$escTar} 2>&1";
                exec($listCmd, $listOutput, $listExit);
                if ($listExit !== 0) {
                    $errors[] = 'Failed to inspect mail archive: ' . implode("\n", $listOutput);
                } else {
                    foreach ($listOutput as $archiveEntry) {
                        $entry = trim($archiveEntry);
                        if ($entry === '') {
                            continue;
                        }
                        // Reject absolute paths, directory traversal, or leading flags
                        if ($entry[0] === '/' || strpos($entry, '..') !== false || $entry[0] === '-') {
                            throw new \Exception("Unsafe path in mail archive entry: {$entry}");
                        }
                    }

                    // Safe extraction with --no-same-owner, --no-same-permissions
                    $cmd = "tar xzf {$escTar} -C {$escHome} --no-same-owner --no-same-permissions 2>&1";
                    exec($cmd, $output, $exitCode);

                    if ($exitCode === 0) {
                        // Restore ownership
                        $escUser = escapeshellarg($username);
                        exec("chown -R {$escUser}:{$escUser} {$escHome} 2>&1", $chownOut, $chownRet);

                        $fileBytes = (int)@filesize($archiveFile);
                        $totalBytes += $fileBytes;
                        $restored[] = [
                            'type'  => 'maildir_archive',
                            'file'  => 'mail.tar.gz',
                            'bytes' => $fileBytes,
                        ];
                    } else {
                        $errors[] = 'Maildir archive extraction failed: ' . implode("\n", $output);
                    }
                }
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
