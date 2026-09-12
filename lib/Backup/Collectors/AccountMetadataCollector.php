<?php
/**
 * CWP Account Metadata Collector
 *
 * Extracts account metadata, domain mappings, limits, package info,
 * and serializes it to meta/account.json in the backup snapshot.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

class AccountMetadataCollector implements ComponentCollectorInterface
{
    public function getName(): string
    {
        return 'metadata';
    }

    public function getLabel(): string
    {
        return 'Account Metadata & Limits';
    }

    public function collect(array $account, string $stagingDir, array $options = []): array
    {
        $metaDir = $stagingDir . '/meta';
        if (!is_dir($metaDir) && !mkdir($metaDir, 0755, true) && !is_dir($metaDir)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to create metadata directory: ' . $metaDir,
            ];
        }

        $metaFile = $metaDir . '/account.json';
        $json = json_encode($account, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to serialize account metadata to JSON',
            ];
        }

        $bytes = @file_put_contents($metaFile, $json);
        if ($bytes === false) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to write account metadata to: ' . $metaFile,
            ];
        }

        return [
            'ok'          => true,
            'files_count' => 1,
            'bytes'       => $bytes,
            'items'       => ['account.json'],
            'error'       => null,
        ];
    }
}
