<?php
/**
 * rcloneCWP CSRF Protection
 *
 * Session-bound synchronizer token pattern with timestamp-based expiration
 * PSR-12 compliant, PHP 7.1+ compatible
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

class CSRF
{
    /**
     * Session key for CSRF token
     */
    const SESSION_KEY = 'rclone_csrf_token';

    /**
     * Maximum token age in seconds (24 hours)
     */
    const TOKEN_MAX_AGE = 86400;

    /**
     * Generate a CSRF token with timestamp
     *
     * @return string The generated token (hex, 64 chars)
     */
    public static function generateToken()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = [
            'token' => $token,
            'created' => time(),
        ];

        return $token;
    }

    /**
     * Validate a CSRF token against the session token
     *
     * @param string $token Token to validate
     * @return bool True if token matches the session token and is not expired
     */
    public static function validateToken($token)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionData = $_SESSION[self::SESSION_KEY] ?? null;
        if (empty($sessionData)) {
            return false;
        }

        // Support both old format (string) and new format (array with timestamp)
        if (is_string($sessionData)) {
            // Legacy format - just compare tokens
            return hash_equals($sessionData, $token);
        }

        if (!is_array($sessionData) || !isset($sessionData['token'], $sessionData['created'])) {
            return false;
        }

        if (!is_string($token) || strlen($token) === 0) {
            return false;
        }

        // Check token age
        $age = time() - $sessionData['created'];
        if ($age > self::TOKEN_MAX_AGE) {
            // Token expired - remove it
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }

        return hash_equals($sessionData['token'], $token);
    }

    /**
     * Validate and consume a CSRF token (one-time use)
     *
     * @param string $token Token to validate and consume
     * @return bool True if token was valid and is now consumed
     */
    public static function validateAndConsumeToken($token)
    {
        if (self::validateToken($token)) {
            unset($_SESSION[self::SESSION_KEY]);
            return true;
        }
        return false;
    }
}
