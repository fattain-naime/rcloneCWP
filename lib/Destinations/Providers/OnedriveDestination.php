<?php
/**
 * rcloneCWP OneDrive Destination Provider
 *
 * Microsoft OneDrive and SharePoint Document Libraries.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class OnedriveDestination extends AbstractDestination
{
    public function getType()
    {
        return 'onedrive';
    }

    public function getName()
    {
        return 'Microsoft OneDrive / SharePoint';
    }

    public function getRcloneType()
    {
        return 'onedrive';
    }

    public function getConfigFields()
    {
        return [
            'token' => [
                'label'       => 'OAuth Token JSON',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => "{\"access_token\":\"...\",\"token_type\":\"Bearer\",\"refresh_token\":\"...\"}",
                'help'        => 'Microsoft OAuth token JSON (encrypted with AES-256-GCM).',
            ],
            'drive_type' => [
                'label'    => 'Drive Type',
                'type'     => 'select',
                'required' => false,
                'default'  => 'personal',
                'options'  => [
                    'personal'        => 'Personal OneDrive',
                    'business'        => 'OneDrive for Business',
                    'documentLibrary' => 'SharePoint Document Library',
                ],
                'help'     => 'Type of Microsoft 365 storage account.',
            ],
            'drive_id' => [
                'label'       => 'Drive ID (optional)',
                'type'        => 'text',
                'required'    => false,
                'placeholder' => 'b!12345...',
                'help'        => 'Specific Drive ID for business/SharePoint accounts.',
            ],
            'path' => [
                'label'       => 'Target Directory Path',
                'type'        => 'text',
                'required'    => false,
                'default'     => 'cwp-backups',
                'placeholder' => 'cwp-backups',
                'help'        => 'Folder name or path inside OneDrive.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['token'];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        $env = [
            $prefix . 'TYPE'       => 'onedrive',
            $prefix . 'TOKEN'      => $decryptedConfig['token'] ?? '',
            $prefix . 'DRIVE_TYPE' => $decryptedConfig['drive_type'] ?? 'personal',
        ];

        if (!empty($decryptedConfig['drive_id'])) {
            $env[$prefix . 'DRIVE_ID'] = $decryptedConfig['drive_id'];
        }

        return $env;
    }

    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '')
    {
        $basePath = trim($decryptedConfig['path'] ?? 'cwp-backups', '/');
        if ($subPath !== '') {
            $basePath = ($basePath !== '' ? $basePath . '/' : '') . ltrim($subPath, '/');
        }
        return $remoteName . ':' . $basePath;
    }
}
