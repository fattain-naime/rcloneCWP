<?php
/**
 * rcloneCWP Tencent Cloud COS Destination Provider
 *
 * Tencent Cloud Object Storage (COS).
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class TencentDestination extends AbstractDestination
{
    public function getType()
    {
        return 'tencent';
    }

    public function getName()
    {
        return 'Tencent Cloud Object Storage (COS)';
    }

    public function getRcloneType()
    {
        return 'cos';
    }

    public function getConfigFields()
    {
        return [
            'app_id' => [
                'label'       => 'App ID',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => '1250000000',
                'help'        => 'Tencent Cloud account APPID.',
            ],
            'secret_id' => [
                'label'       => 'Secret ID',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'APPID-SECRET-ID-XXXX',
                'help'        => 'Tencent Cloud SecretId.',
            ],
            'secret_key' => [
                'label'       => 'Secret Key',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => '••••••••',
                'help'        => 'Tencent Cloud SecretKey (encrypted with AES-256-GCM).',
            ],
            'region' => [
                'label'       => 'Region',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'ap-singapore',
                'help'        => 'Tencent COS region (e.g. ap-guangzhou, ap-singapore, na-siliconvalley).',
            ],
            'bucket' => [
                'label'       => 'Bucket Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'cwp-backups-1250000000',
                'help'        => 'Tencent COS bucket name (typically name-APPID).',
            ],
            'prefix' => [
                'label'       => 'Path Prefix (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'backups',
                'help'        => 'Sub-folder inside bucket.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['secret_key'];
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
                'errors' => ['bucket' => 'Bucket name contains invalid characters.'],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        return [
            $prefix . 'TYPE'       => 'cos',
            $prefix . 'APP_ID'     => $decryptedConfig['app_id'] ?? '',
            $prefix . 'SECRET_ID'  => $decryptedConfig['secret_id'] ?? '',
            $prefix . 'SECRET_KEY' => $decryptedConfig['secret_key'] ?? '',
            $prefix . 'REGION'     => $decryptedConfig['region'] ?? '',
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
