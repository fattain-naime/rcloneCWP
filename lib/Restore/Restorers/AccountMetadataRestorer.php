<?php
/**
 * rcloneCWP Account Metadata Restorer
 *
 * Restores CWP account record in root_cwp.user, packages, and domain
 * configurations for Disaster Recovery / cross-server migration mode.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore\Restorers
 */

namespace CWP\RcloneCWP\Restore\Restorers;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Restore\ComponentRestorerInterface;

class AccountMetadataRestorer implements ComponentRestorerInterface
{
    /** @var Database */
    private $db;

    public function __construct(Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public function getName(): string
    {
        return 'metadata';
    }

    public function getLabel(): string
    {
        return 'Account Metadata & Migration';
    }

    /**
     * Restore account metadata into CWP database.
     *
     * @param array $account
     * @param string $stagedDir
     * @param array $options ['create_account' => bool to create new CWP user]
     * @return array
     */
    public function restore(array $account, string $stagedDir, array $options = []): array
    {
        $metaFile = $stagedDir . '/meta/account.json';
        if (!is_file($metaFile)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        $accountData = json_decode(@file_get_contents($metaFile), true);
        if (!is_array($accountData)) {
            return [
                'ok'       => false,
                'restored' => [],
                'bytes'    => 0,
                'error'    => 'Invalid account.json metadata file.',
            ];
        }

        $username = $accountData['username'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || $username[0] === '-') {
            return ['ok' => false, 'restored' => [], 'bytes' => 0, 'error' => 'Invalid username in account metadata.'];
        }

        $fileBytes = (int)@filesize($metaFile);
        $restored = [];
        $errors = [];
        $pdo = $this->db->getConnection();

        try {
            // Restore/Update user account in root_cwp
            // Check if user exists
            $existing = $this->db->fetch(
                "SELECT id FROM root_cwp.user WHERE username = ?",
                [$username]
            );

            $createAccount = !empty($options['create_account']) && !$existing;

            if ($createAccount) {
                // Create new user account with strong password hashing
                $hashedPass = password_hash(
                    $accountData['password'] ?? bin2hex(random_bytes(16)),
                    PASSWORD_DEFAULT
                );
                $insert = $pdo->prepare(
                    "INSERT INTO root_cwp.user (username, password, email, fullname, " .
                    "package, disklimit, bwlimit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $insert->execute([
                    $username,
                    $hashedPass,
                    $accountData['email'] ?? ($username . '@' . ($accountData['primary_domain'] ?? 'localhost')),
                    $accountData['fullname'] ?? $username,
                    $accountData['package'] ?? '',
                    $accountData['disklimit'] ?? 0,
                    $accountData['bwlimit'] ?? 0,
                    $accountData['status'] ?? 'Active',
                ]);
                $restored[] = ['type' => 'user_created', 'username' => $username];
            } else {
                $restored[] = ['type' => 'user_exists', 'username' => $username, 'action' => 'skipped'];
            }

            // Restore domains
            $domains = $accountData['domains'] ?? [];
            if (is_array($domains)) {
                foreach ($domains as $domain) {
                    $domainName = $domain['domain'] ?? '';
                    if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domainName)) {
                        continue;
                    }

                    // Insert or update domain in root_cwp.domains
                    $domainData = $this->db->fetch(
                        "SELECT id FROM root_cwp.domains WHERE domain = ?",
                        [$domainName]
                    );

                    $domainPath = $domain['path'] ?? ('/home/' . $username . '/public_html/' . $domainName);

                    if ($domainData) {
                        $pdo->prepare(
                            "UPDATE root_cwp.domains SET user = ?, path = ? WHERE domain = ?"
                        )->execute([
                            $username,
                            $domainPath,
                            $domainName,
                        ]);
                    } else {
                        $pdo->prepare(
                            "INSERT INTO root_cwp.domains (domain, user, path, setup_time) VALUES (?, ?, ?, ?)"
                        )->execute([
                            $domainName,
                            $username,
                            $domainPath,
                            $domain['created'] ?? date('Y-m-d H:i:s'),
                        ]);
                    }
                    $restored[] = ['type' => 'domain_updated', 'domain' => $domainName];
                }
            }

            return [
                'ok'       => true,
                'restored' => $restored,
                'bytes'    => $fileBytes,
                'error'    => null,
            ];
        } catch (\Exception $e) {
            return [
                'ok'       => false,
                'restored' => $restored,
                'bytes'    => $fileBytes,
                'error'    => $e->getMessage(),
            ];
        }
    }
}
