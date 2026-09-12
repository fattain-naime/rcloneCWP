<?php
/**
 * rcloneCWP S3 Destination Provider
 *
 * Amazon S3, Wasabi, Cloudflare R2, MinIO, DigitalOcean Spaces, Backblaze S3.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class S3Destination extends AbstractDestination
{
    public function getType()
    {
        return 's3';
    }

    public function getName()
    {
        return 'Amazon S3 & Compatible (Wasabi, R2, MinIO, Spaces)';
    }

    public function getRcloneType()
    {
        return 's3';
    }

    public function getConfigFields()
    {
        return [
            'provider' => [
                'label'    => 'S3 Provider',
                'type'     => 'select',
                'required' => true,
                'default'  => 'AWS',
                'options'  => [
                    'AWS'          => 'Amazon Web Services (AWS) S3',
                    'Wasabi'       => 'Wasabi Cloud Storage',
                    'Cloudflare'   => 'Cloudflare R2',
                    'DigitalOcean' => 'DigitalOcean Spaces',
                    'Minio'        => 'MinIO Object Storage',
                    'Ceph'         => 'Ceph Object Gateway',
                    'Other'        => 'Other S3 Compatible Storage',
                ],
                'help'     => 'Select your S3 cloud or on-premise provider.',
            ],
            'access_key_id' => [
                'label'       => 'Access Key ID',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'AKIAIOSFODNN7EXAMPLE',
                'help'        => 'S3 API access key.',
            ],
            'secret_access_key' => [
                'label'       => 'Secret Access Key',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'help'        => 'S3 API secret key (encrypted with AES-256-GCM).',
            ],
            'region' => [
                'label'       => 'Region',
                'type'        => 'text',
                'required'    => false,
                'default'     => 'us-east-1',
                'placeholder' => 'us-east-1',
                'help'        => 'Bucket region (e.g. us-east-1, eu-central-1, auto for R2).',
            ],
            'endpoint' => [
                'label'       => 'Custom Endpoint',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'https://s3.wasabisys.com',
                'help'        => 'Required for Wasabi, MinIO, Cloudflare R2, DigitalOcean Spaces.',
            ],
            'bucket' => [
                'label'       => 'Bucket Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'my-cwp-backups',
                'help'        => 'Target bucket name.',
            ],
            'prefix' => [
                'label'       => 'Path Prefix (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'cwp-backups',
                'help'        => 'Sub-folder inside the bucket.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['secret_access_key'];
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

        $provider = $config['provider'] ?? 'AWS';
        $endpoint = trim($config['endpoint'] ?? '');
        if (in_array($provider, ['Wasabi', 'Cloudflare', 'DigitalOcean', 'Minio'], true) && $endpoint === '') {
            return [
                'valid'  => false,
                'errors' => ['endpoint' => 'Endpoint URL is required for ' . $provider],
            ];
        }

        if ($endpoint !== '') {
            $urlCheck = \CWP\RcloneCWP\Validator::validateUrlSecurity($endpoint, false, 'S3 Endpoint');
            if (!$urlCheck['valid']) {
                return [
                    'valid'  => false,
                    'errors' => ['endpoint' => $urlCheck['error']],
                ];
            }
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        $env = [
            $prefix . 'TYPE'              => 's3',
            $prefix . 'PROVIDER'          => $decryptedConfig['provider'] ?? 'AWS',
            $prefix . 'ACCESS_KEY_ID'     => $decryptedConfig['access_key_id'] ?? '',
            $prefix . 'SECRET_ACCESS_KEY' => $decryptedConfig['secret_access_key'] ?? '',
        ];

        if (!empty($decryptedConfig['region'])) {
            $env[$prefix . 'REGION'] = $decryptedConfig['region'];
        }
        if (!empty($decryptedConfig['endpoint'])) {
            $env[$prefix . 'ENDPOINT'] = $decryptedConfig['endpoint'];
        }

        return $env;
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
