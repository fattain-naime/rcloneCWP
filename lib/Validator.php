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
}
