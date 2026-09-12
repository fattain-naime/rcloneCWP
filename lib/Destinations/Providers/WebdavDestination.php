<?php
/**
 * rcloneCWP WebDAV Destination Provider
 *
 * Nextcloud, ownCloud, SharePoint, and generic WebDAV servers.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;

class WebdavDestination extends AbstractDestination
{
    public function getType()
    {
        return 'webdav';
    }

    public function getName()
    {
        return 'WebDAV (Nextcloud, ownCloud, Generic)';
    }

    public function getRcloneType()
    {
        return 'webdav';
    }

    public function getConfigFields()
    {
        return [
            'url' => [
                'label'       => 'WebDAV Server URL',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'https://nextcloud.example.com/remote.php/dav/files/admin/',
                'help'        => 'Full base URL of the WebDAV endpoint.',
            ],
            'vendor' => [
                'label'    => 'Vendor / Platform',
                'type'     => 'select',
                'required' => true,
                'default'  => 'nextcloud',
                'options'  => [
                    'nextcloud'  => 'Nextcloud',
                    'owncloud'   => 'ownCloud',
                    'sharepoint' => 'SharePoint',
                    'other'      => 'Other / Generic WebDAV',
                ],
                'help'     => 'Vendor-specific protocol optimizations.',
            ],
            'user' => [
                'label'       => 'Username',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'webdavuser',
                'help'        => 'WebDAV account username.',
            ],
            'pass' => [
                'label'       => 'Password / App Token',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => '••••••••',
                'help'        => 'Password or Nextcloud App Token (encrypted with AES-256-GCM).',
            ],
            'path' => [
                'label'       => 'Remote Sub-directory',
                'type'        => 'text',
                'required'    => false,
                'default'     => 'backups',
                'placeholder' => 'cwp-backups',
                'help'        => 'Target directory within WebDAV store.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return ['pass'];
    }

    public function validateConfig(array $config)
    {
        if (empty($config['vendor'])) {
            $config['vendor'] = 'nextcloud';
        }

        $base = parent::validateConfig($config);
        if (!$base['valid']) {
            return $base;
        }

        $url = trim($config['url'] ?? '');
        $urlCheck = \CWP\RcloneCWP\Validator::validateUrlSecurity($url, false, 'WebDAV URL');
        if (!$urlCheck['valid']) {
            return [
                'valid'  => false,
                'errors' => ['url' => $urlCheck['error']],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        return [
            $prefix . 'TYPE'   => 'webdav',
            $prefix . 'URL'    => $decryptedConfig['url'] ?? '',
            $prefix . 'VENDOR' => $decryptedConfig['vendor'] ?? 'other',
            $prefix . 'USER'   => $decryptedConfig['user'] ?? '',
            $prefix . 'PASS'   => $decryptedConfig['pass'] ?? '',
        ];
    }

    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '')
    {
        $basePath = trim($decryptedConfig['path'] ?? 'backups', '/');
        if ($subPath !== '') {
            $basePath = ($basePath !== '' ? $basePath . '/' : '') . ltrim($subPath, '/');
        }
        return $remoteName . ':' . $basePath;
    }
}
