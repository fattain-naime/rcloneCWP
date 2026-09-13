<?php
/**
 * rcloneCWP CLI Engine
 *
 * Unified command-line dispatcher for administering rcloneCWP: jobs, backups,
 * destinations, restores, schedules, logs and system status.
 *
 * Run `rcloneCWP help` for command reference. Every subcommand supports
 * `--json` for machine-parseable output and `--no-color` to disable ANSI.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

use CWP\RcloneCWP\Backup\BackupEngine;
use CWP\RcloneCWP\Backup\BackupJobManager;
use CWP\RcloneCWP\Destinations\DestinationManager;
use CWP\RcloneCWP\Restore\RestoreEngine;
use CWP\RcloneCWP\Restore\SnapshotBrowser;
use CWP\RcloneCWP\Scheduling\ScheduleManager;
use Exception;

class CLI
{
    /** @var array Command line arguments (excluding the script name). */
    private $args = [];

    /** @var bool Emit machine-readable JSON. */
    private $json = false;

    /** @var bool Disable ANSI colour output. */
    private $noColor = false;

    /** @var bool Colour is usable (TTY + not disabled). */
    private $isatty = false;

    /** @var int Process exit code. */
    private $exitCode = 0;

    /** @var Database|null */
    private $db;

    /** @var Logger|null */
    private $logger;

    /** @var array Parsed flag/value pairs. */
    private $flags = [];

    /**
     * CLI entry point.
     *
     * @param array $argv Full argv including the script name.
     * @return int Exit code.
     */
    public static function main(array $argv): int
    {
        $cli = new self();
        $cli->parse(array_slice($argv, 1));
        try {
            $cli->dispatch();
        } catch (CLIException $e) {
            // fail() already rendered the message; just propagate the code.
            if ($cli->exitCode === 0) {
                $cli->exitCode = 1;
            }
        }
        return $cli->exitCode;
    }

    /**
     * Constructor.
     *
     * @param array $argv Optional argv tail (for tests).
     */
    public function __construct(array $argv = [])
    {
        if (!empty($argv)) {
            $this->parse($argv);
        }
        $this->isatty = $this->supportsAnsi();
    }

    /**
     * Parse command-line tokens into positional args and flags.
     *
     * @param array $tokens argv tail.
     */
    public function parse(array $tokens): void
    {
        $this->args = [];
        $this->flags = [];

        foreach ($tokens as $tok) {
            if (substr($tok, 0, 2) === '--') {
                $pair = explode('=', substr($tok, 2), 2);
                $key = strtolower(trim($pair[0]));
                $val = isset($pair[1]) ? $pair[1] : true;
                $this->flags[$key] = $val;

                if ($key === 'json') {
                    $this->json = true;
                }
                if ($key === 'no-color' || $key === 'nocolor') {
                    $this->noColor = true;
                }
            } elseif ($tok !== '' && $tok[0] === '-') {
                // Short flags are not used; ignore.
                continue;
            } else {
                $this->args[] = $tok;
            }
        }
    }

    /**
     * Read a flag value with a default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function flag($key, $default = null)
    {
        return array_key_exists($key, $this->flags) ? $this->flags[$key] : $default;
    }

    /**
     * Read a required integer position argument.
     *
     * @param int $index Positional index.
     * @param string $name Human label for the error message.
     * @return int
     */
    private function intArg(int $index, string $name): int
    {
        if (!isset($this->args[$index]) || !ctype_digit((string)$this->args[$index])) {
            $this->fail("Missing or invalid {$name} argument.");
        }
        return (int)$this->args[$index];
    }

    /**
     * Read a required string position argument.
     *
     * @param int $index Positional index.
     * @param string $name Human label for the error message.
     * @return string
     */
    private function strArg(int $index, string $name): string
    {
        if (!isset($this->args[$index]) || $this->args[$index] === '') {
            $this->fail("Missing required {$name} argument.");
        }
        return $this->args[$index];
    }

    /**
     * Dispatch the primary command.
     */
    public function dispatch(): void
    {
        $command = $this->args[0] ?? 'help';

        try {
            switch ($command) {
                case 'status':
                    $this->runStatus();
                    break;
                case 'job':
                    $this->runJob();
                    break;
                case 'backup':
                    $this->runBackup();
                    break;
                case 'destination':
                    $this->runDestination();
                    break;
                case 'restore':
                    $this->runRestore();
                    break;
                case 'schedule':
                    $this->runSchedule();
                    break;
                case 'log':
                case 'logs':
                    $this->runLog();
                    break;
                case 'help':
                case '--help':
                case '-h':
                    $this->renderHelp();
                    break;
                case 'version':
                case '--version':
                case '-V':
                    $this->out('rcloneCWP ' . $this->version());
                    break;
                default:
                    $this->fail("Unknown command '{$command}'. Run `rcloneCWP help`.");
            }
        } catch (CLIException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }
    }

    // =========================================================================
    // STATUS
    // =========================================================================

    private function runStatus(): void
    {
        $db = $this->db();
        $dm = $this->destinations();

        $destinations = $dm->listDestinations();
        $jobs = (new BackupJobManager($db, $dm, $this->logger()))->listJobs();

        $totalBackups = (int)$db->fetchOne(
            "SELECT COUNT(*) AS c FROM rclone_backups"
        )['c'];

        $lastRun = $db->fetch(
            "SELECT status, started_at FROM rclone_backups ORDER BY started_at DESC LIMIT 1"
        );

        $storageUsed = (int)$db->fetchOne(
            "SELECT COALESCE(SUM(bytes_transferred),0) AS b FROM rclone_backups"
        )['b'];

        $activeSchedules = (int)$db->fetchOne(
            "SELECT COUNT(*) AS c FROM rclone_schedules WHERE active = 1"
        )['c'];

        $activeDestCount = 0;
        foreach ($destinations as $d) {
            if (!empty($d['enabled'])) {
                $activeDestCount++;
            }
        }

        $data = [
            'rclone_version' => $this->rcloneVersion(),
            'module_version' => $this->version(),
            'destinations' => [
                'total' => count($destinations),
                'active' => $activeDestCount,
            ],
            'jobs' => count($jobs),
            'backups' => [
                'total' => $totalBackups,
                'storage_bytes' => $storageUsed,
                'last_run' => $lastRun ? [
                    'status' => $lastRun['status'],
                    'started_at' => $lastRun['started_at'],
                ] : null,
            ],
            'schedules_active' => $activeSchedules,
        ];

        if ($this->json) {
            $this->renderJson(['ok' => true, 'data' => $data]);
            return;
        }

        $this->title('rcloneCWP Status');
        $this->kv('Module version', $data['module_version']);
        $this->kv('rclone version', $data['rclone_version']);
        $this->kv('Destinations', count($destinations) . ' (active: ' . $data['destinations']['active'] . ')');
        $this->kv('Jobs', (string)$data['jobs']);
        $this->kv('Backups (history)', (string)$data['backups']['total']);
        $this->kv('Storage used', $this->humanBytes($storageUsed));
        $this->kv('Active schedules', (string)$activeSchedules);
        if ($lastRun) {
            $this->kv('Last backup', $lastRun['status'] . ' @ ' . $lastRun['started_at']);
        }
    }

    // =========================================================================
    // JOB
    // =========================================================================

    private function runJob(): void
    {
        $dm = $this->destinations();
        $bjm = $this->jobs($dm);

        $sub = $this->args[1] ?? 'list';

        switch ($sub) {
            case 'list':
                $jobList = $bjm->listJobs();
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $jobList]);
                    return;
                }
                if (!$jobList) {
                    $this->info('No jobs defined.');
                    return;
                }
                $rows = [];
                foreach ($jobList as $j) {
                    $rows[] = [
                        (string)$j['id'],
                        $j['name'],
                        $j['job_type'],
                        $j['destination_name'] ?? (string)$j['destination_id'],
                        !empty($j['enabled']) ? 'yes' : 'no',
                    ];
                }
                $this->table(['#', 'Name', 'Type', 'Destination', 'Enabled'], $rows);
                break;

            case 'show':
                $id = $this->intArg(2, 'job id');
                $job = $bjm->getJob($id);
                if (!$job) {
                    $this->fail("Job #{$id} not found.");
                }
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $job]);
                    return;
                }
                $this->renderRecord($job);
                break;

            case 'run':
                $id = $this->intArg(2, 'job id');
                $engine = new BackupEngine($this->db(), $dm, $this->logger());
                $res = $engine->runJob($id, []);
                $this->showRunResult($res);
                break;

            case 'create':
                $name = $this->strArg(2, 'job name');
                $source = $this->strArg(3, 'source path');
                $destId = $this->intArg(4, 'destination id');
                $payload = [
                    'name' => $name,
                    'source_path' => $source,
                    'destination_id' => $destId,
                    'job_type' => $this->flag('type', 'incremental'),
                    'retention_days' => (int)$this->flag('retention', 7),
                    'compression' => (int)(bool)$this->flag('compression', 1),
                ];
                $res = $bjm->createJob($payload);
                $this->showOpResult($res, 'Job created');
                break;

            case 'delete':
                $id = $this->intArg(2, 'job id');
                $res = $bjm->deleteJob($id);
                $this->showOpResult($res, "Job #{$id} deleted");
                break;

            default:
                $this->fail("Unknown job subcommand '{$sub}'. See `rcloneCWP help`.");
        }
    }

    // =========================================================================
    // BACKUP
    // =========================================================================

    private function runBackup(): void
    {
        $dm = $this->destinations();
        $bjm = $this->jobs($dm);
        $engine = new BackupEngine($this->db(), $dm, $this->logger());

        $sub = $this->args[1] ?? 'list';

        switch ($sub) {
            case 'list':
                $jobId = (int)$this->flag('job', 0);
                $limit = (int)$this->flag('limit', 50);
                $history = $bjm->listHistory($jobId, $limit);
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $history]);
                    return;
                }
                if (!$history) {
                    $this->info('No backup history found.');
                    return;
                }
                $rows = [];
                foreach ($history as $b) {
                    $rows[] = [
                        (string)$b['id'],
                        $b['job_name'] ?? (string)$b['job_id'],
                        $b['destination_name'] ?? (string)$b['destination_id'],
                        $b['status'],
                        $b['started_at'],
                        $this->humanBytes((int)($b['bytes_transferred'] ?? 0)),
                    ];
                }
                $this->table(['#', 'Job', 'Destination', 'Status', 'Started', 'Data'], $rows);
                break;

            case 'show':
                $id = $this->intArg(2, 'backup id');
                $row = $this->db()->fetch(
                    "SELECT b.*, j.name AS job_name, d.name AS destination_name, d.type AS destination_type " .
                    "FROM rclone_backups b " .
                    "LEFT JOIN rclone_jobs j ON b.job_id = j.id " .
                    "LEFT JOIN rclone_destinations d ON b.destination_id = d.id " .
                    "WHERE b.id = ?",
                    [$id]
                );
                if (!$row) {
                    $this->fail("Backup record #{$id} not found.");
                }
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $row]);
                    return;
                }
                $this->renderRecord($row);
                break;

            case 'run':
                $id = $this->intArg(2, 'job id');
                $res = $engine->runJob($id, []);
                $this->showRunResult($res);
                break;

            case 'prune':
                $jobId = (int)$this->flag('job', 0);
                $res = $bjm->pruneExpiredBackups($jobId, []);
                $this->showOpResult($res, 'Prune complete');
                break;

            default:
                $this->fail("Unknown backup subcommand '{$sub}'. See `rcloneCWP help`.");
        }
    }

    // =========================================================================
    // DESTINATION
    // =========================================================================

    private function runDestination(): void
    {
        $dm = $this->destinations();

        $sub = $this->args[1] ?? 'list';

        switch ($sub) {
            case 'list':
                $destList = $dm->listDestinations();
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $destList]);
                    return;
                }
                if (!$destList) {
                    $this->info('No destinations configured.');
                    return;
                }
                $rows = [];
                foreach ($destList as $d) {
                    $rows[] = [
                        (string)$d['id'],
                        $d['name'],
                        $d['type'],
                        !empty($d['enabled']) ? 'yes' : 'no',
                    ];
                }
                $this->table(['#', 'Name', 'Type', 'Enabled'], $rows);
                break;

            case 'show':
                $id = $this->intArg(2, 'destination id');
                $dest = $dm->getDestination($id, true);
                if (!$dest) {
                    $this->fail("Destination #{$id} not found.");
                }
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $dest]);
                    return;
                }
                $this->renderRecord($dest);
                break;

            case 'test':
                $id = $this->intArg(2, 'destination id');
                $res = $dm->testDestination($id);
                $this->showOpResult($res, 'Test result');
                break;

            case 'enable':
                $id = $this->intArg(2, 'destination id');
                $res = $dm->toggleDestination($id, true);
                $this->showOpResult($res, "Destination #{$id} enabled");
                break;

            case 'disable':
                $id = $this->intArg(2, 'destination id');
                $res = $dm->toggleDestination($id, false);
                $this->showOpResult($res, "Destination #{$id} disabled");
                break;

            case 'delete':
                $id = $this->intArg(2, 'destination id');
                $res = $dm->deleteDestination($id);
                $this->showOpResult($res, "Destination #{$id} deleted");
                break;

            default:
                $this->fail("Unknown destination subcommand '{$sub}'. See `rcloneCWP help`.");
        }
    }

    // =========================================================================
    // RESTORE
    // =========================================================================

    private function runRestore(): void
    {
        $dm = $this->destinations();
        $browser = new SnapshotBrowser($this->db(), $dm, $this->logger());
        $engine = new RestoreEngine($this->db(), $dm, $this->logger(), $browser);

        $sub = $this->args[1] ?? 'list';

        switch ($sub) {
            case 'list':
                $destinationId = $this->intArg(2, 'destination id');
                $snapshots = $browser->listSnapshots($destinationId);
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $snapshots]);
                    return;
                }
                if (!$snapshots) {
                    $this->info('No snapshots found for this destination.');
                    return;
                }
                $rows = [];
                foreach ($snapshots as $s) {
                    $path = $s['path'] ?? $s['name'] ?? '-';
                    $size = $s['size'] ?? 0;
                    $modified = $s['modified'] ?? '-';
                    $rows[] = [$path, (string)$size, $modified];
                }
                $this->table(['Snapshot', 'Size', 'Modified'], $rows);
                break;

            case 'inspect':
                $destinationId = $this->intArg(2, 'destination id');
                $snapshotPath = $this->strArg(3, 'snapshot path');
                $meta = $browser->inspectSnapshot($destinationId, $snapshotPath);
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $meta]);
                    return;
                }
                $this->renderRecord($meta);
                break;

            case 'run':
                $destinationId = $this->intArg(2, 'destination id');
                $snapshotPath = $this->strArg(3, 'snapshot path');
                $username = $this->strArg(4, 'username');
                $components = [];
                $compVal = $this->flag('components', '');
                if (!empty($compVal) && $compVal !== true) {
                    $rawComps = array_map('trim', explode(',', (string)$compVal));
                    $allowedComps = ['files', 'databases', 'dns', 'ssl', 'cron', 'mail'];
                    foreach ($rawComps as $c) {
                        if (in_array($c, $allowedComps, true)) {
                            $components[] = $c;
                        }
                    }
                }
                $options = [
                    'dry_run' => !empty($this->flags['dry-run']) || !empty($this->flags['dry_run']),
                    'overwrite' => !empty($this->flags['overwrite']),
                    'create_account' => !empty($this->flags['create-account']) || !empty($this->flags['create_account']),
                ];
                $res = $engine->executeRestore(
                    $destinationId, $snapshotPath, $username, $components, $options
                );
                $this->showRunResult($res);
                break;

            default:
                $this->fail("Unknown restore subcommand '{$sub}'. See `rcloneCWP help`.");
        }
    }

    // =========================================================================
    // SCHEDULE
    // =========================================================================

    private function runSchedule(): void
    {
        $dm = $this->destinations();
        $bjm = $this->jobs($dm);
        $sm = $this->schedules();

        $sub = $this->args[1] ?? 'list';

        switch ($sub) {
            case 'list':
                $schedList = $sm->listSchedules();
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $schedList]);
                    return;
                }
                if (!$schedList) {
                    $this->info('No schedules defined.');
                    return;
                }
                $rows = [];
                foreach ($schedList as $s) {
                    $rows[] = [
                        (string)$s['id'],
                        (string)$s['job_id'],
                        $s['cron_expression'],
                        !empty($s['active']) ? 'yes' : 'no',
                        $s['next_run'] ?? '-',
                    ];
                }
                $this->table(['#', 'Job', 'Cron', 'Active', 'Next run'], $rows);
                break;

            case 'show':
                $id = $this->intArg(2, 'schedule id');
                $sched = $sm->getSchedule($id);
                if (!$sched) {
                    $this->fail("Schedule #{$id} not found.");
                }
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $sched]);
                    return;
                }
                $this->renderRecord($sched);
                break;

            case 'enable':
                $id = $this->intArg(2, 'schedule id');
                $res = $sm->toggleActive($id, true);
                $this->showOpResult($res, "Schedule #{$id} enabled");
                break;

            case 'disable':
                $id = $this->intArg(2, 'schedule id');
                $res = $sm->toggleActive($id, false);
                $this->showOpResult($res, "Schedule #{$id} disabled");
                break;

            case 'run-due':
                $due = $sm->getDueSchedules();
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $due]);
                    return;
                }
                if (!$due) {
                    $this->info('No schedules due.');
                    return;
                }
                foreach ($due as $sched) {
                    $schedId = (int)$sched['id'];
                    $jobId = (int)$sched['job_id'];
                    $sm->recordRun($schedId, 'running');
                    $res = $bjm->runJobNow($jobId);
                    if (!empty($res['ok'])) {
                        $sm->recordRun($schedId, 'completed');
                        $this->ok("Schedule #{$schedId} (job #{$jobId}) completed.");
                    } else {
                        $sm->recordRun($schedId, 'failed');
                        $this->err("Schedule #{$schedId} (job #{$jobId}) failed: " . ($res['error'] ?? 'unknown'));
                    }
                }
                break;

            default:
                $this->fail("Unknown schedule subcommand '{$sub}'. See `rcloneCWP help`.");
        }
    }

    // =========================================================================
    // LOG
    // =========================================================================

    private function runLog(): void
    {
        $sub = $this->args[1] ?? 'show';

        switch ($sub) {
            case 'show':
                $limit = (int)$this->flag('limit', 100);
                $rows = $this->db()->fetchAll(
                    "SELECT id, level, category, message, created_at " .
                    "FROM rclone_logs ORDER BY id DESC LIMIT ?",
                    [$limit]
                );
                $rows = array_reverse($rows);
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => $rows]);
                    return;
                }
                if (!$rows) {
                    $this->info('No log entries.');
                    return;
                }
                foreach ($rows as $r) {
                    $level = strtoupper($r['level']);
                    $colorReset = '';
                    $levelColor = $this->colorForLevel($level);
                    $line = sprintf(
                        '%-8s %s [%s] %s',
                        $levelColor . $level . $this->reset(),
                        $r['created_at'],
                        $r['category'],
                        $r['message']
                    );
                    $this->out($line);
                }
                break;

            case 'tail':
                $lines = (int)$this->flag('lines', 20);
                $logDir = $this->logger()->getLogDir();
                $logFile = $logDir . '/rcloneCWP.' . date('Y-m-d') . '.log';
                $content = @file($logFile);
                if (!$content) {
                    $this->info('No log file available for today.');
                    return;
                }
                $slice = array_slice($content, -$lines);
                foreach ($slice as $line) {
                    $this->out(rtrim($line, "\n"));
                }
                break;

            case 'clear':
                $this->db()->query("TRUNCATE TABLE rclone_logs");
                if ($this->json) {
                    $this->renderJson(['ok' => true, 'data' => ['truncated' => true]]);
                    return;
                }
                $this->ok('Log table cleared.');
                break;

            default:
                $this->fail("Unknown log subcommand '{$sub}'. See `rcloneCWP help`.");
        }
    }

    // =========================================================================
    // HELP
    // =========================================================================

    private function renderHelp(): void
    {
        $sep = str_repeat('-', 60);
        $this->title('rcloneCWP CLI');
        $this->out('Usage: rcloneCWP <command> [subcommand] [args] [--json] [--no-color]');
        $this->out('');
        $this->out('Commands:');
        $this->out('  status                           Show overall system status');
        $this->out('  job list                         List backup jobs');
        $this->out('  job show <id>                    Show a single job');
        $this->out('  job run <id>                     Run a job immediately');
        $this->out('  job create <name> <source> <dest_id>');
        $this->out('                                   Create a backup job');
        $this->out('  job delete <id>                  Delete a job');
        $this->out('  backup list [--job=<id>] [--limit=N]');
        $this->out('                                   List backup history');
        $this->out('  backup show <id>                 Show a backup record');
        $this->out('  backup run <id>                  Run a backup job');
        $this->out('  backup prune [--job=<id>]        Prune expired backups');
        $this->out('  destination list                 List storage destinations');
        $this->out('  destination show <id>            Show destination details');
        $this->out('  destination test <id>            Test connectivity');
        $this->out('  destination enable|disable <id>  Toggle a destination');
        $this->out('  destination delete <id>          Delete a destination');
        $this->out('  restore list <dest_id>           List snapshot paths');
        $this->out('  restore inspect <dest_id> <path> Inspect a snapshot');
        $this->out('  restore run <dest_id> <path> <user>');
        $this->out('                                   Restore to an account');
        $this->out('  schedule list                    List cron schedules');
        $this->out('  schedule show <id>               Show a schedule');
        $this->out('  schedule enable|disable <id>     Toggle a schedule');
        $this->out('  schedule run-due                 Run all due schedules');
        $this->out('  log show [--limit=N]             Show recent log entries');
        $this->out('  log tail [--lines=N]             Tail the daily log file');
        $this->out('  log clear                        Truncate the log table');
        $this->out('  version                          Show version');
    }

    // =========================================================================
    // SUBSYSTEM HELPERS
    // =========================================================================

    private function db(): Database
    {
        if (!$this->db) {
            $this->db = Database::getInstance();
        }
        return $this->db;
    }

    private function logger(): Logger
    {
        if (!$this->logger) {
            $this->logger = new Logger(RCLONE_LOG_DIR, $this->db());
        }
        return $this->logger;
    }

    private function destinations(): DestinationManager
    {
        return new DestinationManager($this->db(), null, $this->logger());
    }

    private function jobs(DestinationManager $dm): BackupJobManager
    {
        return new BackupJobManager($this->db(), $dm, $this->logger());
    }

    private function schedules(): ScheduleManager
    {
        return new ScheduleManager($this->db());
    }

    private function version(): string
    {
        return defined('RCLONE_VERSION') ? RCLONE_VERSION : '1.0.0';
    }

    private function rcloneVersion(): string
    {
        $v = Rclone::version();
        if (is_array($v) && !empty($v['version'])) {
            return $v['version'];
        }
        return is_string($v) ? $v : 'unknown';
    }

    // =========================================================================
    // RESULT RENDERING
    // =========================================================================

    private function showRunResult(array $res): void
    {
        $ok = !empty($res['ok']);
        if ($this->json) {
            $this->renderJson($res);
            return;
        }
        if ($ok) {
            $this->ok('Operation completed successfully.');
            $count = count($res['results'] ?? []);
            if ($count > 0) {
                $this->out('  Items processed: ' . $count);
            }
        } else {
            $this->err($res['error'] ?? 'Operation failed.');
        }
        $this->exitCode = $ok ? 0 : 1;
    }

    private function showOpResult(array $res, string $okMsg): void
    {
        $ok = !empty($res['ok']);
        if ($this->json) {
            $this->renderJson($res);
            return;
        }
        if ($ok) {
            $this->ok($okMsg);
        } else {
            $this->err($res['error'] ?? 'Operation failed.');
        }
        $this->exitCode = $ok ? 0 : 1;
    }

    private function renderRecord(array $record): void
    {
        foreach ($record as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v) ?: '{}';
            }
            $this->kv($k, $v);
        }
    }

    // =========================================================================
    // OUTPUT HELPERS
    // =========================================================================

    private function renderJson($data): void
    {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->out('');
    }

    private function title(string $text): void
    {
        $this->out($this->bold($text));
    }

    private function kv(string $key, $value): void
    {
        $this->out($this->bold($key) . ': ' . $value);
    }

    private function table(array $headers, array $rows): void
    {
        if (!$rows) {
            $this->info('No data.');
            return;
        }

        $widths = [];
        $this->normalizeRowWidths($headers, $widths);
        foreach ($rows as $row) {
            $this->normalizeRowWidths($row, $widths);
        }

        $this->out($this->printRow($headers, $widths, true));
        foreach ($rows as $row) {
            $this->out($this->printRow($row, $widths, false));
        }
    }

    private function normalizeRowWidths(array $row, array &$widths): void
    {
        foreach ($row as $i => $cell) {
            $len = strlen((string)$cell);
            if (!isset($widths[$i]) || $len > $widths[$i]) {
                $widths[$i] = $len;
            }
        }
    }

    private function printRow(array $row, array $widths, bool $header): string
    {
        $cells = [];
        foreach ($widths as $i => $w) {
            $cell = (string)($row[$i] ?? '');
            $cells[] = str_pad($cell, $w);
        }
        $line = '  ' . implode('  ', $cells) . '  ';
        return $header ? $this->bold($line) : $line;
    }

    private function ok(string $msg): void
    {
        $this->out($this->color('32', $msg));
    }

    private function err(string $msg): void
    {
        fwrite(STDERR, $this->color('31', 'Error: ' . $msg) . PHP_EOL);
    }

    private function info(string $msg): void
    {
        $this->out($this->color('36', $msg));
    }

    private function warn(string $msg): void
    {
        $this->out($this->color('33', $msg));
    }

    private function colorForLevel(string $level): string
    {
        switch ($level) {
            case 'ERROR':
                return $this->color('31', '');
            case 'WARNING':
                return $this->color('33', '');
            case 'INFO':
                return $this->color('36', '');
            default:
                return $this->color('90', '');
        }
    }

    private function color(string $code, string $text): string
    {
        if (!$this->isatty || $this->noColor) {
            return $text;
        }
        return "\033[{$code}m{$text}\033[0m";
    }

    private function bold(string $text): string
    {
        if (!$this->isatty || $this->noColor) {
            return $text;
        }
        return "\033[1m{$text}\033[0m";
    }

    private function reset(): string
    {
        return $this->isatty && !$this->noColor ? "\033[0m" : '';
    }

    private function supportsAnsi(): bool
    {
        if (getenv('NO_COLOR')) {
            return false;
        }
        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float)$bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, 2) . ' ' . $units[$i];
    }

    private function fail(string $message): void
    {
        $this->err($message);
        $this->exitCode = 1;
        if ($this->json) {
            $this->renderJson(['ok' => false, 'error' => $message]);
        }
        throw new CLIException($message);
    }

    private function out(string $msg): void
    {
        echo $msg . PHP_EOL;
    }
}
