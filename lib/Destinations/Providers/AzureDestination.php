<?php
/**
 * rcloneCWP Azure Blob Destination Provider
 *
 * Microsoft Azure Blob Storage.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class AzureDestination extends AbstractDestination
{
    public function getType()
    {
        return 'azure';
    }

    public function getName()
    {
        return 'Microsoft Azure Blob Storage';
    }

    public function getRcloneType()
    {
        return 'azureblob';
    }

    public function getConfigFields()
    {
        return [
            'account' => [
                'label'       => 'Storage Account Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'mystorageaccount',
                'help'        => 'Azure storage account name.',
            ],
            'key' => [
                'label'       => 'Account Key / SAS Token',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => '••••••••',
                'help'        => 'Azure storage account access key or SAS token (encrypted with AES-256-GCM).',
            ],
            'container' => [
                'label'       => 'Blob Container Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'cwp-backups',
                'help'        => 'Azure blob container name.',
            ],
            'prefix' => [
                'label'       => 'Path Prefix (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'daily',
                'help'        => 'Sub-path inside the blob container.',
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

        $container = trim($config['container'] ?? '');
        if (preg_match('/[^a-z0-9\-]/', $container)) {
            return [
                'valid'  => false,
                'errors' => ['container' => 'Azure container names must only contain lowercase letters, numbers, and hyphens.'],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        $key = $decryptedConfig['key'] ?? '';

        $env = [
            $prefix . 'TYPE'    => 'azureblob',
            $prefix . 'ACCOUNT' => $decryptedConfig['account'] ?? '',
        ];

        // If key starts with '?' or contains 'sig=', treat as SAS URL/token
        if (strpos($key, '?') === 0 || strpos($key, 'sig=') !== false) {
            $env[$prefix . 'SAS_URL'] = $key;
        } else {
            $env[$prefix . 'KEY'] = $key;
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
