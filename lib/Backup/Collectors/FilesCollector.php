<?php
/**
 * CWP User Files Collector
 *
 * Collects /home/<username> files (including public_html, subdomains, dotfiles)
 * with robust exclusion filters (.cache, .trash, sockets) and support for
 * both tar/gzip archiving and rclone direct sync manifests.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Backup\Collectors
 */

namespace CWP\RcloneCWP\Backup\Collectors;

class FilesCollector implements ComponentCollectorInterface
{
    /**
     * Default file/directory exclusion patterns
     * @var array
     */
    private $defaultExclusions = [
        '.cache',
        '.trash',
        '.Trash',
        'tmp',
        '*.sock',
        '*.socket',
        'core.[0-9]*',
        '.cwp_tmp',
    ];

    public function getName(): string
    {
        return 'files';
    }

    public function getLabel(): string
    {
        return 'Home Directory & Web Files';
    }

    /**
     * Get list of default exclusions.
     *
     * @return array
     */
    public function getExclusions(): array
    {
        return $this->defaultExclusions;
    }

    public function collect(array $account, string $stagingDir, array $options = []): array
    {
        $homeDir = $account['home_dir'] ?? ('/home/' . ($account['username'] ?? ''));
        $username = $account['username'] ?? '';

        if (empty($username) || !is_dir($homeDir)) {
            return [
                'ok'          => true,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Home directory does not exist or user is empty: ' . $homeDir,
            ];
        }

        $filesDir = $stagingDir . '/files';
        if (!is_dir($filesDir) && !mkdir($filesDir, 0755, true) && !is_dir($filesDir)) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Failed to create files staging directory: ' . $filesDir,
            ];
        }

        // Custom user exclusions merged with defaults
        $customExcludes = $options['exclude'] ?? [];
        $exclusions = array_unique(array_merge($this->defaultExclusions, $customExcludes));

        // Always write the exclusion filter to staging for rclone or tar
        $excludeFile = $stagingDir . '/meta/files_exclude.txt';
        $metaDir = dirname($excludeFile);
        if (!is_dir($metaDir)) {
            @mkdir($metaDir, 0755, true);
        }
        @file_put_contents($excludeFile, implode("\n", $exclusions) . "\n");

        $mode = $options['mode'] ?? 'archive';

        if ($mode === 'direct_sync') {
            // Direct sync mode: files will be transferred by rclone directly from /home/<user>
            return [
                'ok'          => true,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [
                    'mode'         => 'direct_sync',
                    'source_path'  => $homeDir,
                    'exclude_file' => $excludeFile,
                ],
                'error'       => null,
            ];
        }

        // Archive mode: create home.tar.gz
        $tarFile = $filesDir . '/home.tar.gz';
        $tarResult = $this->createHomeArchive($homeDir, $username, $tarFile, $exclusions);

        if (!$tarResult['ok']) {
            return [
                'ok'          => false,
                'files_count' => 0,
                'bytes'       => 0,
                'items'       => [],
                'error'       => 'Tar creation failed: ' . $tarResult['error'],
            ];
        }

        return [
            'ok'          => true,
            'files_count' => 1,
            'bytes'       => $tarResult['bytes'],
            'items'       => [
                [
                    'file'     => 'home.tar.gz',
                    'bytes'    => $tarResult['bytes'],
                    'checksum' => $tarResult['checksum'],
                ]
            ],
            'error'       => null,
        ];
    }

    /**
     * Create a tar.gz archive of /home/<username> with exclusions
     *
     * @param string $homeDir
     * @param string $username
     * @param string $outputFile
     * @param array $exclusions
     * @return array
     */
    private function createHomeArchive(string $homeDir, string $username, string $outputFile, array $exclusions): array
    {
        $tar = is_file('/usr/bin/tar') ? '/usr/bin/tar' : 'tar';

        $excludeArgs = '';
        foreach ($exclusions as $ex) {
            $excludeArgs .= ' --exclude=' . escapeshellarg($ex);
        }

        // Change directory to /home and archive <username>
        $cmd = sprintf(
            '%s -czf %s%s -C /home %s',
            escapeshellarg($tar),
            escapeshellarg($outputFile),
            $excludeArgs,
            escapeshellarg($username)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Failed to execute tar'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Tar exit code 1 means some files changed while being read, which is common and non-fatal on live systems
        if ($exitCode > 1 || !is_file($outputFile) || filesize($outputFile) === 0) {
            $err = trim($stderr) ?: 'tar exited with status ' . $exitCode;
            return ['ok' => false, 'error' => $err];
        }

        $size = filesize($outputFile);
        $hash = hash_file('sha256', $outputFile);

        return [
            'ok'       => true,
            'bytes'    => $size,
            'checksum' => 'sha256:' . $hash,
        ];
    }
}
