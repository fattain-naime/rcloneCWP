<?php
/**
 * rcloneCWP Destination Interface
 *
 * Contract for all storage destination providers.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations
 */

namespace CWP\RcloneCWP\Destinations;

interface DestinationInterface
{
    /**
     * Provider type key matching rclone_destinations.type enum
     * (e.g. 'local', 's3', 'sftp')
     *
     * @return string
     */
    public function getType();

    /**
     * Human-readable display name (e.g. 'Amazon S3 & Compatible')
     *
     * @return string
     */
    public function getName();

    /**
     * Internal rclone backend type name (e.g. 's3', 'local', 'sftp')
     *
     * @return string
     */
    public function getRcloneType();

    /**
     * Field schema definitions for configuration and UI forms.
     *
     * @return array Associative array of field definitions
     */
    public function getConfigFields();

    /**
     * Sensitive field keys that must be encrypted at rest with AES-256-GCM.
     *
     * @return array List of string keys
     */
    public function getSensitiveFields();

    /**
     * Validate provider configuration values.
     *
     * @param array $config Key-value configuration
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateConfig(array $config);

    /**
     * Encrypt sensitive fields using Encryption service.
     *
     * @param array $config Decrypted config
     * @return array Config with sensitive fields encrypted (base64 ciphertext)
     */
    public function encryptConfig(array $config);

    /**
     * Decrypt sensitive fields using Encryption service.
     *
     * @param array $config Encrypted config from DB
     * @return array Config with sensitive fields decrypted to plaintext
     */
    public function decryptConfig(array $config);

    /**
     * Generate RCLONE_CONFIG_* environment variables for in-memory proc_open execution.
     *
     * @param array $decryptedConfig Decrypted configuration values
     * @param string $remoteName Unique remote identifier
     * @return array Associative array of environment variables
     */
    public function getRcloneEnv(array $decryptedConfig, $remoteName);

    /**
     * Get the rclone remote target string (e.g. "myremote:bucket/path" or "localpath").
     *
     * @param array $decryptedConfig Decrypted configuration values
     * @param string $remoteName Unique remote identifier
     * @param string $subPath Optional sub-path inside the remote
     * @return string
     */
    public function getRemoteTarget(array $decryptedConfig, $remoteName, $subPath = '');

    /**
     * Test connection and path/bucket accessibility.
     *
     * @param array $decryptedConfig Decrypted configuration values
     * @return array ['ok' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection(array $decryptedConfig);
}
