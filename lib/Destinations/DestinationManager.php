<?php
/**
 * rcloneCWP Destination Manager
 *
 * Provider registry, database persistence, credential security,
 * and connection testing coordinator.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Destinations
 */

namespace CWP\RcloneCWP\Destinations;

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Encryption;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Validator;

class DestinationManager
{
    /**
     * @var Database
     */
    protected $db;

    /**
     * @var Encryption
     */
    protected $encryption;

    /**
     * @var Logger|null
     */
    protected $logger;

    /**
     * Provider registry mapping type key => class FQCN
     * @var array
     */
    protected $registry = [
        'local'     => Providers\LocalDestination::class,
        's3'        => Providers\S3Destination::class,
        'sftp'      => Providers\SftpDestination::class,
        'ftp'       => Providers\FtpDestination::class,
        'webdav'    => Providers\WebdavDestination::class,
        'b2'        => Providers\B2Destination::class,
        'gs'        => Providers\GcsDestination::class,
        'azure'     => Providers\AzureDestination::class,
        'azblob'    => Providers\AzureDestination::class,
        'dropbox'   => Providers\DropboxDestination::class,
        'onedrive'  => Providers\OnedriveDestination::class,
        'swift'     => Providers\SwiftDestination::class,
        'tencent'   => Providers\TencentDestination::class,
    ];

    /**
     * Instantiated provider instances
     * @var array
     */
    protected $instances = [];

    /**
     * Constructor
     *
     * @param Database|null $db
     * @param Encryption|null $encryption
     * @param Logger|null $logger
     */
    public function __construct(Database $db = null, Encryption $encryption = null, Logger $logger = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->encryption = $encryption ?: new Encryption();
        $this->logger = $logger;
    }

    /**
     * Register or override a provider class.
     *
     * @param string $type
     * @param string $className
     */
    public function registerProvider($type, $className)
    {
        $this->registry[$type] = $className;
        unset($this->instances[$type]);
    }

    /**
     * Get a provider instance by type key.
     *
     * @param string $type
     * @return DestinationInterface
     * @throws \InvalidArgumentException if type is unsupported
     */
    public function getProvider($type)
    {
        $type = strtolower(trim($type));
        if (!isset($this->registry[$type])) {
            throw new \InvalidArgumentException('Unsupported destination type: ' . $type);
        }

        if (!isset($this->instances[$type])) {
            $class = $this->registry[$type];
            if (!class_exists($class)) {
                throw new \RuntimeException('Provider class not found: ' . $class);
            }
            $this->instances[$type] = new $class($this->encryption);
        }

        return $this->instances[$type];
    }

    /**
     * Get metadata list of all available provider types.
     *
     * @return array
     */
    public function getAvailableTypes()
    {
        $types = [];
        // Deduplicate aliases (e.g. azure and azblob share the same class)
        $seenClasses = [];

        foreach ($this->registry as $type => $class) {
            if (isset($seenClasses[$class])) {
                continue;
            }
            $seenClasses[$class] = true;

            try {
                $provider = $this->getProvider($type);
                $types[$type] = [
                    'type'         => $provider->getType(),
                    'name'         => $provider->getName(),
                    'rclone_type'  => $provider->getRcloneType(),
                    'fields'       => $provider->getConfigFields(),
                    'sensitive'    => $provider->getSensitiveFields(),
                ];
            } catch (\Exception $e) {
                // Skip uninstantiable providers
            }
        }

        return $types;
    }

    /**
     * List all configured destinations.
     *
     * @param bool $enabledOnly
     * @return array
     */
    public function listDestinations($enabledOnly = false)
    {
        $sql = 'SELECT id, name, type, config, enabled, created_at, updated_at FROM rclone_destinations';
        $params = [];

        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY id ASC';

        $rows = $this->db->fetchAll($sql, $params);
        $destinations = [];

        foreach ($rows as $row) {
            $config = json_decode($row['config'], true) ?: [];
            // Mask sensitive fields for safe UI presentation
            try {
                $provider = $this->getProvider($row['type']);
                foreach ($provider->getSensitiveFields() as $sKey) {
                    if (!empty($config[$sKey])) {
                        $config[$sKey] = '••••••••';
                    }
                }
            } catch (\Exception $e) {
                // Provider missing
            }

            $row['config'] = $config;
            $row['enabled'] = (bool) $row['enabled'];
            $destinations[] = $row;
        }

        return $destinations;
    }

