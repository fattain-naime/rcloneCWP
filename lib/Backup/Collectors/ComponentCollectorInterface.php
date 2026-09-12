<?php
/**
 * Component Collector Interface
 *
 * Defines the contract for backing up an isolated CWP account component
 * (databases, files, emails, SSL, DNS, cron, etc.).
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

interface ComponentCollectorInterface
{
    /**
     * Get the machine-readable identifier of this component (e.g. 'databases', 'files', 'dns')
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the human-readable label for display in CWP Admin UI
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Collect and stage the component for the given user account into the staging directory
     *
     * @param array $account CWP account metadata array from CwpAccountDiscovery
     * @param string $stagingDir Absolute path to the snapshot staging directory
     * @param array $options Optional component-specific options
     * @return array [
     *     'ok'          => bool,
     *     'files_count' => int,
     *     'bytes'       => int,
     *     'items'       => array,
     *     'error'       => string|null
     * ]
     */
    public function collect(array $account, string $stagingDir, array $options = []): array;
}
