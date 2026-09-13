<?php
/**
 * rcloneCWP Settings & Maintenance View
 *
 * Crontab management, module diagnostics, and maintenance actions.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}

use CWP\RcloneCWP\CSRF;
use CWP\RcloneCWP\Scheduling\CrontabService;
use CWP\RcloneCWP\Scheduling\ScheduleManager;

$csrfToken = CSRF::generateToken();

// Fetch current crontab status for display
$crontabStatus = CrontabService::getStatus();
$crontabInstalled = !empty($crontabStatus['installed']);
$crontabEntry = $crontabStatus['entry'] ?? '';
$crontabLabel = $crontabInstalled
    ? '<span class="label label-success"><i class="fa fa-check"></i> Installed</span>'
    : '<span class="label label-danger"><i class="fa fa-times"></i> Not Installed</span>';

// Fetch schedules count
$scheduleCount = 0;
try {
    $db = \CWP\RcloneCWP\Database::getInstance();
    $scheduleCount = (int)$db->fetchOne(
        "SELECT COUNT(*) AS c FROM rclone_schedules WHERE active = 1"
    )['c'];
} catch (\Exception $e) {
    // Ignore
}
?>

<div class="settings-view">
    <!-- Crontab Management -->
    <div class="row">
        <div class="col-md-8">
            <div class="panel panel-default">
                <div class="panel-heading" style="background: #2c3e50; color: #fff; padding: 10px 15px;">
                    <i class="fa fa-clock-o"></i> Crontab Runner Status
                </div>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <table class="table table-bordered" style="margin-bottom: 0;">
                                <tr>
                                    <th style="width: 40%;">Status</th>
                                    <td><?php echo $crontabLabel; ?></td>
                                </tr>
                                <tr>
                                    <th>Active Schedules</th>
                                    <td><?php echo $scheduleCount; ?></td>
                                </tr>
                                <tr>
                                    <th>Cron Command</th>
                                    <td><code style="font-size: 11px;"><?php echo htmlspecialchars($crontabEntry ?: '—'); ?></code></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-sm-6">
                            <p class="text-muted" style="font-size: 12px; margin-top: 10px;">
                                The crontab runner checks for due backup schedules every 5 minutes and executes them automatically.
                                Install it once during initial setup; uninstalling stops automatic backups.
                            </p>
                            <div style="margin-top: 15px;">
                                <button type="button" class="btn btn-success btn-sm" id="btn-install-crontab"
                                    <?php echo $crontabInstalled ? 'disabled' : ''; ?>
                                    onclick="installCrontab()">
                                    <i class="fa fa-plus"></i> Install Crontab
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" id="btn-uninstall-crontab"
                                    <?php echo $crontabInstalled ? '' : 'disabled'; ?>
                                    onclick="uninstallCrontab()">
                                    <i class="fa fa-trash"></i> Uninstall Crontab
                                </button>
                                <button type="button" class="btn btn-default btn-sm" onclick="refreshCrontabStatus()">
                                    <i class="fa fa-refresh"></i> Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="background: #f9f9f9; font-size: 11px; color: #777;">
                    Crontab entry runs as root via <code>/usr/local/cwp/php71/bin/php</code>. Logs: <code>/var/log/rcloneCWP_cron.log</code>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="panel panel-info">
                <div class="panel-heading" style="background: #2980b9; color: #fff; padding: 10px 15px;">
                    <i class="fa fa-info-circle"></i> Recommended Crontab Frequency
                </div>
                <div class="panel-body" style="font-size: 12px;">
                    <p>For reliable schedule execution, the crontab runner should run every 5 minutes:</p>
                    <pre style="background: #f4f4f4; padding: 8px; border-radius: 3px; font-size: 11px;">*/5 * * * * root /usr/local/cwp/php71/bin/php /usr/local/cwp/rcloneCWP/cron/rcloneCWP.php >> /var/log/rcloneCWP_cron.log 2>&1</pre>
                    <p class="text-muted" style="margin-top: 8px; margin-bottom: 0;">
                        The installer writes this entry automatically. Custom schedules with longer intervals are also supported.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Module Information -->
    <div class="row" style="margin-top: 20px;">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading" style="background: #2c3e50; color: #fff; padding: 10px 15px;">
                    <i class="fa fa-cogs"></i> Module Environment
                </div>
                <div class="panel-body" style="padding: 0;">
                    <table class="table table-striped table-bordered" style="margin-bottom: 0; font-size: 12px;">
                        <tr>
                            <th style="width: 40%;">Module Version</th>
                            <td><code>v<?php echo htmlspecialchars(RCLONE_VERSION); ?></code></td>
                        </tr>
                        <tr>
                            <th>PHP Runtime</th>
                            <td><?php echo PHP_VERSION; ?> (<?php echo PHP_SAPI; ?>)</td>
                        </tr>
                        <tr>
                            <th>Runtime Home</th>
                            <td><code><?php echo htmlspecialchars(RCLONE_HOME); ?></code></td>
                        </tr>
                        <tr>
                            <th>Web Module Entry</th>
                            <td><code><?php echo htmlspecialchars(RCLONE_MODULE_FILE); ?></code></td>
                        </tr>
                        <tr>
                            <th>Log Directory</th>
                            <td><code><?php echo htmlspecialchars(RCLONE_LOG_DIR); ?></code></td>
                        </tr>
                        <tr>
                            <th>Cache Directory</th>
                            <td><code><?php echo htmlspecialchars(RCLONE_CACHE_DIR); ?></code></td>
                        </tr>
                        <tr>
                            <th>CLI Binaries</th>
                            <td>
                                <code>cli/rcloneCWP</code><br>
                                <code>cli/rclone-restore</code><br>
                                <code>cli/rclone-destination</code><br>
                                <code>cli/rclone-schedule</code>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading" style="background: #2c3e50; color: #fff; padding: 10px 15px;">
                    <i class="fa fa-terminal"></i> Transport & Storage
                </div>
                <div class="panel-body" style="padding: 0;">
                    <table class="table table-striped table-bordered" style="margin-bottom: 0; font-size: 12px;">
                        <tr>
                            <th style="width: 40%;">rclone Binary</th>
                            <td>
                                <code><?php echo htmlspecialchars(\CWP\RcloneCWP\Rclone::getBinaryPath() ?: 'Not installed'); ?></code>
                                <span class="text-muted" style="margin-left: 8px;">(<?php echo htmlspecialchars(\CWP\RcloneCWP\Rclone::version()['version'] ?? 'unknown'); ?>)</span>
                            </td>
                        </tr>
                        <tr>
                            <th>rclone Config</th>
                            <td><code>/etc/rclone/rclone.conf</code> (mode 0600, root-owned)</td>
                        </tr>
                        <tr>
                            <th>Database</th>
                            <td>MariaDB / MySQL (<code>root_cwp</code>)</td>
                        </tr>
                        <tr>
                            <th>Supported Providers</th>
                            <td>
                                <span class="label label-primary" style="font-size: 10px;">Local</span>
                                <span class="label label-primary" style="font-size: 10px;">S3</span>
                                <span class="label label-primary" style="font-size: 10px;">GCS</span>
                                <span class="label label-primary" style="font-size: 10px;">Azure</span>
                                <span class="label label-primary" style="font-size: 10px;">B2</span>
                                <span class="label label-primary" style="font-size: 10px;">OneDrive</span>
                                <span class="label label-primary" style="font-size: 10px;">Dropbox</span>
                                <span class="label label-primary" style="font-size: 10px;">SFTP</span>
                                <span class="label label-primary" style="font-size: 10px;">WebDAV</span>
                                <span class="label label-primary" style="font-size: 10px;">Swift</span>
                                <span class="label label-primary" style="font-size: 10px;">Tencent</span>
                            </td>
                        </tr>
                        <tr>
                            <th>Encryption</th>
                            <td>
                                <span class="label label-success">AES-256-GCM</span>
                                <span class="text-muted" style="margin-left: 8px;">
                                    <?php echo \CWP\RcloneCWP\Encryption::hasKeyFile() ? 'Active (0600)' : 'Missing'; ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Security & Architecture Info -->
    <div class="row" style="margin-top: 20px;">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading" style="background: #2c3e50; color: #fff; padding: 10px 15px;">
                    <i class="fa fa-shield"></i> Security Model
                </div>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="media">
                                <div class="media-left">
                                    <i class="fa fa-memory fa-2x text-primary" style="font-size: 24px;"></i>
                                </div>
                                <div class="media-body">
                                    <h5 class="media-heading" style="font-weight: bold;">In-Memory Credential Passing</h5>
                                    <p class="text-muted" style="font-size: 12px;">
                                        Secrets are decrypted only in RAM and passed to rclone via dynamic environment variables. Zero plaintext credentials are ever written to disk outside the database.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="media">
                                <div class="media-left">
                                    <i class="fa fa-lock fa-2x text-success" style="font-size: 24px;"></i>
                                </div>
                                <div class="media-body">
                                    <h5 class="media-heading" style="font-weight: bold;">At-Rest AES-256-GCM</h5>
                                    <p class="text-muted" style="font-size: 12px;">
                                        Storage credentials in <code>rclone_destinations.config</code> are encrypted with 256-bit AES-GCM. A unique 12-byte IV is used per field.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="media">
                                <div class="media-left">
                                    <i class="fa fa-folder-open fa-2x text-warning" style="font-size: 24px;"></i>
                                </div>
                                <div class="media-body">
                                    <h5 class="media-heading" style="font-weight: bold;">Placement-Based Isolation</h5>
                                    <p class="text-muted" style="font-size: 12px;">
                                        Only the flat module dispatcher resides in the web root. All application logic, schemas, and keys remain off-htdocs under root-only permissions.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function csrfHeaders() {
    return { 'X-CSRF-Token': '<?php echo htmlspecialchars($csrfToken); ?>' };
}

