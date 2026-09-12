<?php
/**
 * rcloneCWP API Authentication Helper
 *
 * Provides standalone authentication and permission verification
 * for direct endpoint routing or custom integrations.
 */

require_once __DIR__ . '/../bootstrap.php';

use CWP\RcloneCWP\API;

/**
 * Authenticate incoming request using API key
 *
 * @param string|null $token
 * @return array
 */
function rcloneApiAuthenticate($token = null): array
{
    $api = new API();
    return $api->authenticate($token);
}

/**
 * Check permission scope for authenticated user
 *
 * @param array $apiKeyRecord
 * @param string $permission
 * @return bool
 */
function rcloneApiHasPermission(array $apiKeyRecord, string $permission): bool
{
    $perms = json_decode($apiKeyRecord['permissions'] ?? '[]', true);
    if (!is_array($perms)) {
        return false;
    }

    if (in_array('*', $perms, true) || in_array('all', $perms, true)) {
        return true;
    }

    if (in_array($permission, $perms, true)) {
        return true;
    }

    $parts = explode(':', $permission);
    if (count($parts) === 2) {
        $wildcard = $parts[0] . ':*';
        if (in_array($wildcard, $perms, true)) {
            return true;
        }
    }

    return false;
}
