<?php
/**
 * rcloneCWP Logs View
 *
 * Recent log file reader and audit trail.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}

$logDir = RCLONE_LOG_DIR;
$todayLog = $logDir . '/rcloneCWP-' . date('Y-m-d') . '.log';
$logLines = [];

if (file_exists($todayLog) && is_readable($todayLog)) {
    $lines = file($todayLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        $logLines = array_slice($lines, -100);
        $logLines = array_reverse($logLines);
    }
}
?>

<div class="logs-view">
    <div class="row" style="margin-bottom: 15px;">
        <div class="col-xs-8">
            <h4 style="margin: 0;"><i class="fa fa-list-alt"></i> Recent Activity Logs</h4>
            <p class="text-muted" style="margin: 0; font-size: 12px;">
                Log file: <code><?php echo htmlspecialchars($todayLog); ?></code>
            </p>
        </div>
        <div class="col-xs-4 text-right">
            <button type="button" class="btn btn-default btn-sm" onclick="location.reload();">
                <i class="fa fa-refresh"></i> Refresh Log
            </button>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading" style="background: #2c3e50; color: #fff; padding: 8px 15px;">
            <i class="fa fa-terminal"></i> Log Tail (Last 100 entries, newest first)
        </div>
        <div class="panel-body" style="padding: 0; background: #1e1e1e;">
            <pre style="margin: 0; padding: 15px; background: transparent; border: none; color: #d4d4d4; font-size: 12px; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; max-height: 480px; overflow-y: auto; line-height: 1.5;"><?php
            if (empty($logLines)) {
                echo '<span style="color: #888;">No log entries for today (' . htmlspecialchars(date('Y-m-d')) . '). Operations will record here.</span>';
            } else {
                foreach ($logLines as $line) {
                    $color = '#d4d4d4';
                    if (strpos($line, 'ERROR') !== false || strpos($line, 'CRITICAL') !== false) {
                        $color = '#f44336';
                    } elseif (strpos($line, 'WARNING') !== false) {
                        $color = '#ff9800';
                    } elseif (strpos($line, 'SUCCESS') !== false || strpos($line, 'ok') !== false) {
                        $color = '#4caf50';
                    } elseif (strpos($line, 'DEBUG') !== false) {
                        $color = '#757575';
                    }
                    echo '<span style="color: ' . $color . ';">' . htmlspecialchars($line) . '</span>' . "\n";
                }
            }
            ?></pre>
        </div>
    </div>
</div>
