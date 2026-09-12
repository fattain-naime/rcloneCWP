<?php
/**
 * rcloneCWP FTP Destination Provider
 *
 * Remote FTP and FTPS servers.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class FtpDestination extends AbstractDestination
{
    public function getType()
    {
        return 'ftp';
    }

    public function getName()
    {
        return 'FTP / FTPS';
    }

    public function getRcloneType()
    {
        return 'ftp';
    }

    public function getConfigFields()
    {
        return [
            'host' => [
                'label'       => 'FTP Host',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'ftp.example.com',
                'help'        => 'FTP server hostname or IP.',
            ],
            'port' => [
                'label'       => 'Port',
                'type'        => 'number',
                'required'    => false,
                'default'     => '21',
                'placeholder' => '21',
                'help'        => 'Standard FTP port is 21; FTPS implicit is 990.',
            ],
            'user' => [
                'label'       => 'Username',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'ftpuser',
                'help'        => 'FTP username.',
            ],
            'pass' => [
                'label'       => 'Password',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => '••••••••',
                'help'        => 'FTP password (encrypted with AES-256-GCM).',
            ],
            'tls' => [
                'label'    => 'TLS Encryption',
                'type'     => 'select',
                'required' => false,
                'default'  => 'false',
                'options'  => [
                    'false'    => 'Plain FTP (No encryption)',
                    'true'     => 'Explicit FTPS (TLS)',
                    'implicit' => 'Implicit FTPS (Port 990)',
                ],
                'help'     => 'Use FTPS whenever supported by the remote server.',
            ],
            'path' => [
                'label'       => 'Remote Directory',
                'type'        => 'text',
                'required'    => false,
                'default'     => '/',
                'placeholder' => '/backups',
                'help'        => 'Path relative to user root on FTP server.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['pass'];
    }

    public function validateConfig(array $config)
    {
        $base = parent::validateConfig($config);
        if (!$base['valid']) {
            return $base;
        }

        // SSRF defense: validate host is not loopback, private, or metadata endpoint
        $host = trim($config['host'] ?? '');
        if ($host === '') {
            return [
                'valid'  => false,
                'errors' => ['host' => 'FTP host is required.'],
            ];
        }

        $hostCheck = \CWP\RcloneCWP\Validator::validateHostSecurity($host, false, 'host');
        if (!$hostCheck['valid']) {
            return ['valid' => false, 'errors' => ['host' => $hostCheck['error']]];
        }

        $port = (int) ($config['port'] ?? 21);
        if ($port < 1 || $port > 65535) {
            return [
                'valid'  => false,
                'errors' => ['port' => 'Port must be between 1 and 65535.'],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        $env = [
            $prefix . 'TYPE' => 'ftp',
            $prefix . 'HOST' => $decryptedConfig['host'] ?? '',
            $prefix . 'USER' => $decryptedConfig['user'] ?? '',
            $prefix . 'PASS' => $decryptedConfig['pass'] ?? '',
            $prefix . 'PORT' => (string) ($decryptedConfig['port'] ?: '21'),
        ];

        $tls = $decryptedConfig['tls'] ?? 'false';
        if ($tls === 'true') {
            $env[$prefix . 'TLS'] = 'true';
        } elseif ($tls === 'implicit') {
            $env[$prefix . 'TLS'] = 'true';
            $env[$prefix . 'EXPLICIT_TLS'] = 'false';
        }

        return $env;
    }

    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '')
    {
        $rawPath = $decryptedConfig['path'] ?? '/';
        $isAbsolute = isset($rawPath[0]) && $rawPath[0] === '/';
        $basePath = trim($rawPath, '/');
        if ($subPath !== '') {
            $basePath = ($basePath !== '' ? $basePath . '/' : '') . ltrim($subPath, '/');
        }
        return $remoteName . ':' . ($isAbsolute ? '/' : '') . $basePath;
    }
}
