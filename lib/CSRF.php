<?php
/**
 * rcloneCWP CSRF Protection
 *
 * Session-bound synchronizer token pattern
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
     * Generate a CSRF token
     *
     * @return string The generated token (hex, 64 chars)
     */
    public static function generateToken()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    /**
     * Validate a CSRF token against the session token
     *
     * @param string $token Token to validate
     * @return bool True if token matches the session token
     */
    public static function validateToken($token)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        if (!is_string($token) || strlen($token) === 0) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
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
