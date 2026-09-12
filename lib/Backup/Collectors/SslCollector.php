<?php
/**
 * CWP SSL Certificate & Key Collector
 *
 * Discovers and safely backs up TLS certificates, CA bundles, and private keys
 * from /etc/pki/tls/certs/ and /etc/pki/tls/private/ for all user domains.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

class SslCollector implements ComponentCollectorInterface
{
    public function getName(): string
    {
        return 'ssl';
    }

    public function getLabel(): string
    {
        return 'SSL Certificates & Private Keys';
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

        $sslDir = $stagingDir . '/meta/ssl';
        if (!is_dir($sslDir) && !mkdir($sslDir, 0700, true) && !is_dir($sslDir)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to create SSL staging directory: ' . $sslDir,
            ];
        }

        $collected = [];
        $totalBytes = 0;

        foreach ($allDomains as $domain) {
            $domain = trim(strtolower($domain));
            if ($domain === '' || preg_match('/[^a-z0-9.-]/', $domain)) {
                continue;
            }

            $certFound = false;
            $certCandidates = [
                '/etc/pki/tls/certs/' . $domain . '.cert',
                '/etc/pki/tls/certs/' . $domain . '.crt',
            ];
            foreach ($certCandidates as $cPath) {
                if (is_file($cPath) && is_readable($cPath)) {
                    $destCert = $sslDir . '/' . $domain . '.cert';
                    if (@copy($cPath, $destCert)) {
                        $size = filesize($destCert);
                        $totalBytes += $size;
                        $certFound = true;
                        $collected[] = [
                            'domain' => $domain,
                            'type'   => 'cert',
                            'file'   => $domain . '.cert',
                            'bytes'  => $size,
                        ];
                    }
                    break;
                }
            }

            // Private key
            $keyCandidates = [
                '/etc/pki/tls/private/' . $domain . '.key',
            ];
            foreach ($keyCandidates as $kPath) {
                if (is_file($kPath) && is_readable($kPath)) {
                    $destKey = $sslDir . '/' . $domain . '.key';
                    if (@copy($kPath, $destKey)) {
                        @chmod($destKey, 0600);
                        $size = filesize($destKey);
                        $totalBytes += $size;
                        $collected[] = [
                            'domain' => $domain,
                            'type'   => 'key',
                            'file'   => $domain . '.key',
                            'bytes'  => $size,
                        ];
                    }
                    break;
                }
            }

            // CA bundle if present
            $bundleCandidates = [
                '/etc/pki/tls/certs/' . $domain . '.bundle',
                '/etc/pki/tls/certs/' . $domain . '.ca-bundle',
            ];
            foreach ($bundleCandidates as $bPath) {
                if (is_file($bPath) && is_readable($bPath)) {
                    $destBundle = $sslDir . '/' . $domain . '.bundle';
                    if (@copy($bPath, $destBundle)) {
                        $size = filesize($destBundle);
                        $totalBytes += $size;
                        $collected[] = [
                            'domain' => $domain,
                            'type'   => 'bundle',
                            'file'   => $domain . '.bundle',
                            'bytes'  => $size,
                        ];
                    }
                    break;
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