function installCrontab() {
    var btn = document.getElementById('btn-install-crontab');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Installing...';

    fetch('?ajax=install_crontab', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, csrfHeaders()),
        body: 'csrf_token=<?php echo htmlspecialchars($csrfToken); ?>'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok || (data.message && data.message.indexOf('already') === -1)) {
            alert('Crontab installed successfully.');
        }
        refreshCrontabStatus();
    })
    .catch(function() {
        alert('Failed to install crontab. Check /var/log/rcloneCWP_cron.log for details.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-plus"></i> Install Crontab';
    });
}

function uninstallCrontab() {
    if (!confirm('Stop automatic backup scheduling? Existing schedules and backups are preserved.')) {
        return;
    }
    var btn = document.getElementById('btn-uninstall-crontab');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uninstalling...';

    fetch('?ajax=uninstall_crontab', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, csrfHeaders()),
        body: 'csrf_token=<?php echo htmlspecialchars($csrfToken); ?>'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        alert('Crontab uninstalled. Automatic scheduling stopped.');
        refreshCrontabStatus();
    })
    .catch(function() {
        alert('Failed to uninstall crontab.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-trash"></i> Uninstall Crontab';
    });
}

function refreshCrontabStatus() {
    fetch('?ajax=get_crontab_status')
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            location.reload();
        }
    });
}
</script>
