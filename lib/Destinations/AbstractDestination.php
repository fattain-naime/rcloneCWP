<?php
/**
 * rcloneCWP Abstract Destination Base Class
 *
 * Implements common validation, AES-256-GCM encryption/decryption,
 * and rclone execution integration.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations
 */

namespace CWP\RcloneCWP\Destinations;

use CWP\RcloneCWP\Encryption;
use CWP\RcloneCWP\Rclone;
use CWP\RcloneCWP\Validator;

abstract class AbstractDestination implements DestinationInterface
{
    /**
     * @var Encryption
     */
    protected $encryption;

    /**
     * Constructor
     *
     * @param Encryption|null $encryption
     */
    public function __construct(Encryption $encryption = null)
    {
        $this->encryption = $encryption ?: new Encryption();
    }

    /**
     * Default validation: checks required fields from getConfigFields()
     *
     * @param array $config
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateConfig(array $config)
    {
        $errors = [];
        $fields = $this->getConfigFields();

        foreach ($fields as $key => $meta) {
            $required = !empty($meta['required']);
            $val = isset($config[$key]) ? $config[$key] : null;

            if ($required && ($val === null || $val === '')) {
                $errors[$key] = ($meta['label'] ?? $key) . ' is required';
                continue;
            }

            if ($val !== null && $val !== '') {
                $type = $meta['type'] ?? 'text';
                if ($type === 'number' && !is_numeric($val)) {
                    $errors[$key] = ($meta['label'] ?? $key) . ' must be a valid number';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Encrypt sensitive configuration values before database write.
     *
     * @param array $config
     * @return array
     */
    public function encryptConfig(array $config)
    {
        $encrypted = $config;
        $sensitive = $this->getSensitiveFields();

        foreach ($sensitive as $key) {
            if (isset($encrypted[$key]) && is_string($encrypted[$key]) && $encrypted[$key] !== '') {
                // Don't re-encrypt if already encrypted or placeholder
                if ($encrypted[$key] === '••••••••') {
                    continue;
                }
                $encrypted[$key] = $this->encryption->encrypt($encrypted[$key]);
            }
        }

        return $encrypted;
    }

    /**
     * Decrypt sensitive configuration values for in-memory use.
     *
     * @param array $config
     * @return array
     */
    public function decryptConfig(array $config)
    {
        $decrypted = $config;
        $sensitive = $this->getSensitiveFields();

        foreach ($sensitive as $key) {
            if (isset($decrypted[$key]) && is_string($decrypted[$key]) && $decrypted[$key] !== '') {
                $plain = $this->encryption->decrypt($decrypted[$key]);
                if ($plain !== false) {
                    $decrypted[$key] = $plain;
                }
            }
        }

        return $decrypted;
    }

    /**
     * Default connection test: probe target with rclone lsjson (max-depth 1)
     *
     * @param array $decryptedConfig
     * @return array ['ok' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection(array $decryptedConfig)
    {
        $validation = $this->validateConfig($decryptedConfig);
        if (!$validation['valid']) {
            return [
                'ok' => false,
                'message' => 'Configuration validation failed: ' . implode(', ', $validation['errors']),
                'details' => $validation['errors'],
            ];
        }

        // Generate transient remote name for testing
        $remoteName = 'test_' . strtolower($this->getType()) . '_' . substr(md5(uniqid('', true)), 0, 8);
        $env = $this->getRcloneEnv($decryptedConfig, $remoteName);
        $target = $this->getRemoteTarget($decryptedConfig, $remoteName);

        try {
            $result = Rclone::execute('lsjson', [$target], [], [], 15, $env);

            if ($result['exit'] === 0) {
                return [
                    'ok' => true,
                    'message' => 'Connection successful. Remote storage is accessible.',
                    'details' => [
                        'remote' => $remoteName,
                        'target' => $target,
                        'output' => $result['stdout'] ?? '',
                    ],
                ];
            }

            $errMsg = trim($result['stderr']);
            if ($errMsg === '') {
                $errMsg = trim($result['stdout']);
            }
            if ($errMsg === '') {
                $errMsg = 'rclone process exited with code ' . $result['exit'];
            }

            // Clean any sensitive tokens from error message
            $errMsg = preg_replace('/\b[A-Za-z0-9\/+=]{30,}\b/', '[REDACTED]', $errMsg);

            return [
                'ok' => false,
                'message' => 'Connection test failed: ' . $errMsg,
                'details' => [
                    'exit' => $result['exit'],
                    'error' => $errMsg,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'message' => 'Test error: ' . $e->getMessage(),
                'details' => ['exception' => get_class($e)],
            ];
        }
    }
}
