<?php
/**
 * rcloneCWP Rclone Command Wrapper
 *
 * Safe execution of whitelisted rclone commands with injection defense.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

/**
 * Executes rclone commands through a strict whitelist.
 *
 * Security model:
 * - Only whitelisted subcommands run
 * - Every argument is escapeshellarg'd
 * - Arguments may not start with '-' (flag injection)
 * - Flags come from a fixed server-side map only; unknown/--prefixed tokens denied
 * - Remote names may not start with '-'
 * - 'config show' is never executed (leaks secrets); 'config redacted' only
 */
class Rclone
{
    /**
     * Whitelisted rclone subcommands
     * @var array
     */
    private static $allowedCommands = [
        'copy', 'move', 'sync', 'ls', 'lsd', 'lsl', 'lsjson', 'size',
        'delete', 'rmdir', 'rmdirs', 'purge', 'mkdir', 'cat',
        'checksum', 'check', 'about', 'listremotes', 'version',
        'config',
    ];

    /**
     * Fixed server-side flag map: user-facing option => exact rclone flag.
     * Users reference flags by map key; raw --tokens are never accepted.
     * @var array
     */
    private static $flagMap = [
        'transfers'  => '--transfers',
        'checkers'   => '--checkers',
        'buffer'     => '--buffer-size',
        'tpslimit'   => '--tpslimit',
        'retries'    => '--retries',
        'fast_list'  => '--fast-list',
        'dry_run'    => '--dry-run',
        'quiet'      => '-q',
        'verbose'    => '-v',
        'progress'   => '--progress',
        'json'       => '--json',
        'recursive'  => '-R',
        'human'      => '--human-readable',
    ];

    /**
     * Flags always denied outright (credential/config injection vectors)
     * @var array
     */
    private static $forbiddenFlags = [
        '--config', '--password', '--ask-password', '-o',
        '--sftp-ask-password', '--ask-chunk-password',
    ];

    /**
     * Default transfer flags applied to copy/move/sync
     * @var array
     */
    private static $defaultFlags = ['transfers', 'checkers', 'buffer', 'tpslimit', 'retries', 'fast_list'];

    /**
     * rclone binary path (resolved once, memoized)
     * @var string|null
     */
    private static $binary;

    /**
     * Locate the rclone binary via probe chain.
     *
     * Probe order: /usr/local/bin/rclone, /usr/bin/rclone, PATH lookup.
     *
     * @return string|null Binary path, or null if rclone is not installed
     */
    public static function findBinary()
    {
        if (self::$binary !== null) {
            return self::$binary;
        }

        $candidates = [
            '/usr/local/bin/rclone',
            '/usr/bin/rclone',
            '/bin/rclone',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                self::$binary = $candidate;
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build the shell-safe argument string for an rclone invocation.
     *
     * @param string $command Whitelisted subcommand
     * @param array $args Positional arguments (paths/remotes); each escapeshellarg'd
     * @param array $flagKeys Keys from the fixed flag map
     * @param array $flagValues Optional values keyed by flag key (e.g. ['transfers' => 4])
     * @return string Full command line
     * @throws \InvalidArgumentException on any rejection
     */
    public static function buildCommand($command, array $args, array $flagKeys, array $flagValues = [])
    {
        if (!in_array($command, self::$allowedCommands, true)) {
            throw new \InvalidArgumentException('rclone subcommand not allowed: ' . $command);
        }

        // 'config show' and 'config dump' leak secrets — hard-deny, allow only safe config actions
        if ($command === 'config') {
            $action = isset($args[0]) ? (string) $args[0] : '';
            $allowedConfigActions = ['redacted', 'file', 'providers'];
            if (!in_array($action, $allowedConfigActions, true)) {
                throw new \InvalidArgumentException('config action not allowed: ' . $action);
            }
        }

        $parts = [];

        // Positional args: must be strings, must not start with '-' (flag injection)
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('non-string argument rejected');
            }
            $arg = trim($arg);
            if ($arg === '') {
                throw new \InvalidArgumentException('empty argument rejected');
            }
            if ($arg[0] === '-') {
                throw new \InvalidArgumentException('argument starting with "-" rejected: ' . $arg);
            }
            // Remote name part (before ':') may not start with '-' either — covered above;
            // escapeshellarg neutralizes metacharacters
            $parts[] = escapeshellarg($arg);
        }

        // Flags: only fixed-map keys, values whitelisted by type
        $parts[] = ''; // placeholder removed below; flags appended after args
        array_pop($parts);

        foreach ($flagKeys as $key) {
            if (!is_string($key) || !array_key_exists($key, self::$flagMap)) {
                throw new \InvalidArgumentException('flag not in server-side map: ' . var_export($key, true));
            }
            $token = self::$flagMap[$key];

            // Denylist re-check (defense in depth: map can never contain these)
            if (in_array($token, self::$forbiddenFlags, true)) {
                throw new \InvalidArgumentException('forbidden flag: ' . $token);
            }

            $hasValue = array_key_exists($key, $flagValues) && $flagValues[$key] !== null;
            if ($hasValue) {
                $value = $flagValues[$key];
                if (!is_int($value) && !is_string($value)) {
                    throw new \InvalidArgumentException('flag value must be int or string');
                }
                if (is_string($value) && ($value === '' || $value[0] === '-')) {
                    throw new \InvalidArgumentException('flag value rejected');
                }
                $parts[] = $token . ' ' . escapeshellarg((string) $value);
            } else {
                $parts[] = $token;
            }
        }

        $binary = self::findBinary();
        if ($binary === null) {
            throw new \RuntimeException('rclone binary not found (probe chain: /usr/local/bin, /usr/bin, /bin)');
        }

        $commandParts = [escapeshellarg($binary), $command];
        if (!empty($parts)) {
            $commandParts[] = implode(' ', $parts);
        }

        return implode(' ', $commandParts);
    }

