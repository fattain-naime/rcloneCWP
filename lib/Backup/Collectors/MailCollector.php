<?php
/**
 * CWP Email & Postfix Mailbox Collector
 *
 * Backs up virtual mailboxes and aliases from MariaDB postfix database,
 * system mail spool (/var/spool/mail/<username>), and Maildir directories.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

use CWP\RcloneCWP\Database;

class MailCollector implements ComponentCollectorInterface
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
        return 'mail';
    }

    public function getLabel(): string
    {
        return 'Email Accounts & Messages';
    }

    public function collect(array $account, string $stagingDir, array $options = []): array
    {
        $username = $account['username'] ?? '';
        $allDomains = $account['all_domains'] ?? [];

        $mailDir = $stagingDir . '/meta/mail';
        if (!is_dir($mailDir) && !mkdir($mailDir, 0755, true) && !is_dir($mailDir)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to create mail metadata directory: ' . $mailDir,
            ];
        }

        $collected = [];
        $totalBytes = 0;

        // 1. Export Postfix virtual mailboxes and aliases
        if (!empty($allDomains)) {
            $inClause = implode(',', array_fill(0, count($allDomains), '?'));

            // Mailboxes
            try {
                $mailboxes = $this->db->fetchAll(
                    "SELECT username, name, maildir, quota, local_part, domain, created, active " .
                    "FROM postfix.mailbox WHERE domain IN ($inClause)",
                    $allDomains
                );
                if (!empty($mailboxes)) {
                    $json = json_encode($mailboxes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $file = $mailDir . '/mailboxes.json';
                    file_put_contents($file, $json);
                    $b = filesize($file);
                    $totalBytes += $b;
                    $collected[] = [
                        'type'  => 'mailboxes',
                        'count' => count($mailboxes),
                        'bytes' => $b,
                    ];
                }
            } catch (\Exception $e) {
                // Non-fatal if postfix DB is not accessible
            }

            // Aliases
            try {
                $aliases = $this->db->fetchAll(
                    "SELECT address, goto, domain, created, active " .
                    "FROM postfix.alias WHERE domain IN ($inClause)",
                    $allDomains
                );
                if (!empty($aliases)) {
                    $json = json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $file = $mailDir . '/aliases.json';
                    file_put_contents($file, $json);
                    $b = filesize($file);
                    $totalBytes += $b;
                    $collected[] = [
                        'type'  => 'aliases',
                        'count' => count($aliases),
                        'bytes' => $b,
                    ];
                }
            } catch (\Exception $e) {
                // Non-fatal
            }
        }

        // 2. System Mail Spool (/var/spool/mail/<username>)
        if (!empty($username)) {
            $spoolFile = '/var/spool/mail/' . $username;
            if (is_file($spoolFile) && is_readable($spoolFile)) {
                $destSpool = $mailDir . '/spool_' . $username;
                if (@copy($spoolFile, $destSpool)) {
                    $b = filesize($destSpool);
                    $totalBytes += $b;
                    $collected[] = [
                        'type'  => 'spool',
                        'file'  => 'spool_' . $username,
                        'bytes' => $b,
                    ];
                }
            }
        }

        // 3. User maildir /home/<username>/mail if present
        $homeMail = '/home/' . $username . '/mail';
        if (is_dir($homeMail)) {
            $mailDataDir = $stagingDir . '/mail';
            if (!is_dir($mailDataDir)) {
                @mkdir($mailDataDir, 0755, true);
            }
            $archiveFile = $mailDataDir . '/mail.tar.gz';
            $tar = is_file('/usr/bin/tar') ? '/usr/bin/tar' : 'tar';
            $cmd = sprintf(
                '%s -czf %s -C %s mail',
                escapeshellarg($tar),
                escapeshellarg($archiveFile),
                escapeshellarg('/home/' . $username)
            );
            exec($cmd, $out, $ret);
            if ($ret === 0 && is_file($archiveFile)) {
                $b = filesize($archiveFile);
                $totalBytes += $b;
                $collected[] = [
                    'type'  => 'maildir_archive',
                    'file'  => 'mail.tar.gz',
                    'bytes' => $b,
                ];
            }
        }

        return [
            'ok'          => true,
            'files_count' => count($collected),
            'bytes'       => $totalBytes,
            'items'       => $collected,
            'error'       => null,
        ];
    }
}
