<?php
/**
 * rcloneCWP Schedules & Retention View
 *
 * Provides full UI management for backup schedules, cron expression presets,
 * next-run previews, system crontab status, and automated retention pruning.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not permitted');
}

$csrfToken = \CWP\RcloneCWP\CSRF::generateToken();
?>

<!-- ========================================================================= -->
<!-- SYSTEM CRON INTEGRATION BANNER                                            -->
<!-- ========================================================================= -->
<div id="crontab-status-card" class="panel panel-default" style="border-left: 4px solid #3498db; margin-bottom: 20px;">
    <div class="panel-body" style="padding: 15px 20px;">
        <div class="row" style="display: flex; align-items: center; flex-wrap: wrap;">
            <div class="col-sm-8">
                <h4 style="margin: 0 0 5px 0; font-weight: 600; color: #2c3e50;">
                    <i class="fa fa-clock-o text-primary" style="margin-right: 6px;"></i> System Crontab Status:
                    <span id="crontab-status-badge" class="label label-default" style="font-size: 12px;">Checking...</span>
                </h4>
                <div id="crontab-status-desc" class="text-muted" style="font-size: 12px;">
                    Loading system crontab configuration for <code>/etc/cron.d/rclonecwp</code>...
                </div>
            </div>
            <div class="col-sm-4 text-right">
                <button type="button" class="btn btn-sm btn-primary" id="btn-install-crontab" style="display: none;">
                    <i class="fa fa-plus-circle"></i> Enable System Cron
                </button>
                <button type="button" class="btn btn-sm btn-default" id="btn-uninstall-crontab" style="display: none;">
                    <i class="fa fa-times"></i> Disable System Cron
                </button>
                <button type="button" class="btn btn-sm btn-info" id="btn-open-prune-modal">
                    <i class="fa fa-recycle"></i> Prune Retention Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- SCHEDULES ACTION BAR                                                      -->
<!-- ========================================================================= -->
<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-6">
        <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: #2c3e50;">
            <i class="fa fa-calendar" style="color: #3498db; margin-right: 8px;"></i> Automated Backup Schedules
        </h3>
        <p class="text-muted" style="margin: 3px 0 0 0; font-size: 12px;">
            Configure cron expressions to automatically run backup jobs and enforce retention lifecycles.
        </p>
    </div>
    <div class="col-sm-6 text-right">
        <button type="button" class="btn btn-default" id="btn-refresh-schedules" style="margin-right: 5px;">
            <i class="fa fa-refresh"></i> Refresh
        </button>
        <button type="button" class="btn btn-success" id="btn-create-schedule">
            <i class="fa fa-plus"></i> New Schedule
        </button>
    </div>
</div>

<!-- ========================================================================= -->
<!-- SCHEDULES TABLE                                                           -->
<!-- ========================================================================= -->
<div class="table-responsive">
    <table class="table table-hover table-bordered table-striped" id="schedules-table" style="background: #fff;">
        <thead style="background: #eaeded; font-weight: 600;">
            <tr>
                <th style="width: 50px;">ID</th>
                <th>Target Backup Job</th>
                <th>Cron Expression</th>
                <th>Timezone</th>
                <th>Status</th>
                <th>Next Scheduled Run</th>
                <th>Last Executed</th>
                <th style="width: 170px;" class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody id="schedules-table-body">
            <tr>
                <td colspan="8" class="text-center" style="padding: 30px; color: #7f8c8d;">
                    <i class="fa fa-spinner fa-spin fa-2x"></i>
                    <div style="margin-top: 10px;">Loading schedules...</div>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADD / EDIT SCHEDULE                                                -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-schedule" tabindex="-1" role="dialog" aria-labelledby="modal-sched-title">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content" style="border-radius: 4px;">
            <form id="form-schedule">
                <input type="hidden" name="id" id="sched-id" value="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="modal-header" style="background: #2c3e50; color: #fff;">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title" id="modal-sched-title" style="font-weight: 600;">
                        <i class="fa fa-calendar-plus-o" style="color: #3498db; margin-right: 6px;"></i> Configure Backup Schedule
                    </h4>
                </div>

                <div class="modal-body" style="padding: 20px;">
                    <!-- Backup Job Selection -->
                    <div class="form-group" id="group-job-select">
                        <label for="sched-job-id" class="control-label" style="font-weight: 600;">
                            Target Backup Job <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" name="job_id" id="sched-job-id" required>
                            <option value="">-- Select Backup Job --</option>
                        </select>
                        <span class="help-block" style="font-size: 11px;">The backup job that will be triggered when this schedule fires.</span>
                    </div>

                    <!-- Preset Selector -->
                    <div class="form-group">
                        <label for="sched-preset" class="control-label" style="font-weight: 600;">Frequency Preset</label>
                        <select class="form-control" id="sched-preset">
                            <option value="daily" selected>Daily at 02:00 AM (0 2 * * *) - Recommended</option>
                            <option value="twicedaily">Twice Daily at 02:00 & 14:00 (0 2,14 * * *)</option>
                            <option value="hourly">Hourly at minute 0 (0 * * * *)</option>
                            <option value="weekly">Weekly on Sunday at 02:00 AM (0 2 * * 0)</option>
                            <option value="monthly">Monthly on 1st at 02:00 AM (0 2 1 * *)</option>
                            <option value="every_30min">Every 30 Minutes (0,30 * * * *)</option>
                            <option value="every_15min">Every 15 Minutes (*/15 * * * *)</option>
                            <option value="custom">Custom Cron Expression</option>
                        </select>
                    </div>

                    <!-- Cron Expression Input -->
                    <div class="form-group">
                        <label for="sched-cron" class="control-label" style="font-weight: 600;">
                            Cron Expression <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control font-monospace" name="cron_expression" id="sched-cron"
                               value="0 2 * * *" required style="font-family: monospace; font-size: 14px;">
                        <span class="help-block" style="font-size: 11px;">
                            Standard 5-field format: <code>minute hour day-of-month month day-of-week</code>
                        </span>
                    </div>

                    <!-- Timezone Selection -->
                    <div class="form-group">
                        <label for="sched-timezone" class="control-label" style="font-weight: 600;">Timezone</label>
                        <select class="form-control" name="timezone" id="sched-timezone">
                            <option value="UTC" selected>UTC (Coordinated Universal Time)</option>
                            <option value="America/New_York">America/New_York (EST/EDT)</option>
                            <option value="America/Chicago">America/Chicago (CST/CDT)</option>
                            <option value="America/Los_Angeles">America/Los_Angeles (PST/PDT)</option>
                            <option value="Europe/London">Europe/London (GMT/BST)</option>
                            <option value="Europe/Paris">Europe/Paris (CET/CEST)</option>
                            <option value="Asia/Dhaka">Asia/Dhaka (BST, UTC+6)</option>
                            <option value="Asia/Singapore">Asia/Singapore (SGT, UTC+8)</option>
                            <option value="Asia/Tokyo">Asia/Tokyo (JST, UTC+9)</option>
                            <option value="Australia/Sydney">Australia/Sydney (AEST/AEDT)</option>
                        </select>
                    </div>

                    <!-- Next 5 Runs Preview Box -->
                    <div class="panel panel-default" style="background: #fdfefe; margin-bottom: 15px; border-color: #d6dbdf;">
                        <div class="panel-heading" style="background: #edf2f7; padding: 6px 12px; font-size: 12px; font-weight: 600;">
                            <i class="fa fa-calendar-check-o text-primary"></i> Estimated Next 5 Executions
                        </div>
                        <div class="panel-body" style="padding: 10px 15px; font-size: 12px;">
                            <ul id="sched-preview-list" style="margin: 0; padding-left: 20px; font-family: monospace;">
                                <li>Computing schedule...</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Active Toggle -->
                    <div class="checkbox" style="margin-top: 5px;">
                        <label style="font-weight: 600;">
                            <input type="checkbox" name="active" id="sched-active" value="1" checked>
                            Enable schedule immediately
                        </label>
                    </div>
                </div>

                <div class="modal-footer" style="background: #f8f9fa;">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-schedule">
                        <i class="fa fa-save"></i> Save Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: RETENTION PRUNING                                                  -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-retention-prune" tabindex="-1" role="dialog" aria-labelledby="modal-prune-title">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content" style="border-radius: 4px;">
            <form id="form-retention-prune">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="modal-header" style="background: #2c3e50; color: #fff;">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title" id="modal-prune-title" style="font-weight: 600;">
                        <i class="fa fa-recycle" style="color: #3498db; margin-right: 6px;"></i> Run Retention Pruning
                    </h4>
                </div>

                <div class="modal-body" style="padding: 20px;">
                    <div class="alert alert-info" style="font-size: 12px;">
                        <i class="fa fa-info-circle"></i> Retention pruning automatically evaluates completed backup snapshots, purges expired archives from cloud storage, and removes database history records. The latest backup snapshot is always preserved.
                    </div>

                    <div class="form-group">
                        <label for="prune-job-id" class="control-label" style="font-weight: 600;">Target Job</label>
                        <select class="form-control" name="job_id" id="prune-job-id">
                            <option value="0" selected>All Backup Jobs</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="prune-policy" class="control-label" style="font-weight: 600;">Retention Policy</label>
                        <select class="form-control" name="policy" id="prune-policy">
                            <option value="days" selected>Time-based (Prune older than job's Retention Days)</option>
                            <option value="count">Count-based (Keep the most recent N backups)</option>
                            <option value="gfs">Grandfather-Father-Son (Daily 7d / Weekly 4w / Monthly 12m)</option>
                        </select>
                    </div>

                    <div class="form-group" id="group-keep-count" style="display: none;">
                        <label for="prune-keep-count" class="control-label" style="font-weight: 600;">Backups to Keep</label>
                        <input type="number" class="form-control" name="keep_count" id="prune-keep-count" value="10" min="1" max="1000">
                    </div>

                    <div class="checkbox">
                        <label style="font-weight: 600;">
                            <input type="checkbox" name="dry_run" id="prune-dry-run" value="1">
                            Dry Run (preview what would be pruned without deleting anything)
                        </label>
                    </div>

                    <div id="prune-result-box" style="display: none; margin-top: 15px;">
                        <hr style="margin: 10px 0;">
                        <h5><strong>Pruning Results:</strong></h5>
                        <div id="prune-result-content"></div>
                    </div>
                </div>

                <div class="modal-footer" style="background: #f8f9fa;">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger" id="btn-execute-prune">
                        <i class="fa fa-trash"></i> Execute Retention Pruning
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    var CSRF_TOKEN = '<?php echo $csrfToken; ?>';

    function escapeHtml(str) {
        if (str === null || str === undefined) return "";
        return $('<div>').text(String(str)).html();
    }

    var availableJobs = [];

    // Presets mapping
    var PRESETS = {
        'daily':       '0 2 * * *',
        'twicedaily':  '0 2,14 * * *',
        'hourly':      '0 * * * *',
        'weekly':      '0 2 * * 0',
        'monthly':     '0 2 1 * *',
        'every_30min': '0,30 * * * *',
        'every_15min': '*/15 * * * *'
    };

    // Load Crontab Status
    function loadCrontabStatus() {
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'get_crontab_status' },
            dataType: 'json',
            success: function(resp) {
                if (!resp.ok || !resp.status) return;
                var st = resp.status;
                if (st.installed) {
                    $('#crontab-status-badge')
                        .removeClass('label-default label-danger')
                        .addClass('label-success')
                        .html('<i class="fa fa-check"></i> Active (/etc/cron.d/rclonecwp)');
                    $('#crontab-status-desc').html(
                        'Automated scheduled backups and daily retention cleaning are actively managed by system cron.'
                    );
                    $('#btn-install-crontab').hide();
                    $('#btn-uninstall-crontab').show();
                } else {
                    $('#crontab-status-badge')
                        .removeClass('label-default label-success')
                        .addClass('label-warning')
                        .html('<i class="fa fa-exclamation-triangle"></i> Not Installed');
                    $('#crontab-status-desc').html(
                        'System cron entry is not active in <code>/etc/cron.d/rclonecwp</code>. Click "Enable System Cron" to enable automated execution.'
                    );
                    $('#btn-install-crontab').show();
                    $('#btn-uninstall-crontab').hide();
                }
            }
        });
    }

    // Load Backup Jobs for selectors
    function loadJobsDropdown() {
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_jobs' },
            dataType: 'json',
            success: function(resp) {
                if (!resp.ok || !resp.jobs) return;
                availableJobs = resp.jobs;

                var schedOpts = '<option value="">-- Select Backup Job --</option>';
                var pruneOpts = '<option value="0" selected>All Backup Jobs</option>';

                $.each(availableJobs, function(i, j) {
                    schedOpts += '<option value="' + j.id + '">' + escapeHtml(j.name) + ' (Job #' + j.id + ')</option>';
                    pruneOpts += '<option value="' + j.id + '">' + escapeHtml(j.name) + ' (Job #' + j.id + ')</option>';
                });

                $('#sched-job-id').html(schedOpts);
                $('#prune-job-id').html(pruneOpts);
            }
        });
    }

    // Load Schedules Table
    function loadSchedules() {
        $('#schedules-table-body').html(
            '<tr><td colspan="8" class="text-center" style="padding: 30px; color: #7f8c8d;">' +
            '<i class="fa fa-spinner fa-spin fa-2x"></i>' +
            '<div style="margin-top: 10px;">Loading schedules...</div></td></tr>'
        );

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_schedules' },
            dataType: 'json',
            success: function(resp) {
                if (!resp.ok || !resp.schedules || resp.schedules.length === 0) {
                    $('#schedules-table-body').html(
                        '<tr><td colspan="8" class="text-center text-muted" style="padding: 40px 20px;">' +
                        '<i class="fa fa-calendar-times-o fa-3x" style="color: #bdc3c7; margin-bottom: 10px;"></i>' +
                        '<div style="font-size: 15px; font-weight: 600; color: #7f8c8d;">No automated schedules configured yet.</div>' +
                        '<p style="margin-top: 5px;">Create a schedule to automate your cloud backup jobs.</p>' +
                        '<button class="btn btn-sm btn-success" id="btn-first-schedule" style="margin-top: 10px;">' +
                        '<i class="fa fa-plus"></i> Create First Schedule</button>' +
                        '</td></tr>'
                    );
                    return;
                }

                var html = '';
                $.each(resp.schedules, function(i, s) {
                    var statusBadge = s.active
                        ? '<span class="label label-success"><i class="fa fa-check"></i> Active</span>'
                        : '<span class="label label-default"><i class="fa fa-pause"></i> Paused</span>';

                    var nextRunHtml = s.next_run
                        ? '<strong>' + escapeHtml(s.next_run) + '</strong>'
                        : '<span class="text-muted">--</span>';

                    var lastRunHtml = s.last_run
                        ? '<span class="text-muted" style="font-size: 12px;">' + escapeHtml(s.last_run) + '</span>'
                        : '<span class="text-muted">Never executed</span>';

                    html += '<tr data-id="' + s.id + '">' +
                        '<td><code>#' + s.id + '</code></td>' +
                        '<td>' +
                            '<div style="font-weight: 600; color: #2c3e50;">' + escapeHtml(s.job_name) + '</div>' +
                            '<small class="text-muted"><i class="fa fa-cloud"></i> ' + escapeHtml(s.destination_name) + ' &bull; Keep ' + s.retention_days + 'd</small>' +
                        '</td>' +
                        '<td>' +
                            '<code style="font-size: 13px; font-weight: bold; background: #eef2f7; padding: 2px 6px; border-radius: 3px;">' + escapeHtml(s.cron_expression) + '</code>' +
                        '</td>' +
                        '<td><small class="text-muted">' + escapeHtml(s.timezone) + '</small></td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td>' + nextRunHtml + '</td>' +
                        '<td>' + lastRunHtml + '</td>' +
                        '<td class="text-center">' +
                            '<button class="btn btn-xs btn-success btn-run-now" data-id="' + s.id + '" data-jobid="' + s.job_id + '" title="Run Job Now">' +
                                '<i class="fa fa-play"></i>' +
                            '</button> ' +
                            '<button class="btn btn-xs ' + (s.active ? 'btn-warning' : 'btn-info') + ' btn-toggle-active" data-id="' + s.id + '" data-active="' + (s.active ? 1 : 0) + '" title="' + (s.active ? 'Pause Schedule' : 'Resume Schedule') + '">' +
                                '<i class="fa fa-' + (s.active ? 'pause' : 'play') + '"></i>' +
                            '</button> ' +
                            '<button class="btn btn-xs btn-default btn-edit-schedule" data-id="' + s.id + '" title="Edit Schedule">' +
                                '<i class="fa fa-pencil"></i>' +
                            '</button> ' +
                            '<button class="btn btn-xs btn-danger btn-delete-schedule" data-id="' + s.id + '" title="Delete Schedule">' +
                                '<i class="fa fa-trash"></i>' +
                            '</button>' +
                        '</td>' +
                    '</tr>';
                });

                $('#schedules-table-body').html(html);
            }
        });
    }

    // Compute Cron Next Runs Preview
    function updateCronPreview() {
        var expr = $('#sched-cron').val().trim();
        var tz = $('#sched-timezone').val();

        if (!expr) {
            $('#sched-preview-list').html('<li class="text-muted">Enter a valid cron expression.</li>');
            return;
        }

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'preview_cron', cron_expression: expr, timezone: tz },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok && resp.next_runs) {
                    var list = '';
                    $.each(resp.next_runs, function(i, r) {
                        list += '<li>' + escapeHtml(r) + '</li>';
                    });
                    $('#sched-preview-list').html(list);
                } else {
                    $('#sched-preview-list').html('<li class="text-danger">' + escapeHtml(resp.error || 'Invalid cron expression') + '</li>');
                }
            }
        });
    }

    // Handlers: Preset change
    $('#sched-preset').on('change', function() {
        var p = $(this).val();
        if (p !== 'custom' && PRESETS[p]) {
            $('#sched-cron').val(PRESETS[p]);
            updateCronPreview();
        }
    });

    $('#sched-cron, #sched-timezone').on('input change', function() {
        // Debounce preview updates
        clearTimeout(window._cronPreviewTimeout);
        window._cronPreviewTimeout = setTimeout(updateCronPreview, 300);
    });

    // Prune Policy change
    $('#prune-policy').on('change', function() {
        if ($(this).val() === 'count') {
            $('#group-keep-count').slideDown(150);
        } else {
            $('#group-keep-count').slideUp(150);
        }
    });

    // Enable/Disable Crontab
    $('#btn-install-crontab').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Enabling...');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax: 1, action: 'install_crontab', csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="fa fa-plus-circle"></i> Enable System Cron');
                if (resp.ok) {
                    loadCrontabStatus();
                } else {
                    alert('Error enabling system cron: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    });

    $('#btn-uninstall-crontab').on('click', function() {
        if (!confirm('Disable automated background execution in /etc/cron.d/rclonecwp?')) return;
        var btn = $(this);
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Disabling...');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax: 1, action: 'uninstall_crontab', csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="fa fa-times"></i> Disable System Cron');
                if (resp.ok) {
                    loadCrontabStatus();
                } else {
                    alert('Error disabling system cron: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    });

    // Open Modal: New Schedule
    $('#btn-create-schedule').on('click', function() {
        $('#sched-id').val('');
        $('#modal-sched-title').html('<i class="fa fa-calendar-plus-o text-primary"></i> Create Backup Schedule');
        $('#form-schedule')[0].reset();
        $('#sched-preset').val('daily');
        $('#sched-cron').val(PRESETS['daily']);
        $('#sched-active').prop('checked', true);
        $('#group-job-select').show();
        $('#modal-schedule').modal('show');
        updateCronPreview();
    });

    // Open Modal: New Schedule (from empty state button)
    $(document).on('click', '#btn-first-schedule', function() {
        $('#sched-id').val('');
        $('#modal-sched-title').html('<i class="fa fa-calendar-plus-o text-primary"></i> Create Backup Schedule');
        $('#form-schedule')[0].reset();
        $('#sched-preset').val('daily');
        $('#sched-cron').val(PRESETS['daily']);
        $('#sched-active').prop('checked', true);
        $('#group-job-select').show();
        $('#modal-schedule').modal('show');
        updateCronPreview();
    });

    // Open Modal: Prune
    $('#btn-open-prune-modal').on('click', function() {
        $('#prune-result-box').hide();
        $('#modal-retention-prune').modal('show');
    });

    // Save Schedule
    $('#form-schedule').on('submit', function(e) {
        e.preventDefault();
        var btn = $('#btn-save-schedule');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax=1&action=save_schedule',
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Schedule');
                if (resp.ok) {
                    $('#modal-schedule').modal('hide');
                    loadSchedules();
                } else {
                    alert('Failed to save schedule: ' + (resp.error || 'Unknown error'));
                }
            },
            error: function(xhr) {
                btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Schedule');
                alert('Request failed. Please check server logs.');
            }
        });
    });

    // Edit Schedule
    $(document).on('click', '.btn-edit-schedule', function() {
        var id = $(this).data('id');
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'get_schedule', id: id },
            dataType: 'json',
            success: function(resp) {
                if (!resp.ok || !resp.schedule) {
                    alert('Schedule not found');
                    return;
                }
                var s = resp.schedule;
                $('#sched-id').val(s.id);
                $('#sched-job-id').val(s.job_id);
                $('#sched-cron').val(s.cron_expression);
                $('#sched-timezone').val(s.timezone || 'UTC');
                $('#sched-active').prop('checked', s.active);
                $('#modal-sched-title').html('<i class="fa fa-pencil text-primary"></i> Edit Schedule #' + s.id);
                $('#sched-preset').val('custom');
                $('#modal-schedule').modal('show');
                updateCronPreview();
            }
        });
    });

    // Toggle Active
    $(document).on('click', '.btn-toggle-active', function() {
        var id = $(this).data('id');
        var cur = $(this).data('active');
        var newAct = cur ? 0 : 1;

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax: 1, action: 'toggle_schedule', id: id, active: newAct, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok) {
                    loadSchedules();
                } else {
                    alert('Error updating status: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    });

    // Delete Schedule
    $(document).on('click', '.btn-delete-schedule', function() {
        var id = $(this).data('id');
        if (!confirm('Are you sure you want to delete Schedule #' + id + '?')) return;

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax: 1, action: 'delete_schedule', id: id, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok) {
                    loadSchedules();
                } else {
                    alert('Error deleting schedule: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    });

    // Run Job Now
    $(document).on('click', '.btn-run-now', function() {
        var btn = $(this);
        var jobId = btn.data('jobid');
        if (!confirm('Execute backup job #' + jobId + ' immediately?')) return;

        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax: 1, action: 'run_job', id: jobId, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="fa fa-play"></i>');
                if (resp.ok) {
                    alert('Backup job completed successfully!');
                    loadSchedules();
                } else {
                    alert('Backup job error: ' + (resp.error || 'Unknown error'));
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fa fa-play"></i>');
                alert('Request timed out or failed.');
            }
        });
    });

    // Execute Retention Pruning
    $('#form-retention-prune').on('submit', function(e) {
        e.preventDefault();
        var btn = $('#btn-execute-prune');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Pruning...');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax=1&action=prune_retention',
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="fa fa-trash"></i> Execute Retention Pruning');
                if (resp.ok) {
                    var out = '<div class="alert alert-success" style="font-size: 12px; margin-bottom: 0;">' +
                              '<strong>' + (resp.dry_run ? 'Dry Run Preview' : 'Pruning Completed') + ':</strong><br>' +
                              'Snapshots pruned: ' + (resp.pruned ? resp.pruned.length : (resp.total_pruned || 0)) + '<br>' +
                              'Snapshots retained: ' + (resp.kept ? resp.kept.length : 'All active') + '<br>' +
                              'Bytes freed: ' + (resp.bytes_freed ? (resp.bytes_freed + ' bytes') : '0 bytes') +
                              '</div>';
                    $('#prune-result-content').html(out);
                    $('#prune-result-box').slideDown(150);
                } else {
                    alert('Pruning error: ' + (resp.error || 'Unknown error'));
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fa fa-trash"></i> Execute Retention Pruning');
                alert('Request failed.');
            }
        });
    });

    $('#btn-refresh-schedules').on('click', function() {
        loadCrontabStatus();
        loadSchedules();
    });

    // Initial load
    loadCrontabStatus();
    loadJobsDropdown();
    loadSchedules();

})(jQuery);
</script>
