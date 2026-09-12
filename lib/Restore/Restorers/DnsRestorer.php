<?php
/**
 * rcloneCWP DNS Zone Restorer
 *
 * Restores BIND zone files from backup to /var/named/ and reloads named.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore\Restorers
 */

namespace CWP\RcloneCWP\Restore\Restorers;

use CWP\RcloneCWP\Restore\ComponentRestorerInterface;

class DnsRestorer implements ComponentRestorerInterface
{
    public function getName(): string
    {
        return 'dns';
    }

    public function getLabel(): string
    {
        return 'DNS Zone Files (BIND)';
    }

    /**
     * Restore DNS zone files.
     *
     * @param array $account
     * @param string $stagedDir
     * @param array $options
     * @return array
     */
    public function restore(array $account, string $stagedDir, array $options = []): array
    {
        $dnsDir = $stagedDir . '/meta/dns';
        if (!is_dir($dnsDir)) {
            return [
                'ok'       => true,
                'restored' => [],
                'bytes'    => 0,
                'error'    => null,
            ];
        }

        $zoneFiles = glob($dnsDir . '/*.db');
        if (empty($zoneFiles)) {
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

        foreach ($zoneFiles as $file) {
            $basename = basename($file);
            // Validate zone file name (domain.db format)
            if (!preg_match('/^[a-zA-Z0-9.-]+\.db$/', $basename) || $basename[0] === '-') {
                $errors[] = "Invalid zone file name: {$basename}";
                continue;
            }

            $zoneDomain = preg_replace('/\.db$/', '', $basename);
            if (!$isDomainAuthorized($zoneDomain)) {
                $errors[] = "DNS zone domain '{$zoneDomain}' does not belong to account";
                continue;
            }

            $destPath = '/var/named/' . $basename;
            if (is_link($destPath)) {
                @unlink($destPath);
            }

            $escSrc = escapeshellarg($file);
            $escDest = escapeshellarg($destPath);

            $cmd = "cp {$escSrc} {$escDest} 2>&1";
            exec($cmd, $output, $exitCode);

            if ($exitCode === 0) {
                $fileBytes = (int)@filesize($file);
                $totalBytes += $fileBytes;
                $restored[] = [
                    'zone' => $basename,
                    'file' => $basename,
                    'bytes' => $fileBytes,
                ];

                // Set correct permissions for named
                exec("chown named:named {$escDest} 2>&1", $out, $ret);
                exec("chmod 644 {$escDest} 2>&1", $out, $ret);
            } else {
                $errors[] = "Failed to restore DNS zone {$basename}: " . implode("\n", $output);
            }
        }

        // Reload named if any zones were restored (best-effort notification)
        if (!empty($restored)) {
            @exec("systemctl reload named 2>&1 || service named reload 2>&1 || rndc reload 2>&1");
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
