<?php
/**
 * CWP BIND DNS Zone Collector
 *
 * Discovers and exports BIND zone files from /var/named/<domain>.db
 * for all domains and subdomains belonging to the CWP user account.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

class DnsCollector implements ComponentCollectorInterface
{
    public function getName(): string
    {
        return 'dns';
    }

    public function getLabel(): string
    {
        return 'BIND DNS Zone Records';
    }

    public function collect(array $account, string $stagingDir, array $options = []): array
    {
        $allDomains = $account['all_domains'] ?? [];
        if (empty($allDomains)) {
            return [
                'ok'          => true,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => null,
            ];
        }

        $dnsDir = $stagingDir . '/meta/dns';
        if (!is_dir($dnsDir) && !mkdir($dnsDir, 0755, true) && !is_dir($dnsDir)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to create DNS staging directory: ' . $dnsDir,
            ];
        }

        $collected = [];
        $totalBytes = 0;

        foreach ($allDomains as $domain) {
            $domain = trim(strtolower($domain));
            if ($domain === '' || preg_match('/[^a-z0-9.-]/', $domain)) {
                continue;
            }

            $zonePath = '/var/named/' . $domain . '.db';
            if (is_file($zonePath) && is_readable($zonePath)) {
                $destFile = $dnsDir . '/' . $domain . '.db';
                if (@copy($zonePath, $destFile)) {
                    $size = filesize($destFile);
                    $totalBytes += $size;
                    $collected[] = [
                        'domain'   => $domain,
                        'file'     => $domain . '.db',
                        'bytes'    => $size,
                        'checksum' => 'sha256:' . hash_file('sha256', $destFile),
                    ];
                }
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
