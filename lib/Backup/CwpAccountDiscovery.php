<?php
/**
 * CWP Account Discovery Service
 *
 * Inspects MariaDB root_cwp and filesystem to safely enumerate CWP user accounts,
 * associated domains, subdomains, packages, databases, and filesystem paths.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup
 */

namespace CWP\RcloneCWP\Backup;

use CWP\RcloneCWP\Database;

class CwpAccountDiscovery
{
    /**
     * @var Database
     */
    private $db;

    public function __construct(Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /**
     * List all CWP user accounts with their associated domains and metadata.
     *
     * @return array Array of user account arrays keyed by username
     */
    public function listAccounts(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, username, domain, ip_address, email, package, setup_date FROM user ORDER BY username ASC'
        );

        $accounts = [];
        foreach ($rows as $row) {
            $username = $row['username'];
            $accounts[$username] = $this->enrichAccount($row);
        }

        return $accounts;
    }

    /**
     * Get details for a single CWP account by username.
     *
     * @param string $username
     * @return array|null Account metadata or null if user does not exist
     */
    public function getAccount(string $username): ?array
    {
        $row = $this->db->fetch(
            'SELECT id, username, domain, ip_address, email, package, setup_date FROM user WHERE username = ?',
            [$username]
        );

        if (!$row) {
            return null;
        }

        return $this->enrichAccount($row);
    }

    /**
     * Enrich raw user row with domains, subdomains, package details, and databases.
     *
     * @param array $user
     * @return array
     */
    private function enrichAccount(array $user): array
    {
        $username = $user['username'];
        $primaryDomain = $user['domain'];
        $homeDir = '/home/' . $username;

        // 1. Fetch Addon Domains
        $addonDomains = [];
        try {
            $addonRows = $this->db->fetchAll(
                'SELECT domain, path FROM domains WHERE user = ? ORDER BY domain ASC',
                [$username]
            );
            foreach ($addonRows as $ar) {
                $addonDomains[] = [
                    'domain' => $ar['domain'],
                    'path'   => $ar['path'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            $addonDomains = [];
        }

        // 2. Fetch Subdomains
        $subdomains = [];
        try {
            $subRows = $this->db->fetchAll(
                'SELECT subdomain, domain, path FROM subdomains WHERE user = ? ORDER BY subdomain ASC',
                [$username]
            );
            foreach ($subRows as $sr) {
                $fqdn = trim($sr['subdomain']) . '.' . trim($sr['domain']);
                $subdomains[] = [
                    'subdomain' => $sr['subdomain'],
                    'domain'    => $sr['domain'],
                    'fqdn'      => $fqdn,
                    'path'      => $sr['path'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            $subdomains = [];
        }

        // 3. Compile all unique FQDN domains
        $allDomains = [];
        if (!empty($primaryDomain)) {
            $allDomains[] = strtolower(trim($primaryDomain));
        }
        foreach ($addonDomains as $ad) {
            $d = strtolower(trim($ad['domain']));
            if (!in_array($d, $allDomains, true)) {
                $allDomains[] = $d;
            }
        }
        foreach ($subdomains as $sd) {
            $d = strtolower(trim($sd['fqdn']));
            if (!in_array($d, $allDomains, true)) {
                $allDomains[] = $d;
            }
        }

        // 4. Fetch Package Details
        $packageInfo = [];
        if (!empty($user['package'])) {
            try {
                $pkgRow = $this->db->fetch(
                    'SELECT * FROM packages WHERE id = ? OR package_name = ?',
                    [$user['package'], $user['package']]
                );
                if ($pkgRow) {
                    $packageInfo = $pkgRow;
                }
            } catch (\Exception $e) {
                $packageInfo = [];
            }
        }

        // 5. Discover Databases
        $databases = $this->discoverDatabases($username);

        // 6. Verify Filesystem Paths
        $hasHome = is_dir($homeDir);
        $cronFile = '/var/spool/cron/' . $username;
        $hasCron = is_file($cronFile) && is_readable($cronFile);
        $mailSpool = '/var/spool/mail/' . $username;
        $hasMailSpool = is_file($mailSpool);

        return [
            'id'             => (int) $user['id'],
            'username'       => $username,
            'primary_domain' => $primaryDomain,
            'ip_address'     => $user['ip_address'] ?? '',
            'email'          => $user['email'] ?? '',
            'package_id'     => $user['package'] ?? '',
            'package'        => $packageInfo,
            'setup_date'     => $user['setup_date'] ?? null,
            'home_dir'       => $homeDir,
            'has_home'       => $hasHome,
            'addon_domains'  => $addonDomains,
            'subdomains'     => $subdomains,
            'all_domains'    => $allDomains,
            'databases'      => $databases,
            'has_cron'       => $hasCron,
            'cron_file'      => $hasCron ? $cronFile : null,
            'has_mail_spool' => $hasMailSpool,
            'mail_spool'     => $hasMailSpool ? $mailSpool : null,
        ];
    }

    /**
     * Enumerate all MariaDB databases belonging to the user.
     * Matches standard CWP naming: <username>_* or exact <username>.
     *
     * @param string $username
     * @return array List of database names
     */
    public function discoverDatabases(string $username): array
    {
        $dbs = [];
        $prefix = $username . '_';

        try {
            $allDbs = $this->db->fetchAll('SHOW DATABASES');
            foreach ($allDbs as $dRow) {
                $dbName = reset($dRow);
                if ($dbName === 'information_schema' || $dbName === 'performance_schema'
                    || $dbName === 'mysql' || $dbName === 'sys' || $dbName === 'root_cwp'
                    || $dbName === 'postfix' || $dbName === 'roundcube') {
                    continue;
                }

                if (strpos($dbName, $prefix) === 0 || $dbName === $username) {
                    $dbs[] = $dbName;
                }
            }
        } catch (\Exception $e) {
            $dbs = [];
        }

        return $dbs;
    }
}