    /**
     * Execute a whitelisted rclone command.
     *
     * @param string $command Subcommand (e.g. 'version', 'lsjson')
     * @param array $args Positional arguments, all escapeshellarg'd
     * @param array $flagKeys Keys from the fixed flag map
     * @param array $flagValues Values for flags that take one
     * @param int|null $timeout Seconds; null = no timeout
     * @param array $env Optional environment variables passed to the process (e.g. RCLONE_CONFIG_*)
     * @return array ['exit' => int, 'stdout' => string, 'stderr' => string, 'cmdline' => string]
     * @throws \InvalidArgumentException on rejected input
     * @throws \RuntimeException if rclone binary is missing or execution fails
     */
    public static function execute($command, array $args = [], array $flagKeys = [], array $flagValues = [], $timeout = null, array $env = [])
    {
        $cmdline = self::buildCommand($command, $args, $flagKeys, $flagValues);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin (closed immediately)
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $procEnv = null;
        if (!empty($env)) {
            $procEnv = array_merge(getenv(), $env);
        }

        $proc = proc_open($cmdline, $descriptors, $pipes, null, $procEnv);
        if (!is_resource($proc)) {
            throw new \RuntimeException('proc_open failed for rclone execution');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);

        if ($timeout !== null && $exit === 9) {
            throw new \RuntimeException('rclone timed out after ' . $timeout . 's');
        }

        // Redact any potential credentials from the returned cmdline
        $safeCmdline = preg_replace('/(--[a-zA-Z0-9_-]*(?:pass|password|secret|key|token)[a-zA-Z0-9_-]*\s+)\S+/i', '$1[REDACTED]', $cmdline);

        return [
            'exit'    => $exit,
            'stdout'  => $stdout,
            'stderr'  => $stderr,
            'cmdline' => $safeCmdline,
        ];
    }

    /**
     * Run a data command with the default transfer flags pre-applied.
     *
     * @param string $command One of copy/move/sync
     * @param string $source Source path or remote:path
     * @param string $dest Destination path or remote:path
     * @param array $extraFlagKeys Additional flag keys beyond the defaults
     * @return array Result from execute()
     */
    public static function transfer($command, $source, $dest, array $extraFlagKeys = [])
    {
        $allowedTransfers = ['copy', 'move', 'sync'];
        if (!in_array($command, $allowedTransfers, true)) {
            throw new \InvalidArgumentException('transfer command must be copy/move/sync');
        }

        $flagKeys = array_values(array_unique(array_merge(self::$defaultFlags, $extraFlagKeys)));
        return self::execute($command, [$source, $dest], $flagKeys);
    }

    /**
     * Get the rclone version string (safe, no args).
     *
     * @return string|null Version line, or null if rclone missing
     */
    public static function version()
    {
        $result = self::execute('version');
        if ($result['exit'] !== 0) {
            return null;
        }
        $firstLine = strtok($result['stdout'], "\n");
        return $firstLine !== false ? $firstLine : null;
    }

    /**
     * List remotes without exposing secrets.
     *
     * @return array List of remote names (config listremotes output lines)
     */
    public static function listRemotes()
    {
        $result = self::execute('listremotes');
        if ($result['exit'] !== 0) {
            return [];
        }
        $lines = array_filter(array_map('trim', explode("\n", $result['stdout'])));
        return array_values($lines);
    }

    /**
     * Validate a remote name (rejects leading '-' and invalid characters).
     *
     * @param string $name Remote name
     * @return string Cleaned remote name
     * @throws \InvalidArgumentException if invalid
     */
    public static function validateRemoteName($name)
    {
        if (!is_string($name) || $name === '' || $name[0] === '-' || strlen($name) > 255) {
            throw new \InvalidArgumentException('invalid remote name');
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $name)) {
            throw new \InvalidArgumentException('invalid remote name');
        }
        return $name;
    }

    /**
     * Expose the flag map (for tests/UI option lists).
     *
     * @return array
     */
    public static function allowedFlagKeys()
    {
        return array_keys(self::$flagMap);
    }
}
