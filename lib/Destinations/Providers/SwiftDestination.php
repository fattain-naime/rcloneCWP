<?php
/**
 * rcloneCWP OpenStack Swift Destination Provider
 *
 * OpenStack Swift Object Storage.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class SwiftDestination extends AbstractDestination
{
    public function getType()
    {
        return 'swift';
    }

    public function getName()
    {
        return 'OpenStack Swift Object Storage';
    }

    public function getRcloneType()
    {
        return 'swift';
    }

    public function getConfigFields()
    {
        return [
            'user' => [
                'label'       => 'User Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'swiftuser',
                'help'        => 'OpenStack Swift username.',
            ],
            'key' => [
                'label'       => 'API Key / Password',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => '••••••••',
                'help'        => 'API key or password (encrypted with AES-256-GCM).',
            ],
            'auth' => [
                'label'       => 'Auth URL',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'https://auth.example.com/v3',
                'help'        => 'OpenStack Identity (Keystone) authentication endpoint.',
            ],
            'tenant' => [
                'label'       => 'Tenant / Project Name (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'admin_project',
                'help'        => 'Tenant name or project ID.',
            ],
            'region' => [
                'label'       => 'Region (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'RegionOne',
                'help'        => 'Endpoint region.',
            ],
            'container' => [
                'label'       => 'Container Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'cwp-backups',
                'help'        => 'Target Swift container name.',
            ],
            'prefix' => [
                'label'       => 'Path Prefix (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'cwp-data',
                'help'        => 'Sub-folder inside the container.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['key'];
    }

    public function validateConfig(array $config)
    {
        $base = parent::validateConfig($config);
        if (!$base['valid']) {
            return $base;
        }

        $auth = trim($config['auth'] ?? '');
        $urlCheck = \CWP\RcloneCWP\Validator::validateUrlSecurity($auth, false, 'Auth URL');
        if (!$urlCheck['valid']) {
            return [
                'valid'  => false,
                'errors' => ['auth' => $urlCheck['error']],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        $env = [
            $prefix . 'TYPE' => 'swift',
            $prefix . 'USER' => $decryptedConfig['user'] ?? '',
            $prefix . 'KEY'  => $decryptedConfig['key'] ?? '',
            $prefix . 'AUTH' => $decryptedConfig['auth'] ?? '',
        ];

        if (!empty($decryptedConfig['tenant'])) {
            $env[$prefix . 'TENANT'] = $decryptedConfig['tenant'];
        }
        if (!empty($decryptedConfig['region'])) {
            $env[$prefix . 'REGION'] = $decryptedConfig['region'];
        }

        return $env;
    }

    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '')
    {
        $target = $remoteName . ':' . trim($decryptedConfig['container'] ?? '');
        $prefix = trim($decryptedConfig['prefix'] ?? '', '/');
        if ($prefix !== '') {
            $target .= '/' . $prefix;
        }
        if ($subPath !== '') {
            $target .= '/' . ltrim($subPath, '/');
        }
        return $target;
    }
}
