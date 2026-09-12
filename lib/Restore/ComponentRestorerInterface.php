<?php
/**
 * rcloneCWP Component Restorer Interface
 *
 * Contract for granular and full account component restorers.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore
 */

namespace CWP\RcloneCWP\Restore;

interface ComponentRestorerInterface
{
    /**
     * Unique identifier for the component (e.g. 'databases', 'files', 'dns', 'ssl')
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Human-readable label for UI display
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Restore component from staged backup directory into live CWP environment.
     *
     * @param array $account CWP user account metadata
     * @param string $stagedDir Path to the extracted/downloaded snapshot directory
     * @param array $options Granular restore options (e.g. specific databases, target directories)
     * @return array ['ok' => bool, 'restored' => array, 'bytes' => int, 'error' => string|null]
     */
    public function restore(array $account, string $stagedDir, array $options = []): array;
}
