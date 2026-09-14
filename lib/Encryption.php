<?php
/**
 * rcloneCWP Encryption
 *
 * AES-256-GCM encryption for credentials at rest
 * PSR-12 compliant, PHP 7.1+ compatible (uses openssl, not sodium)
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

class Encryption
{
    private $key;
    private $keyFile;

    /**
     * Constructor
     *
     * @param string|null $key Encryption key (optional, uses file if not provided)
     * @param string|null $keyFile Key file path (optional)
     */
    public function __construct($key = null, $keyFile = null)
    {
        $this->keyFile = $keyFile ?: RCLONE_KEYFILE;

        if ($key === null) {
            $this->key = $this->loadKeyFromFile();
        } else {
            $this->key = $key;
        }
    }

    /**
     * Load encryption key from file
     *
     * @return string
     * @throws \RuntimeException if key file not found or invalid
     */
    private function loadKeyFromFile()
    {
        if (!is_file($this->keyFile)) {
            throw new \RuntimeException('Encryption key file not found: ' . $this->keyFile);
        }

        $key = file_get_contents($this->keyFile);
        if (strlen($key) !== 32) {
            throw new \RuntimeException('Invalid encryption key length. Expected 32 bytes.');
        }

        return $key;
    }

    /**
     * Create a new encryption key and save to file
     *
     * @param string $keyFile Key file path (optional, uses default)
     * @return string Generated key
     * @throws \RuntimeException if key cannot be created or saved
     */
    public static function generateKey($keyFile = null)
    {
        $keyFile = $keyFile ?: RCLONE_KEYFILE;

        // Generate random key
        $key = random_bytes(32);

        // Ensure directory exists
        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // Save key with restrictive permissions
        if (file_put_contents($keyFile, $key, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write encryption key to file: ' . $keyFile);
        }

        // Set restrictive permissions
        @chmod($keyFile, 0600);

        return $key;
    }

    /**
     * Encrypt data using AES-256-GCM
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data (base64-wrapped: nonce(12) || tag(16) || ciphertext)
     * @throws \RuntimeException if encryption fails
     */
    public function encrypt($data)
    {
        if (empty($this->key)) {
            throw new \RuntimeException('Encryption key not available');
        }

        $ivLength = 12; // GCM recommended IV length

        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $data,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Combine: IV + Tag + Ciphertext
        $combined = $iv . $tag . $ciphertext;

        return base64_encode($combined);
    }

    /**
     * Decrypt data using AES-256-GCM
     *
     * @param string $encryptedData Base64-wrapped encrypted data
     * @return string Decrypted data
     * @throws \RuntimeException if decryption fails or data is invalid
     */
    public function decrypt($encryptedData)
    {
        if (empty($this->key)) {
            throw new \RuntimeException('Encryption key not available');
        }

        if (!is_string($encryptedData)) {
            throw new \RuntimeException('Encrypted data must be a string');
        }

        $data = base64_decode($encryptedData, true);
        if ($data === false || strlen($data) < 28) { // 12 + 16 + minimum ciphertext
            throw new \RuntimeException('Invalid encrypted data format');
        }

        $ivLength = 12;
        $tagLength = 16;

        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: authentication tag mismatch or data corrupted');
        }

        return $plaintext;
    }

    /**
     * Get key file path
     *
     * @return string
     */
    public function getKeyFile()
    {
        return $this->keyFile;
    }

    /**
     * Check if key file exists
     *
     * @return bool
     */
    public static function hasKeyFile($keyFile = null)
    {
        $keyFile = $keyFile ?: RCLONE_KEYFILE;
        return is_file($keyFile) && is_readable($keyFile);
    }
}
