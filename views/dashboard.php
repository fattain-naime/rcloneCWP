<?php
/**
 * rcloneCWP Dashboard View
 *
 * Comprehensive dashboard with system stats, recent backups, destination status, and schedule overview.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Encryption;
use CWP\RcloneCWP\Rclone;
use CWP\RcloneCWP\Scheduling\ScheduleManager;
use CWP\RcloneCWP\Scheduling\CrontabService;

/**
 * Format bytes to human-readable string
 * @param int $bytes
 * @return string
 */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        if (!$bytes || $bytes === 0) return '0 B';
        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int)floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
}

/**
 * Get provider badge color
 * @param string $type
 * @return string
 */
if (!function_exists('getProviderColor')) {
    function getProviderColor($type) {
        $colors = [
            'local' => '2e7d32', 's3' => 'e65100', 'gs' => '1565c0', 'azure' => '0277bd',
            'b2' => 'c2185b', 'sftp' => '512da8', 'ftp' => '7b1fa2', 'webdav' => '00695c',
            'dropbox' => '0288d1', 'onedrive' => '283593', 'swift' => '4e342e', 'tencent' => '00838f'
        ];
        return $colors[$type] ?? '95a5a6';
    }
}

$db = Database::getInstance();
$dbConfig = $db->getConfig();
$rcloneVersion = Rclone::version() ?: 'rclone not found';
$hasKey = Encryption::hasKeyFile();

// Fetch statistics
$destCount = 0;
$destActiveCount = 0;
$jobCount = 0;
$jobActiveCount = 0;
$recentBackups = [];
$destinations = [];
$schedules = [];
$crontabStatus = ['installed' => false, 'entry' => ''];

try {
    // Destination stats
    $destCount = (int)$db->fetchOne("SELECT COUNT(*) AS c FROM rclone_destinations")['c'];
    $destActiveCount = (int)$db->fetchOne("SELECT COUNT(*) AS c FROM rclone_destinations WHERE enabled = 1")['c'];

    // Job stats
    $jobCount = (int)$db->fetchOne("SELECT COUNT(*) AS c FROM rclone_jobs")['c'];
    $jobActiveCount = (int)$db->fetchOne("SELECT COUNT(*) AS c FROM rclone_jobs WHERE enabled = 1")['c'];

    // Recent backups (last 10)
    $recentBackups = $db->fetchAll(
        "SELECT b.*, d.name AS dest_name, d.type AS dest_type " .
        "FROM rclone_backups b " .
        "LEFT JOIN rclone_destinations d ON b.destination_id = d.id " .
        "ORDER BY b.id DESC LIMIT 10"
    );

    // Destinations for status grid - use proper MySQL JSON_EXTRACT syntax
    $destinations = $db->fetchAll(
        "SELECT id, name, type, enabled, config, " .
        "       JSON_UNQUOTE(JSON_EXTRACT(config, '$.last_tested')) AS last_tested, " .
        "       JSON_EXTRACT(config, '$.last_test_ok') AS last_test_ok, " .
        "       JSON_UNQUOTE(JSON_EXTRACT(config, '$.last_test_msg')) AS last_test_msg " .
        "FROM rclone_destinations ORDER BY id DESC"
    );

    // Active schedules
    $scheduleManager = new ScheduleManager($db);
    $schedules = $scheduleManager->listSchedules();

    // Crontab status
    $crontabStatus = CrontabService::getStatus();

} catch (\Exception $e) {
    // Log error but show empty states - don't break dashboard
    error_log('[rcloneCWP Dashboard] Error loading data: ' . $e->getMessage());
}

$crontabInstalled = !empty($crontabStatus['installed']);
$crontabLabel = $crontabInstalled
    ? '<span class="label label-success"><i class="fa fa-check"></i> Active</span>'
    : '<span class="label label-danger"><i class="fa fa-times"></i> Not Installed</span>';
?>

