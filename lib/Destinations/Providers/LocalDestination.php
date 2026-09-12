<?php
/**
 * rcloneCWP Local Destination Provider
 *
 * Local directory, secondary hard disk, or mounted NFS/CIFS share.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations\Providers
 */

namespace CWP\RcloneCWP\Destinations\Providers;

use CWP\RcloneCWP\Destinations\AbstractDestination;
use CWP\RcloneCWP\Rclone;

class LocalDestination extends AbstractDestination
{
    public function getType()
    {
        return 'local';
    }

    public function getName()
    {
        return 'Local Storage / Mount';
    }

    public function getRcloneType()
    {
        return 'local';
    }

    public function getConfigFields()
    {
        return [
            'path' => [
                'label'       => 'Local Directory Path',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => '/backup/rcloneCWP',
                'help'        => 'Absolute path on the server filesystem or mounted volume.',
            ],
        ];
    }

    public function getSensitiveFields()
    {
        return [];
    }

    public function validateConfig(array $config)
    {
        $base = parent::validateConfig($config);
        if (!$base['valid']) {
            return $base;
        }

        $path = trim($config['path'] ?? '');
        if ($path === '' || $path[0] !== '/') {
            return [
                'valid'  => false,
                'errors' => ['path' => 'Path must be an absolute path starting with "/"'],
            ];
        }

        // Reject directory traversal
        if (strpos($path, "..") !== false) {
            return [
                "valid"  => false,
                "errors" => ["path" => "Directory traversal (..) is not permitted."],
            ];
        }

        $cleanPath = "/" . trim(preg_replace("#/+#", "/", $path), "/");
        if ($cleanPath === "/") {
            return [
                "valid"  => false,
                "errors" => ["path" => "Path cannot be the root filesystem (/)."],
            ];
        }

        $forbiddenPrefixes = [
            "/root", "/bin", "/sbin", "/usr", "/etc",
            "/boot", "/sys", "/proc", "/dev", "/usr/local/cwp"
        ];

        foreach ($forbiddenPrefixes as $bad) {
            if ($cleanPath === $bad || strpos($cleanPath, $bad . "/") === 0) {
                return [
                    "valid"  => false,
                    "errors" => ["path" => "Path resolves to a forbidden system directory: " . $cleanPath],
                ];
            }
        }

        // Check resolved realpath if path exists to prevent symlink traversal
        if (file_exists($path)) {
            $realPath = realpath($path);
            if ($realPath === false || $realPath === "/") {
                return [
                    "valid"  => false,
                    "errors" => ["path" => "Path cannot be resolved or is root."],
                ];
            }
            foreach ($forbiddenPrefixes as $bad) {
                if ($realPath === $bad || strpos($realPath, $bad . "/") === 0) {
                    return [
                        "valid"  => false,
                        "errors" => ["path" => "Path symlink resolves to a forbidden directory: " . $realPath],
                    ];
                }
            }
        }

        return ["valid" => true, "errors" => []];
    }

    public function getRcloneEnv(array $decryptedConfig, $remoteName)
    {
        $prefix = 'RCLONE_CONFIG_' . strtoupper($remoteName) . '_';
        return [
            $prefix . 'TYPE' => 'local',
        ];
    }

    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '')
    {
        $basePath = rtrim($decryptedConfig['path'] ?? '', '/');
        if ($subPath !== '') {
            $basePath .= '/' . ltrim($subPath, '/');
        }
        return $remoteName . ':' . $basePath;
    }

    public function testConnection(array $decryptedConfig)
    {
        $val = $this->validateConfig($decryptedConfig);
        if (!$val['valid']) {
            return ['ok' => false, 'message' => implode(', ', $val['errors']), 'details' => $val['errors']];
        }

        $path = $decryptedConfig['path'];

        // Auto-create directory if it doesn't exist (mode 0700)
        if (!is_dir($path)) {
            if (!@mkdir($path, 0700, true) && !is_dir($path)) {
                return [
                    'ok'      => false,
                    'message' => "Directory does not exist and could not be created: {$path}",
                    'details' => ['path' => $path],
                ];
            }
        }

        if (!is_readable($path)) {
            return [
                'ok'      => false,
                'message' => "Directory is not readable: {$path}",
                'details' => ['path' => $path],
            ];
        }

        if (!is_writable($path)) {
            return [
                'ok'      => false,
                'message' => "Directory is not writable: {$path}",
                'details' => ['path' => $path],
            ];
        }

        // Test rclone execution
        return parent::testConnection($decryptedConfig);
    }
}
