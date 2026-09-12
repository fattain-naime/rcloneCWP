<?php
/**
 * rcloneCWP Dropbox Destination Provider
 *
 * Dropbox Cloud Storage via API / OAuth token.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class DropboxDestination extends AbstractDestination
{
    public function getType()
    {
        return 'dropbox';
    }

    public function getName()
    {
        return 'Dropbox Cloud Storage';
    }

    public function getRcloneType()
    {
        return 'dropbox';
    }

    public function getConfigFields()
    {
        return [
            'token' => [
                'label'       => 'Dropbox OAuth Token (JSON or Access Token)',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => 'sl.u.AF...',
                'help'        => 'Dropbox API token or OAuth token JSON (encrypted with AES-256-GCM).',
            ],
            'path' => [
                'label'       => 'Target Directory Path',
                'type'        => 'text',
                'required'    => false,
                'default'     => 'cwp-backups',
                'placeholder' => 'cwp-backups',
                'help'        => 'Folder name or path inside Dropbox.',
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
        $token = $decryptedConfig['token'] ?? '';

        // If raw access token (not JSON), format as token JSON structure expected by rclone
        if (strpos($token, '{') === false) {
            $token = json_encode(['access_token' => $token, 'token_type' => 'bearer']);
        }

        return [
            $prefix . 'TYPE'  => 'dropbox',
            $prefix . 'TOKEN' => $token,
        ];
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
