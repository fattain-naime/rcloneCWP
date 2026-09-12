<?php
/**
 * rcloneCWP SFTP Destination Provider
 *
 * Remote SSH server via SFTP.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class SftpDestination extends AbstractDestination
{
    public function getType()
    {
        return 'sftp';
    }

    public function getName()
    {
        return 'SFTP (SSH File Transfer)';
    }

    public function getRcloneType()
    {
        return 'sftp';
    }

    public function getConfigFields()
    {
        return [
            'host' => [
                'label'       => 'Hostname or IP',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'backup.example.com',
                'help'        => 'Target SFTP server address.',
            ],
            'port' => [
                'label'       => 'Port',
                'type'        => 'number',
                'required'    => false,
                'default'     => '22',
                'placeholder' => '22',
                'help'        => 'SSH/SFTP port (default 22).',
            ],
            'user' => [
                'label'       => 'Username',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'backupuser',
                'help'        => 'Remote SSH username.',
            ],
            'pass' => [
                'label'       => 'Password',
                'type'        => 'password',
                'required'    => false,
                'placeholder' => '••••••••',
                'help'        => 'Password for authentication (or leave blank if using Private Key).',
            ],
            'key_pem' => [
                'label'       => 'Private Key (PEM)',
                'type'        => 'textarea',
                'required'    => false,
                'placeholder' => "-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----",
                'help'        => 'SSH private key in PEM format (encrypted with AES-256-GCM).',
            ],
            'path' => [
                'label'       => 'Remote Path',
                'type'        => 'text',
                'required'    => false,
                'default'     => '/backups',
                'placeholder' => '/home/backupuser/cwp',
                'help'        => 'Destination path on remote server.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['pass', 'key_pem'];
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
                'errors' => ['host' => 'Hostname or IP address is required.'],
            ];
        }

        $hostCheck = \CWP\RcloneCWP\Validator::validateHostSecurity($host, false, 'host');
        if (!$hostCheck['valid']) {
            return ['valid' => false, 'errors' => ['host' => $hostCheck['error']]];
        }

        $pass = trim($config['pass'] ?? '');
        $keyPem = trim($config['key_pem'] ?? '');

        if ($pass === '' && $keyPem === '') {
            return [
                'valid'  => false,
                'errors' => ['auth' => 'Either password or SSH private key must be provided.'],
            ];
        }

        $port = (int) ($config['port'] ?? 22);
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
            $prefix . 'TYPE' => 'sftp',
            $prefix . 'HOST' => $decryptedConfig['host'] ?? '',
            $prefix . 'USER' => $decryptedConfig['user'] ?? '',
            $prefix . 'PORT' => (string) ($decryptedConfig['port'] ?: '22'),
        ];

        if (!empty($decryptedConfig['pass'])) {
            $env[$prefix . 'PASS'] = $decryptedConfig['pass'];
        }
        if (!empty($decryptedConfig['key_pem'])) {
            $env[$prefix . 'KEY_PEM'] = $decryptedConfig['key_pem'];
        }

        return $env;
    }

    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '')
    {
        $rawPath = $decryptedConfig['path'] ?? '/backups';
        $isAbsolute = isset($rawPath[0]) && $rawPath[0] === '/';
        $basePath = trim($rawPath, '/');
        if ($subPath !== '') {
            $basePath = ($basePath !== '' ? $basePath . '/' : '') . ltrim($subPath, '/');
        }
        return $remoteName . ':' . ($isAbsolute ? '/' : '') . $basePath;
    }
}