    /**
     * Get a single destination by ID.
     *
     * @param int $id
     * @param bool $decrypt Whether to decrypt sensitive fields (true for jobs, false for UI)
     * @return array|null
     */
    public function getDestination($id, $decrypt = false)
    {
        $row = $this->db->fetch(
            'SELECT id, name, type, config, enabled, created_at, updated_at FROM rclone_destinations WHERE id = ?',
            [(int) $id]
        );

        if (!$row) {
            return null;
        }

        $config = json_decode($row['config'], true) ?: [];
        $provider = $this->getProvider($row['type']);

        if ($decrypt) {
            $config = $provider->decryptConfig($config);
        } else {
            foreach ($provider->getSensitiveFields() as $sKey) {
                if (!empty($config[$sKey])) {
                    $config[$sKey] = '••••••••';
                }
            }
        }

        $row['config'] = $config;
        $row['enabled'] = (bool) $row['enabled'];
        return $row;
    }

    /**
     * Create a new destination.
     *
     * @param array $data ['name' => ..., 'type' => ..., 'config' => [...], 'enabled' => bool]
     * @return array ['ok' => bool, 'id' => int|null, 'errors' => array]
     */
    public function createDestination(array $data)
    {
        $errors = [];

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            $errors['name'] = 'Destination name is required';
        }

        $type = strtolower(trim($data['type'] ?? ''));
        if (!isset($this->registry[$type])) {
            $errors['type'] = 'Invalid destination type: ' . $type;
        }

        $rawConfig = is_array($data['config'] ?? null) ? $data['config'] : [];

        if (!empty($errors)) {
            return ['ok' => false, 'id' => null, 'errors' => $errors];
        }

        $provider = $this->getProvider($type);
        $valResult = $provider->validateConfig($rawConfig);
        if (!$valResult['valid']) {
            return ['ok' => false, 'id' => null, 'errors' => $valResult['errors']];
        }

        // Encrypt sensitive fields before storing
        $encryptedConfig = $provider->encryptConfig($rawConfig);
        $configJson = json_encode($encryptedConfig);

        $enabled = isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1;