<div class="dashboard-view">
    <!-- ================================================================= -->
    <!-- STAT CARDS ROW                                                    -->
    <!-- ================================================================= -->
    <div class="row" style="margin-bottom: 20px;">
        <!-- Destinations -->
        <div class="col-md-3 col-sm-6">
            <div class="panel panel-primary stat-card">
                <div class="panel-body" style="padding: 15px;">
                    <div class="row">
                        <div class="col-xs-4">
                            <i class="fa fa-cloud fa-3x text-primary" style="opacity: 0.8;"></i>
                        </div>
                        <div class="col-xs-8 text-right">
                            <div class="stat-number" id="stat-dest-count" style="font-size: 28px; font-weight: 700; color: #2c3e50; line-height: 1;"><?php echo htmlspecialchars((string)$destCount); ?></div>
                            <div class="stat-label" style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px;">Destinations</div>
                            <div class="stat-detail" style="font-size: 11px; margin-top: 4px;">
                                <span class="text-success"><?php echo htmlspecialchars((string)$destActiveCount); ?></span> active
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; background: #f8f9fa; border-top: 1px solid #eee;">
                    <a href="#tab-destinations" class="switch-to-destinations" style="font-size: 11px; color: #3498db;">
                        <i class="fa fa-arrow-right"></i> Manage Destinations
                    </a>
                </div>
            </div>
        </div>

        <!-- Backup Jobs -->
        <div class="col-md-3 col-sm-6">
            <div class="panel panel-success stat-card">
                <div class="panel-body" style="padding: 15px;">
                    <div class="row">
                        <div class="col-xs-4">
                            <i class="fa fa-tasks fa-3x text-success" style="opacity: 0.8;"></i>
                        </div>
                        <div class="col-xs-8 text-right">
                            <div class="stat-number" style="font-size: 28px; font-weight: 700; color: #2c3e50; line-height: 1;"><?php echo htmlspecialchars((string)$jobCount); ?></div>
                            <div class="stat-label" style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px;">Backup Jobs</div>
                            <div class="stat-detail" style="font-size: 11px; margin-top: 4px;">
                                <span class="text-success"><?php echo htmlspecialchars((string)$jobActiveCount); ?></span> enabled
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; background: #f8f9fa; border-top: 1px solid #eee;">
                    <a href="#tab-jobs" class="switch-to-destinations" style="font-size: 11px; color: #27ae60;">
                        <i class="fa fa-arrow-right"></i> Manage Jobs
                    </a>
                </div>
            </div>
        </div>

        <!-- Active Schedules -->
        <div class="col-md-3 col-sm-6">
            <div class="panel panel-info stat-card">
                <div class="panel-body" style="padding: 15px;">
                    <div class="row">
                        <div class="col-xs-4">
                            <i class="fa fa-calendar fa-3x text-info" style="opacity: 0.8;"></i>
                        </div>
                        <div class="col-xs-8 text-right">
                            <?php $activeSchedCount = count(array_filter($schedules, function($s) { return !empty($s['active']); })); ?>
                            <div class="stat-number" id="stat-sched-count" style="font-size: 28px; font-weight: 700; color: #2c3e50; line-height: 1;"><?php echo htmlspecialchars((string)$activeSchedCount); ?></div>
                            <div class="stat-label" style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px;">Scheduled</div>
                            <div class="stat-detail" style="font-size: 11px; margin-top: 4px;">
                                <span class="<?php echo $crontabInstalled ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $crontabInstalled ? 'Cron Active' : 'Cron Inactive'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; background: #f8f9fa; border-top: 1px solid #eee;">
                    <a href="#tab-schedules" class="switch-to-destinations" style="font-size: 11px; color: #2980b9;">
                        <i class="fa fa-arrow-right"></i> Manage Schedules
                    </a>
                </div>
            </div>
        </div>

        <!-- System Health -->
        <div class="col-md-3 col-sm-6">
            <div class="panel panel-warning stat-card">
                <div class="panel-body" style="padding: 15px;">
                    <div class="row">
                        <div class="col-xs-4">
                            <i class="fa fa-heartbeat fa-3x text-warning" style="opacity: 0.8;"></i>
                        </div>
                        <div class="col-xs-8 text-right">
                            <div class="stat-number" style="font-size: 28px; font-weight: 700; color: #2c3e50; line-height: 1;">OK</div>
                            <div class="stat-label" style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px;">System Health</div>
                            <div class="stat-detail" style="font-size: 11px; margin-top: 4px;">
                                <span class="text-success"><i class="fa fa-check"></i> All Systems Operational</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; background: #f8f9fa; border-top: 1px solid #eee;">
                    <a href="#tab-settings" class="switch-to-destinations" style="font-size: 11px; color: #f39c12;">
                        <i class="fa fa-arrow-right"></i> View Details
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MAIN CONTENT ROW                                                  -->
    <!-- ================================================================= -->
    <div class="row">
        <!-- LEFT: Recent Backups + Schedule Overview -->
        <div class="col-md-8">
            <!-- Recent Backup History -->
            <div class="panel panel-default" style="margin-bottom: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div class="panel-heading" style="background: #fff; border-bottom: 1px solid #e0e0e0; padding: 12px 15px;">
                    <div class="row">
                        <div class="col-xs-6">
                            <h4 class="panel-title" style="margin: 0; font-weight: 600; color: #2c3e50;">
                                <i class="fa fa-history" style="color: #3498db; margin-right: 6px;"></i> Recent Backup Activity
                            </h4>
                        </div>
                        <div class="col-xs-6 text-right">
                            <a href="#tab-jobs" class="switch-to-destinations btn btn-xs btn-default" style="margin-top: -2px;">
                                <i class="fa fa-list"></i> View All
                            </a>
                        </div>
                    </div>
                </div>
                <div class="panel-body" style="padding: 0;">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped" style="margin-bottom: 0; font-size: 13px;">
                            <thead style="background: #fafafa;">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Job</th>
                                    <th>Type</th>
                                    <th>Destination</th>
                                    <th>Started</th>
                                    <th>Duration</th>
                                    <th>Files</th>
                                    <th>Size</th>
                                    <th style="width: 100px;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="recent-backups-body">
                                <?php if (empty($recentBackups)): ?>
                                <tr>
                                    <td colspan="9" class="text-center" style="padding: 40px; color: #7f8c8d;">
                                        <i class="fa fa-folder-open-o fa-3x" style="color: #bdc3c7; margin-bottom: 10px;"></i>
                                        <p style="margin: 0 0 10px 0;">No backup history yet.</p>
                                        <a href="#tab-jobs" class="switch-to-destinations btn btn-primary btn-sm">
                                            <i class="fa fa-plus-circle"></i> Create Your First Backup Job
                                        </a>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($recentBackups as $b):
                                        $statusClass = 'label-default';
                                        $statusIcon = 'fa-question';
                                        $statusText = 'Unknown';
                                        if (!empty($b['status'])) {
                                            if ($b['status'] === 'success') {
                                                $statusClass = 'label-success';
                                                $statusIcon = 'fa-check';
                                                $statusText = 'Success';
                                            } elseif ($b['status'] === 'running') {
                                                $statusClass = 'label-warning';
                                                $statusIcon = 'fa-spinner fa-spin';
                                                $statusText = 'Running';
                                            } elseif ($b['status'] === 'failed') {
                                                $statusClass = 'label-danger';
                                                $statusIcon = 'fa-times';
                                                $statusText = 'Failed';
                                            }
                                        }
                                        $typeLabel = ucfirst($b['backup_type'] ?? 'full');
                                        $typeBadge = $b['backup_type'] === 'incremental' ? 'label-info' : ($b['backup_type'] === 'full' ? 'label-primary' : 'label-warning');
                                        $startedAt = !empty($b['started_at']) ? date('M j, H:i', strtotime($b['started_at'])) : '--';
                                        $duration = !empty($b['duration_seconds']) ? $b['duration_seconds'] . 's' : '--';
                                        $filesCount = !empty($b['files_count']) ? number_format($b['files_count']) : '0';
                                        $bytes = !empty($b['bytes_transferred']) ? $b['bytes_transferred'] : 0;
                                    ?>
                                    <tr>
                                        <td><code>#<?php echo htmlspecialchars((string)$b['id']); ?></code></td>
                                        <td>
                                            <strong style="color: #2c3e50;"><?php echo htmlspecialchars($b['job_name'] ?? 'Job #' . $b['job_id']); ?></strong>
                                        </td>
                                        <td><span class="label <?php echo htmlspecialchars($typeBadge); ?>"><?php echo htmlspecialchars($typeLabel); ?></span></td>
                                        <td>
                                            <i class="fa fa-cloud text-primary"></i>
                                            <strong><?php echo htmlspecialchars($b['dest_name'] ?? 'Unknown'); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(strtoupper($b['dest_type'] ?? '')); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($startedAt); ?></td>
                                        <td><?php echo htmlspecialchars($duration); ?></td>
                                        <td><?php echo htmlspecialchars($filesCount); ?></td>
                                        <td><?php echo htmlspecialchars(formatBytes($bytes)); ?></td>
                                        <td>
                                            <span class="label <?php echo htmlspecialchars($statusClass); ?>">
                                                <i class="fa <?php echo htmlspecialchars($statusIcon); ?>"></i> <?php echo htmlspecialchars($statusText); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Active Schedules Overview -->
            <div class="panel panel-default" style="border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div class="panel-heading" style="background: #fff; border-bottom: 1px solid #e0e0e0; padding: 12px 15px;">
                    <div class="row">
                        <div class="col-xs-6">
                            <h4 class="panel-title" style="margin: 0; font-weight: 600; color: #2c3e50;">
                                <i class="fa fa-calendar-check-o" style="color: #27ae60; margin-right: 6px;"></i> Active Schedules
                            </h4>
                        </div>
                        <div class="col-xs-6 text-right">
                            <a href="#tab-schedules" class="switch-to-destinations btn btn-xs btn-default" style="margin-top: -2px;">
                                <i class="fa fa-cog"></i> Manage
                            </a>
                        </div>
                    </div>
                </div>
                <div class="panel-body" style="padding: 0;">
                    <?php if (empty($schedules)): ?>
                    <div style="padding: 30px; text-align: center; color: #7f8c8d;">
                        <i class="fa fa-calendar-times-o fa-3x" style="color: #bdc3c7; margin-bottom: 10px;"></i>
                        <p style="margin: 0 0 10px 0;">No schedules configured.</p>
                        <a href="#tab-schedules" class="switch-to-destinations btn btn-primary btn-sm">
                            <i class="fa fa-plus"></i> Create Schedule
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped" style="margin-bottom: 0; font-size: 13px;">
                            <thead style="background: #fafafa;">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Job</th>
                                    <th>Cron Expression</th>
                                    <th>Timezone</th>
                                    <th>Next Run</th>
                                    <th style="width: 100px;">Status</th>
                                    <th style="width: 80px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $s):
                                    $nextRun = '—';
                                    try {
                                        if (!empty($s['cron_expression'])) {
                                            $parser = new \CWP\RcloneCWP\Scheduling\CronParser();
                                            $next = $parser->nextRuns($s['cron_expression'], 1, $s['timezone'] ?? 'UTC');
                                            if (!empty($next)) {
                                                $nextRun = $next[0]->format('M j, H:i T');
                                            }
                                        }
                                    } catch (\Exception $e) {
                                        $nextRun = 'Invalid cron';
                                    }
                                    $active = !empty($s['active']);
                                ?>
                                <tr>
                                    <td><code>#<?php echo htmlspecialchars((string)$s['id']); ?></code></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($s['job_name'] ?? 'Job #' . $s['job_id']); ?></strong>
                                    </td>
                                    <td><code style="font-size: 11px; background: #f4f4f4; padding: 2px 6px;"><?php echo htmlspecialchars($s['cron_expression']); ?></code></td>
                                    <td><?php echo htmlspecialchars($s['timezone'] ?? 'UTC'); ?></td>
                                    <td><?php echo htmlspecialchars($nextRun); ?></td>
                                    <td>
                                        <?php if ($active): ?>
                                            <span class="label label-success"><i class="fa fa-check"></i> Active</span>
                                        <?php else: ?>
                                            <span class="label label-default"><i class="fa fa-pause"></i> Paused</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <a href="#tab-schedules" class="switch-to-destinations btn btn-xs btn-default">Edit</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($crontabInstalled): ?>
                <div class="panel-footer" style="background: #f0f8ff; border-top: 1px solid #d6eaff; padding: 10px 15px; font-size: 11px;">
                    <i class="fa fa-info-circle text-info"></i> Crontab runner active: checks every 5 minutes.
                    <code style="background: #fff; padding: 2px 6px;"><?php echo htmlspecialchars($crontabStatus['entry'] ?? ''); ?></code>
                </div>
                <?php else: ?>
                <div class="panel-footer" style="background: #fff8f0; border-top: 1px solid #ffe0b2; padding: 10px 15px; font-size: 11px;">
                    <i class="fa fa-exclamation-triangle text-warning"></i> Crontab not installed.
                    <a href="#tab-settings" class="switch-to-destinations btn btn-xs btn-success">Install Now</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Destination Status Grid + Quick Actions -->
        <div class="col-md-4">
            <!-- Destination Status Grid -->
            <div class="panel panel-default" style="margin-bottom: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div class="panel-heading" style="background: #fff; border-bottom: 1px solid #e0e0e0; padding: 12px 15px;">
                    <h4 class="panel-title" style="margin: 0; font-weight: 600; color: #2c3e50;">
                        <i class="fa fa-server" style="color: #337ab7; margin-right: 6px;"></i> Destination Status
                    </h4>
                </div>
                <div class="panel-body" style="padding: 15px;">
                    <?php if (empty($destinations)): ?>
                    <div style="text-align: center; color: #7f8c8d; padding: 20px;">
                        <i class="fa fa-cloud-upload fa-3x" style="color: #bdc3c7; margin-bottom: 10px;"></i>
                        <p style="margin: 0 0 10px 0;">No destinations configured.</p>
                        <a href="#tab-destinations" class="switch-to-destinations btn btn-primary btn-sm">
                            <i class="fa fa-plus"></i> Add Destination
                        </a>
                    </div>
                    <?php else: ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($destinations as $d):
                            $config = is_array($d['config']) ? $d['config'] : json_decode($d['config'] ?? '{}', true);
                            if (!is_array($config)) $config = [];
                            $enabled = !empty($d['enabled']);
                            // JSON fields now come directly from SQL query
                            $lastTested = !empty($d['last_tested']) ? $d['last_tested'] : (!empty($config['_last_tested']) ? $config['_last_tested'] : null);
                            $lastTestOk = $d['last_test_ok'] !== null ? (bool)$d['last_test_ok'] : (isset($config['_last_test_ok']) ? (bool)$config['_last_test_ok'] : null);
                            $lastTestMsg = $d['last_test_msg'] ?? ($config['_last_test_msg'] ?? '');
                        ?>
                        <div class="dest-status-item" style="margin-bottom: 12px; padding: 12px; background: #fafafa; border-radius: 4px; border: 1px solid #eee;">
                            <div class="row">
                                <div class="col-xs-8">
                                    <div style="font-weight: 600; color: #2c3e50;">
                                        <i class="fa fa-cloud" style="color: #337ab7; margin-right: 4px;"></i>
                                        <?php echo htmlspecialchars($d['name']); ?>
                                    </div>
                                    <div style="font-size: 11px; color: #7f8c8d; margin-top: 2px;">
                                        <span class="label label-default" style="background: #<?php echo htmlspecialchars(getProviderColor($d['type'])); ?>; color: #fff;"><?php echo htmlspecialchars(strtoupper($d['type'])); ?></span>
                                    </div>
                                </div>
                                <div class="col-xs-4 text-right">
                                    <?php if ($enabled): ?>
                                        <span class="label label-success" style="font-size: 10px;"><i class="fa fa-check"></i> Enabled</span>
                                    <?php else: ?>
                                        <span class="label label-default" style="font-size: 10px;"><i class="fa fa-pause"></i> Disabled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($lastTested): ?>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee;">
                                <div class="row">
                                    <div class="col-xs-6">
                                        <small class="text-muted">Last Test:</small>
                                        <br><?php echo htmlspecialchars(date('M j, H:i', strtotime($lastTested))); ?>
                                    </div>
                                    <div class="col-xs-6 text-right">
                                        <?php if ($lastTestOk === true): ?>
                                            <span class="label label-success" style="font-size: 10px;"><i class="fa fa-check-circle"></i> Verified</span>
                                        <?php else: ?>
                                            <span class="label label-danger" style="font-size: 10px;"><i class="fa fa-times-circle"></i> Failed</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee;">
                                <small class="text-muted"><i class="fa fa-clock-o"></i> Not tested yet</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="panel panel-default" style="border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div class="panel-heading" style="background: #2c3e50; color: #fff; padding: 12px 15px;">
                    <h4 class="panel-title" style="margin: 0; font-weight: 600;">
                        <i class="fa fa-bolt" style="margin-right: 6px;"></i> Quick Actions
                    </h4>
                </div>
                <div class="panel-body" style="padding: 15px;">
                    <div class="list-group" style="margin-bottom: 0;">
                        <a href="#tab-destinations" class="switch-to-destinations list-group-item list-group-item-action" style="border-radius: 4px; margin-bottom: 6px; border: 1px solid #e0e0e0;">
                            <div class="row">
                                <div class="col-xs-3 text-center" style="font-size: 18px; color: #337ab7;"><i class="fa fa-cloud"></i></div>
                                <div class="col-xs-9">
                                    <div style="font-weight: 600; color: #2c3e50;">Add Destination</div>
                                    <small class="text-muted">Configure S3, SFTP, Google Drive, etc.</small>
                                </div>
                            </div>
                        </a>
                        <a href="#tab-jobs" class="switch-to-destinations list-group-item list-group-item-action" style="border-radius: 4px; margin-bottom: 6px; border: 1px solid #e0e0e0;">
                            <div class="row">
                                <div class="col-xs-3 text-center" style="font-size: 18px; color: #27ae60;"><i class="fa fa-tasks"></i></div>
                                <div class="col-xs-9">
                                    <div style="font-weight: 600; color: #2c3e50;">Create Backup Job</div>
                                    <small class="text-muted">Define what to back up and where</small>
                                </div>
                            </div>
                        </a>
                        <a href="#tab-schedules" class="switch-to-destinations list-group-item list-group-item-action" style="border-radius: 4px; margin-bottom: 6px; border: 1px solid #e0e0e0;">
                            <div class="row">
                                <div class="col-xs-3 text-center" style="font-size: 18px; color: #2980b9;"><i class="fa fa-calendar"></i></div>
                                <div class="col-xs-9">
                                    <div style="font-weight: 600; color: #2c3e50;">Schedule Backups</div>
                                    <small class="text-muted">Set up automated cron schedules</small>
                                </div>
                            </div>
                        </a>
                        <a href="#tab-restore" class="switch-to-destinations list-group-item list-group-item-action" style="border-radius: 4px; margin-bottom: 6px; border: 1px solid #e0e0e0;">
                            <div class="row">
                                <div class="col-xs-3 text-center" style="font-size: 18px; color: #e67e22;"><i class="fa fa-history"></i></div>
                                <div class="col-xs-9">
                                    <div style="font-weight: 600; color: #2c3e50;">Restore from Backup</div>
                                    <small class="text-muted">Browse snapshots and recover data</small>
                                </div>
                            </div>
                        </a>
                        <a href="#tab-hooks" class="switch-to-destinations list-group-item list-group-item-action" style="border-radius: 4px; margin-bottom: 6px; border: 1px solid #e0e0e0;">
                            <div class="row">
                                <div class="col-xs-3 text-center" style="font-size: 18px; color: #8e44ad;"><i class="fa fa-code-fork"></i></div>
                                <div class="col-xs-9">
                                    <div style="font-weight: 600; color: #2c3e50;">Configure Hooks</div>
                                    <small class="text-muted">Pre/post backup automation scripts</small>
                                </div>
                            </div>
                        </a>
                        <a href="#tab-notifications" class="switch-to-destinations list-group-item list-group-item-action" style="border-radius: 4px; border: 1px solid #e0e0e0;">
                            <div class="row">
                                <div class="col-xs-3 text-center" style="font-size: 18px; color: #e74c3c;"><i class="fa fa-bell"></i></div>
                                <div class="col-xs-9">
                                    <div style="font-weight: 600; color: #2c3e50;">Setup Alerts</div>
                                    <small class="text-muted">Email, Telegram, Webhook notifications</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>