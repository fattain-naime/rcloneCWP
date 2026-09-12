<?php
/**
 * rcloneCWP Validator
 *
 * Input validation utilities for PHP 7.1+
 * PSR-12 compliant
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

class Validator
{
    /**
     * Validate string value
     *
     * @param mixed $value Value to validate
     * @param int $maxLength Maximum length
     * @param string $fieldName Field name for error messages
     * @return string|null Sanitized value or null if invalid
     */
    public static function string($value, $maxLength = 65535, $fieldName = 'value')
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (strlen($value) === 0) {
            return null;
        }

        if (strlen($value) > $maxLength) {
            return null;
        }

        return $value;
    }

    /**
     * Validate integer value
     *
     * @param mixed $value Value to validate
     * @param int|null $min Minimum value (inclusive)
     * @param int|null $max Maximum value (inclusive)
     * @param string $fieldName Field name for error messages
     * @return int|null Validated integer or null if invalid
     */
    public static function int($value, $min = null, $max = null, $fieldName = 'value')
    {
        if (!is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        if ($min !== null && $intValue < $min) {
            return null;
        }

        if ($max !== null && $intValue > $max) {
            return null;
        }

        return $intValue;
    }

    /**
     * Validate enum value
     *
     * @param mixed $value Value to validate
     * @param array $allowedValues Allowed values
     * @param string $fieldName Field name for error messages
     * @return string|null Validated enum value or null if invalid
     */
    public static function enum($value, array $allowedValues, $fieldName = 'value')
    {
        if (!in_array($value, $allowedValues, true)) {
            return null;
        }
        return $value;
    }

    /**
     * Validate remote name (rejects names starting with '-')
     *
     * @param string $name Remote name to validate
     * @param string $fieldName Field name for error messages
     * @return string|null Validated name or null if invalid
     */
    public static function remoteName($name, $fieldName = 'name')
    {
        if (!is_string($name) || strlen($name) === 0) {
            return null;
        }

        if (strlen($name) > 255) {
            return null;
        }

        if (strpos($name, '-') === 0) {
            return null;
        }

        // Basic sanitization
        return preg_replace('/[^a-zA-Z0-9_\-.]/', '', $name);
    }

    /**
     * Validate boolean
     *
     * @param mixed $value Value to validate
     * @param bool $default Default value if invalid
     * @return bool
     */
    public static function bool($value, $default = false)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            return strtolower($value) === 'true' ||
                   strtolower($value) === '1' ||
                   strtolower($value) === 'yes';
        }

        return $default;
    }

    /**
     * Validate email address
     *
     * @param string $email Email to validate
     * @return string|null Validated email or null if invalid
     */
    public static function email($email)
    {
        if (!is_string($email) || strlen($email) === 0 || strlen($email) > 254) {
            return null;
        }

        $email = trim($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Validate date (ISO 8601 format)
     *
     * @param string $date Date string
     * @return string|null Validated date or null if invalid
     */
    public static function date($date)
    {
        if (!is_string($date) || strlen($date) === 0) {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false || $timestamp === -1) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Validate cron expression
     *
     * @param string $cron Cron expression
     * @return string|null Validated cron or null if invalid
     */
    public static function cron($cron)
    {
        if (!is_string($cron) || strlen($cron) === 0) {
            return null;
        }

        $parts = explode(' ', $cron);

        if (count($parts) !== 5) {
            return null;
        }

        // Validate each part
        foreach ($parts as $part) {
            if (strpos($part, '*') !== false) {
                continue;
            }

            $values = explode(',', $part);
            foreach ($values as $value) {
                $value = trim($value);

                // Check for ranges like "1-5" or */3
                if (strpos($value, '-') !== false) {
                    $range = explode('-', $value);
                    if (count($range) !== 2) {
                        return null;
                    }
                    if (!is_numeric($range[0]) || !is_numeric($range[1])) {
                        return null;
                    }
                } elseif (!is_numeric($value)) {
                    return null;
                }
            }
        }

        return $cron;
    }

    /**
     * Validate array of strings
     *
     * @param mixed $value Value to validate
     * @param int $min Minimum items
     * @param int $max Maximum items
     * @return array|null Validated array or null if invalid
     */
    public static function arrayStrings($value, $min = 0, $max = 1000)
    {
        if (!is_array($value)) {
            return null;
        }

        if (count($value) < $min || count($value) > $max) {
            return null;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
        }

        return array_values($value);
    }

    /**
     * Check if an IPv4 or IPv6 address is in a blocked/private/reserved range
     *
     * @param string $ip IP address
     * @param bool $allowPrivate Whether RFC1918 private ranges are allowed
     * @return bool True if blocked, false if safe
     */
    public static function isIpBlocked($ip, $allowPrivate = false)
    {
        $cleanIp = trim($ip, '[]');

        // Check IPv4
        if (filter_var($cleanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($cleanIp);
            if ($long === false) {
                return true;
            }

            // 127.0.0.0/8 (Loopback)
            if (($long & 0xFF000000) === 0x7F000000) {
                return true;
            }
            // 169.254.0.0/16 (Link-Local / Cloud Metadata)
            if (($long & 0xFFFF0000) === 0xA9FE0000) {
                return true;
            }
            // 0.0.0.0/8 ("This network")
            if (($long & 0xFF000000) === 0x00000000) {
                return true;
            }
            // 224.0.0.0/4 (Multicast) & 240.0.0.0/4 (Reserved)
            if (($long & 0xF0000000) === 0xE0000000 || ($long & 0xF0000000) === 0xF0000000) {
                return true;
            }

            if (!$allowPrivate) {
                // 10.0.0.0/8 (RFC 1918)
                if (($long & 0xFF000000) === 0x0A000000) {
                    return true;
                }
                // 172.16.0.0/12 (RFC 1918)
                if (($long & 0xFFF00000) === 0xAC100000) {
                    return true;
                }
                // 192.168.0.0/16 (RFC 1918)
                if (($long & 0xFFFF0000) === 0xC0A80000) {
                    return true;
                }
                // 100.64.0.0/10 (Shared Address Space / CGNAT)
                if (($long & 0xFFC00000) === 0x64400000) {
                    return true;
                }
                // 198.18.0.0/15 (Benchmarking)
                if (($long & 0xFFFE0000) === 0xC6120000) {
                    return true;
                }
            }

            return false;
        }

        // Check IPv6
        if (filter_var($cleanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($cleanIp);
            if ($bin === false) {
                return true;
            }

            // Loopback ::1
            if ($cleanIp === '::1' || $bin === inet_pton('::1')) {
                return true;
            }

            // IPv4-mapped IPv6 (::ffff:x.x.x.x)
            if (substr($bin, 0, 12) === str_repeat("\x00", 10) . "\xFF\xFF") {
                $v4Bin = substr($bin, 12);
                $v4Ip = inet_ntop($v4Bin);
                return self::isIpBlocked($v4Ip, $allowPrivate);
            }

            // Link-local fe80::/10
            if (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) {
                return true;
            }

            if (!$allowPrivate) {
                // Unique-local fc00::/7
                if ((ord($bin[0]) & 0xFE) === 0xFC) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Validate an HTTP/HTTPS URL and prevent SSRF to internal/private/metadata ranges.
     *
     * @param string $url URL to validate
     * @param bool $allowPrivate Whether to allow private RFC1918 IPs (default false)
     * @param string $fieldName Field name for error message
     * @return array ["valid" => bool, "error" => string|null]
     */
    public static function validateUrlSecurity($url, $allowPrivate = false, $fieldName = "URL")
    {
        $url = trim((string)$url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match("/^https?:\/\//i", $url)) {
            return [
                "valid" => false,
                "error" => "{$fieldName} must be a valid HTTP or HTTPS URL.",
            ];
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        if (!$host) {
            return [
                "valid" => false,
                "error" => "{$fieldName} contains an invalid host.",
            ];
        }

        // Disallow embedded userinfo (e.g. http://user:pass@host)
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return [
                "valid" => false,
                "error" => "{$fieldName} cannot contain embedded authentication credentials in URL.",
            ];
        }

        $cleanHost = trim(strtolower($host), '[]');

        // Check dangerous domains and cloud metadata names
        $blockedHosts = [
            'localhost',
            'metadata.google.internal',
            'instance-data',
            '169.254.169.254',
        ];
        if (in_array($cleanHost, $blockedHosts, true)
            || substr($cleanHost, -6) === '.local'
            || substr($cleanHost, -9) === '.internal'
            || substr($cleanHost, -10) === '.localhost'
        ) {
            return [
                "valid" => false,
                "error" => "{$fieldName} host cannot be localhost or internal metadata service.",
            ];
        }

        // Direct IP check
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            if (self::isIpBlocked($cleanHost, $allowPrivate)) {
                return [
                    "valid" => false,
                    "error" => "{$fieldName} points to a restricted, loopback, or private IP address.",
                ];
            }
            return ["valid" => true, "error" => null];
        }

        // Hostname DNS resolution check
        $resolvedIps = @gethostbynamel($cleanHost);
        if (is_array($resolvedIps)) {
            foreach ($resolvedIps as $resolvedIp) {
                if (self::isIpBlocked($resolvedIp, $allowPrivate)) {
                    return [
                        "valid" => false,
                        "error" => "{$fieldName} resolves to a restricted, loopback, or private IP address ({$resolvedIp}).",
                    ];
                }
            }
        }

        return ["valid" => true, "error" => null];
    }
}