        try {
            $id = $this->db->insert('rclone_destinations', [
                'name'    => $name,
                'type'    => $type,
                'config'  => $configJson,
                'enabled' => $enabled,
            ]);

            return ['ok' => true, 'id' => (int) $id, 'errors' => []];
        } catch (\Exception $e) {
            return ['ok' => false, 'id' => null, 'errors' => ['db' => $e->getMessage()]];
        }
    }

    /**
     * Update an existing destination.
     *
     * @param int $id
     * @param array $data ['name' => ..., 'config' => [...], 'enabled' => bool]
     * @return array ['ok' => bool, 'errors' => array]
     */
    public function updateDestination($id, array $data)
    {
        $existing = $this->db->fetch(
            'SELECT id, name, type, config, enabled FROM rclone_destinations WHERE id = ?',
            [(int) $id]
        );

        if (!$existing) {
            return ['ok' => false, 'errors' => ['id' => 'Destination not found']];
        }

        $type = $existing['type'];
        $provider = $this->getProvider($type);
        $oldConfig = json_decode($existing['config'], true) ?: [];

        $name = isset($data['name']) ? trim($data['name']) : $existing['name'];
        if ($name === '') {
            return ['ok' => false, 'errors' => ['name' => 'Destination name cannot be empty']];
        }

        $newConfig = is_array($data['config'] ?? null) ? $data['config'] : $oldConfig;

        // Retain existing encrypted secrets if placeholder '••••••••' or empty is passed
        $sensitive = $provider->getSensitiveFields();
        foreach ($sensitive as $sKey) {
            if (isset($newConfig[$sKey])) {
                $val = $newConfig[$sKey];
                if ($val === '••••••••' || $val === '') {
                    if (isset($oldConfig[$sKey])) {
                        $newConfig[$sKey] = $oldConfig[$sKey]; // keep existing encrypted value
                    }
                } else {
                    // New plaintext secret provided — encrypt it
                    $newConfig[$sKey] = $this->encryption->encrypt($val);
                }
            } elseif (isset($oldConfig[$sKey])) {
                $newConfig[$sKey] = $oldConfig[$sKey];
            }
        }

        // For non-sensitive validation, decrypt in-memory copy
        $decryptedForVal = $provider->decryptConfig($newConfig);
        $valResult = $provider->validateConfig($decryptedForVal);
        if (!$valResult['valid']) {
            return ['ok' => false, 'errors' => $valResult['errors']];
        }

        $enabled = isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : $existing['enabled'];

        try {
            $this->db->update(
                'rclone_destinations',
                [
                    'name'    => $name,
                    'config'  => json_encode($newConfig),
                    'enabled' => $enabled,
                ],
                'id = ?',
                [(int) $id]
            );

            return ['ok' => true, 'errors' => []];
        } catch (\Exception $e) {
            return ['ok' => false, 'errors' => ['db' => $e->getMessage()]];
        }
    }

    /**
     * Delete a destination.
     *
     * @param int $id
     * @return array ['ok' => bool, 'message' => string]
     */
    public function deleteDestination($id)
    {
        $id = (int) $id;

        // Check if referenced by active backup jobs
        $jobCount = $this->db->fetchColumn(
            'SELECT COUNT(*) FROM rclone_jobs WHERE destination_id = ?',
            [$id]
        );

        if ($jobCount > 0) {
            return [
                'ok' => false,
                'message' => "Cannot delete destination: referenced by {$jobCount} backup job(s). Reassign or delete those jobs first.",
            ];
        }

        try {
            $this->db->delete('rclone_destinations', 'id = ?', [$id]);
            return ['ok' => true, 'message' => 'Destination deleted successfully'];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => 'Failed to delete destination: ' . $e->getMessage()];
        }
    }

    /**
     * Toggle destination enabled state.
     *
     * @param int $id
     * @param bool $enabled
     * @return array ['ok' => bool, 'enabled' => int]
     */
    public function toggleDestination($id, $enabled)
    {
        try {
            $this->db->update(
                'rclone_destinations',
                ['enabled' => $enabled ? 1 : 0],
                'id = ?',
                [(int) $id]
            );
            return ['ok' => true, 'enabled' => $enabled ? 1 : 0];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test connection of an existing saved destination by ID.
     *
     * @param int $id
     * @return array ['ok' => bool, 'message' => string, 'details' => array]
     */
    public function testDestination($id)
    {
        $dest = $this->getDestination($id, true);
        if (!$dest) {
            return ['ok' => false, 'message' => 'Destination not found', 'details' => []];
        }

        $provider = $this->getProvider($dest['type']);
        $result = $provider->testConnection($dest['config']);

        try {
            $raw = $this->getDestination($id, false);
            if ($raw && is_array($raw['config'])) {
                $rawConfig = $raw['config'];
                $rawConfig['_last_tested'] = date('Y-m-d H:i:s');
                $rawConfig['_last_test_ok'] = !empty($result['ok']);
                $rawConfig['_last_test_msg'] = $result['message'] ?? '';
                $this->db->query(
                    'UPDATE rclone_destinations SET config = ? WHERE id = ?',
                    [json_encode($rawConfig), (int)$id]
                );
            }
        } catch (\Exception $e) {
            // Non-fatal
        }

        return $result;
    }

    /**
     * Test connection on a raw (unsaved) configuration submitted from the UI.
     *
     * @param string $type
     * @param array $config
     * @return array ['ok' => bool, 'message' => string, 'details' => array]
     */
    public function testRawConfig($type, array $config)
    {
        try {
            $provider = $this->getProvider($type);
            return $provider->testConnection($config);
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'message' => 'Test error: ' . $e->getMessage(),
                'details' => ['exception' => get_class($e)],
            ];
        }
    }

    /**
     * Generate in-memory rclone environment variables for a saved destination.
     *
     * @param int $id
     * @param string|null $remoteName
     * @return array ['env' => array, 'target' => string, 'type' => string]
     */
    public function getRcloneTarget($id, $remoteName = null, $subPath = '')
    {
        $dest = $this->getDestination($id, true);
        if (!$dest) {
            throw new \RuntimeException('Destination ID ' . $id . ' not found');
        }

        $remote = $remoteName ?: ('rclonecwp_dest_' . $id);
        $provider = $this->getProvider($dest['type']);
        $env = $provider->getRcloneEnv($dest['config'], $remote);
        $target = $provider->getRemoteTarget($dest['config'], $remote, $subPath);

        return [
            'env'    => $env,
            'target' => $target,
            'type'   => $dest['type'],
            'remote' => $remote,
        ];
    }
}
