<?php
/**
 * rcloneCWP Snapshot Browser
 *
 * Discovers and inspects remote backup snapshots using rclone's
 * lsjson interface and dynamic in-memory rclone execution.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Restore
 */

namespace CWP\RcloneCWP\Restore;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Rclone;

class SnapshotBrowser
{
    /** @var Database */
    private $db;

    /** @var DestinationManager */
    private $dm;

    /** @var Logger */
    private $logger;

    public function __construct(
        Database $db = null,
        DestinationManager $dm = null,
        Logger $logger = null
    ) {
        $this->db = $db ?: Database::getInstance();
        $this->dm = $dm ?: new DestinationManager($this->db, null, $logger);
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
    }

    /**
     * List all available backup snapshots on a given destination.
     *
     * @param int $destinationId
     * @return array ['ok' => bool, 'snapshots' => array, 'error' => string|null]
     */
    public function listSnapshots(int $destinationId): array
    {
        $dest = $this->dm->getDestination($destinationId, true);
        if (!$dest || empty($dest['enabled'])) {
            return ['ok' => false, 'snapshots' => [], 'error' => 'Destination not found or disabled.'];
        }

        $provider = $this->dm->getProvider($dest['type']);
        $config = $dest['config'] ?? [];
        $remoteName = 'restore_' . (int)$destinationId . '_' . substr(md5(uniqid('', true)), 0, 8);
        $env = $provider->getRcloneEnv($config, $remoteName);

        $backupRoot = $provider->getRemoteTarget($config, $remoteName, 'rcloneCWP-backups');

        try {
            $res = Rclone::execute('lsjson', [$backupRoot], [], [], 60, $env);
            if ($res['exit'] !== 0) {
                $err = trim($res['stderr'] ?: $res['stdout']);
                return ['ok' => false, 'snapshots' => [], 'error' => 'rclone lsjson failed: ' . $err];
            }

            $entries = json_decode($res['stdout'], true);
            if (!is_array($entries)) {
                return ['ok' => false, 'snapshots' => [], 'error' => 'Failed to parse rclone lsjson output.'];
            }

            // Filter for job directories (first-level) and extract snapshot metadata
            $jobDirs = array_filter($entries, function ($e) {
                return ($e['IsDir'] ?? false) === true;
            });

            $snapshots = [];
            foreach ($jobDirs as $jobDir) {
                $jobSlug = $jobDir['Name'];
                // List subdirectories in each job dir (snapshots)
                $jobRes = Rclone::execute(
                    'lsjson',
                    [$provider->getRemoteTarget($config, $remoteName, 'rcloneCWP-backups/' . $jobSlug)],
                    [], [], 60, $env
                );

                if ($jobRes['exit'] !== 0) {
                    $this->logger->warning("Failed to list snapshots for job slug: {$jobSlug}");
                    continue;
                }

                $jobEntries = json_decode($jobRes['stdout'], true);
                if (!is_array($jobEntries)) {
                    continue;
                }

                foreach ($jobEntries as $snapEntry) {
                    if (($snapEntry['IsDir'] ?? false) !== true) {
                        continue;
                    }

                    $snapName = $snapEntry['Name'];
                    $path = 'rcloneCWP-backups/' . $jobSlug . '/' . $snapName;

                    // Try to fetch manifest to enrich snapshot info
                    $manifest = $this->fetchManifest($provider, $config, $remoteName, $path . '/manifest.json', $env);

                    // Extract timestamp and username from directory name convention: YYYYMMDD_HHMMSS_username_type
                    // Username may contain underscores, but conventionally last segment is _full|_diff
                    $parts = explode('_', $snapName, 3);
                    $timestamp = date('Y-m-d H:i:s', strtotime(substr($parts[0], 0, 8) . ' ' . ($parts[1] ?? '')));
                    // Extract username: split last part on _<type> where type is the final segment
                    $remainder = $parts[2] ?? '';
                    $subParts = explode('_', $remainder);
                    $suffix = array_pop($subParts); // e.g. "full" or "diff"
                    // If suffix is a type keyword, username is the rest; otherwise the whole remainder
                    if (in_array($suffix, ['full', 'diff', 'inc'], true)) {
                        $username = implode('_', $subParts);
                    } else {
                        $username = $remainder;
                    }

                    $snapshots[] = [
                        'path'        => $path,
                        'name'        => $snapName,
                        'job_slug'    => $jobSlug,
                        'username'    => $username,
                        'timestamp'   => $timestamp,
                        'size'        => (int)($snapEntry['Size'] ?? 0),
                        'has_manifest' => $manifest !== null,
                        'manifest'    => $manifest,
                    ];
                }
            }

            return ['ok' => true, 'snapshots' => $snapshots, 'error' => null];
        } catch (\Exception $e) {
            $this->logger->error('Snapshot listing exception: ' . $e->getMessage());
            return ['ok' => false, 'snapshots' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch and parse the manifest.json from a remote snapshot path.
     *
     * @param mixed $provider
     * @param array $config
     * @param string $remoteName
     * @param string $path
     * @param array $env
     * @return array|null
     */
    private function fetchManifest($provider, array $config, string $remoteName, string $path, array $env): ?array
    {
        $target = $provider->getRemoteTarget($config, $remoteName, $path);
        $safeTarget = trim($target);
        if ($safeTarget === '' || $safeTarget[0] === '-') {
            return null;
        }

        try {
            $res = Rclone::execute('cat', [$target], [], [], 60, $env);
            if ($res['exit'] !== 0) {
                return null;
            }

            $data = json_decode(trim($res['stdout']), true);
            if (is_array($data)) {
                return $data;
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Inspect a single snapshot by path, returning its manifest and component list.
     *
     * @param int $destinationId
     * @param string $path
     * @return array ['ok' => bool, 'manifest' => array|null, 'components' => array, 'error' => string|null]
     */
    public function inspectSnapshot(int $destinationId, string $path): array
    {
        $dest = $this->dm->getDestination($destinationId, true);
        if (!$dest || empty($dest['enabled'])) {
            return ['ok' => false, 'manifest' => null, 'components' => [], 'error' => 'Destination not found or disabled.'];
        }

        $provider = $this->dm->getProvider($dest['type']);
        $config = $dest['config'] ?? [];
        $remoteName = 'inspect_' . (int)$destinationId . '_' . substr(md5(uniqid('', true)), 0, 8);
        $env = $provider->getRcloneEnv($config, $remoteName);

        $manifest = $this->fetchManifest($provider, $config, $remoteName, $path . '/manifest.json', $env);

        if ($manifest === null) {
            return ['ok' => false, 'manifest' => null, 'components' => [], 'error' => 'manifest.json not found or unreadable.'];
        }

        // Build component list from manifest
        $components = [];
        $manifestComponents = $manifest['components'] ?? [];

        foreach ($manifestComponents as $compName => $compData) {
            if ($compName === 'metadata') {
                continue;
            }
            $items = [];
            if (isset($compData['items']) && is_array($compData['items'])) {
                foreach ($compData['items'] as $item) {
                    if (isset($item['file'])) {
                        $items[] = [
                            'file'     => $item['file'],
                            'checksum' => $item['checksum'] ?? '',
                            'bytes'    => (int)($item['bytes'] ?? 0),
                        ];
                    }
                }
            }
            $components[$compName] = [
                'label'    => ucfirst($compName),
                'ok'       => $compData['ok'] ?? false,
                'files_count' => $compData['files_count'] ?? 0,
                'bytes'    => (int)($compData['bytes'] ?? 0),
                'items'    => $items,
            ];
        }

        return ['ok' => true, 'manifest' => $manifest, 'components' => $components, 'error' => null];
    }
}
