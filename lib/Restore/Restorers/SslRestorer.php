<?php
/**
 * rcloneCWP SSL/TLS Certificate Restorer
 *
 * Restores TLS certificates and private keys to /etc/pki/tls/{certs,private}/.
 * Enforces mode 0600 on private keys.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore\Restorers
 */

namespace CWP\RcloneCWP\Restore\Restorers;

use CWP\RcloneCWP\Restore\ComponentRestorerInterface;

class SslRestorer implements ComponentRestorerInterface
{
    public function getName(): string
    {
        return 'ssl';
    }

    public function getLabel(): string
    {
        return 'SSL / TLS Certificates & Keys';
    }

    /**
     * Restore SSL certificates and private keys.
     *
     * @param array $account
     * @param string $stagedDir
     * @param array $options
     * @return array
     */
    public function restore(array $account, string $stagedDir, array $options = []): array
    {
        $sslDir = $stagedDir . '/meta/ssl';
        if (!is_dir($sslDir)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        $certFiles = glob($sslDir . '/*.cert');
        $keyFiles = glob($sslDir . '/*.key');

        if (empty($certFiles) && empty($keyFiles)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        $restored = [];
        $totalBytes = 0;
        $errors = [];

        // Build allowed domains whitelist from account if available
        $allowedDomains = [];
        if (!empty($account['primary_domain'])) {
            $allowedDomains[] = strtolower($account['primary_domain']);
        }
        if (!empty($account['all_domains']) && is_array($account['all_domains'])) {
            foreach ($account['all_domains'] as $d) {
                $allowedDomains[] = strtolower($d);
            }
        }
        $allowedDomains = array_unique($allowedDomains);

        // Helper to check domain authorization
        $isDomainAuthorized = function (string $domain) use ($allowedDomains): bool {
            if (empty($allowedDomains)) {
                return false; // Strict defense: account must have registered domains
            }
            $d = strtolower($domain);
            foreach ($allowedDomains as $allowed) {
                if ($d === $allowed || substr($d, -strlen('.' . $allowed)) === ('.' . $allowed)) {
                    return true;
                }
            }
            return false;
        };

        // 1. Restore Certificates to /etc/pki/tls/certs/
        foreach ($certFiles as $file) {
            $basename = basename($file);
            $domain = preg_replace('/\.cert$/', '', $basename);

            if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domain) || $domain[0] === '-') {
                $errors[] = "Invalid SSL domain rejected: {$domain}";
                continue;
            }

            if (!$isDomainAuthorized($domain)) {
                $errors[] = "SSL certificate domain '{$domain}' does not belong to account";
                continue;
            }

            $dest = '/etc/pki/tls/certs/' . $basename;
            if (is_link($dest)) {
                @unlink($dest);
            }

            if (@copy($file, $dest)) {
                @chmod($dest, 0644);
                $fileBytes = (int)@filesize($file);
                $totalBytes += $fileBytes;
                $restored[] = [
                    'domain' => $domain,
                    'type'   => 'cert',
                    'file'   => $basename,
                    'bytes'  => $fileBytes,
                ];
            } else {
                $errors[] = "Failed to copy certificate for {$domain}";
            }
        }

        // 2. Restore Private Keys to /etc/pki/tls/private/ (enforce mode 0600)
        foreach ($keyFiles as $file) {
            $basename = basename($file);
            $domain = preg_replace('/\.key$/', '', $basename);

            if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domain) || $domain[0] === '-') {
                $errors[] = "Invalid SSL domain rejected: {$domain}";
                continue;
            }

            if (!$isDomainAuthorized($domain)) {
                $errors[] = "SSL private key domain '{$domain}' does not belong to account";
                continue;
            }

            $dest = '/etc/pki/tls/private/' . $basename;
            if (is_link($dest)) {
                @unlink($dest);
            }

            if (@copy($file, $dest)) {
                @chmod($dest, 0600);
                $fileBytes = (int)@filesize($file);
                $totalBytes += $fileBytes;
                $restored[] = [
                    'domain' => $domain,
                    'type'   => 'key',
                    'file'   => $basename,
                    'bytes'  => $fileBytes,
                ];
            } else {
                $errors[] = "Failed to copy private key for {$domain}";
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
}
