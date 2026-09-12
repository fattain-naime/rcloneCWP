<?php
/**
 * rcloneCWP Backblaze B2 Destination Provider
 *
 * Backblaze B2 Cloud Object Storage.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class B2Destination extends AbstractDestination
{
    public function getType()
    {
        return 'b2';
    }

    public function getName()
    {
        return 'Backblaze B2 Cloud Storage';
    }

    public function getRcloneType()
    {
        return 'b2';
    }

    public function getConfigFields()
    {
        return [
            'account' => [
                'label'       => 'Key ID / Account ID',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => '004b...000000001',
                'help'        => 'Backblaze B2 Application Key ID (or master Account ID).',
            ],
            'key' => [
                'label'       => 'Application Key',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => '••••••••',
                'help'        => 'Backblaze B2 Application Key (encrypted with AES-256-GCM).',
            ],
            'bucket' => [
                'label'       => 'Bucket Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'cwp-b2-backups',
                'help'        => 'Backblaze B2 bucket name.',
            ],
            'prefix' => [
                'label'       => 'Path Prefix (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'daily-backups',
                'help'        => 'Sub-folder inside the B2 bucket.',
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

        $bucket = trim($config['bucket'] ?? '');
        if (preg_match('/[^a-zA-Z0-9.\-_]/', $bucket)) {
            return [
                'valid'  => false,
                'errors' => ['bucket' => 'B2 bucket name contains invalid characters.'],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        return [
            $prefix . 'TYPE'    => 'b2',
            $prefix . 'ACCOUNT' => $decryptedConfig['account'] ?? '',
            $prefix . 'KEY'     => $decryptedConfig['key'] ?? '',
        ];
    }

    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '')
    {
        $target = $remoteName . ':' . trim($decryptedConfig['bucket'] ?? '');
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
