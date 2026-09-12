<?php
/**
 * rcloneCWP Google Cloud Storage Destination Provider
 *
 * Google Cloud Storage (GCS) via Service Account JSON.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class GcsDestination extends AbstractDestination
{
    public function getType()
    {
        return 'gs';
    }

    public function getName()
    {
        return 'Google Cloud Storage (GCS)';
    }

    public function getRcloneType()
    {
        return 'google cloud storage';
    }

    public function getConfigFields()
    {
        return [
            'bucket' => [
                'label'       => 'Bucket Name',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'my-gcs-backup-bucket',
                'help'        => 'Google Cloud Storage bucket name.',
            ],
            'service_account_credentials' => [
                'label'       => 'Service Account JSON Credentials',
                'type'        => 'textarea',
                'required'    => true,
                'placeholder' => "{\n  \"type\": \"service_account\",\n  \"project_id\": \"...\",\n  \"private_key\": \"...\"\n}",
                'help'        => 'Full JSON key file content for your GCP Service Account (encrypted with AES-256-GCM).',
            ],
            'project_number' => [
                'label'       => 'Project Number / ID',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'my-gcp-project-12345',
                'help'        => 'Optional GCP Project ID.',
            ],
            'prefix' => [
                'label'       => 'Path Prefix (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'cwp-backups',
                'help'        => 'Sub-folder path inside the bucket.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['service_account_credentials'];
    }

    public function validateConfig(array $config)
    {
        $base = parent::validateConfig($config);
        if (!$base['valid']) {
            return $base;
        }

        $jsonStr = trim($config['service_account_credentials'] ?? '');
        $decoded = json_decode($jsonStr, true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return [
                'valid'  => false,
                'errors' => ['service_account_credentials' => 'Service Account JSON must be valid JSON containing client_email and private_key.'],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        $env = [
            $prefix . 'TYPE'                        => 'google cloud storage',
            $prefix . 'SERVICE_ACCOUNT_CREDENTIALS' => $decryptedConfig['service_account_credentials'] ?? '',
        ];

        if (!empty($decryptedConfig['project_number'])) {
            $env[$prefix . 'PROJECT_NUMBER'] = $decryptedConfig['project_number'];
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
