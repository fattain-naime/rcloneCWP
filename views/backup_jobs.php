<?php
/**
 * rcloneCWP Backup Jobs View
 *
 * Job list, creation/editing modal, instant execution runner,
 * and execution history diagnostics.
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}
?>

<div class="jobs-view">
    <!-- Action Bar -->
    <div class="row" style="margin-bottom: 20px;">
        <div class="col-sm-6">
            <h4 style="margin: 0; font-weight: 600; color: #2c3e50;">
                <i class="fa fa-tasks" style="color: #3498db; margin-right: 6px;"></i>
                Backup Jobs
            </h4>
            <p class="text-muted" style="margin: 4px 0 0 0; font-size: 13px;">
                Manage automated multi-component CWP account backup jobs and cloud synchronization.
            </p>
        </div>
        <div class="col-sm-6 text-right">
            <button type="button" class="btn btn-default" id="btn-refresh-jobs" style="margin-right: 6px;">
                <i class="fa fa-refresh"></i> Refresh
            </button>
            <button type="button" class="btn btn-info" id="btn-view-all-history" style="margin-right: 6px;">
                <i class="fa fa-history"></i> Run History
            </button>
            <button type="button" class="btn btn-primary" id="btn-add-job">
                <i class="fa fa-plus-circle"></i> Create Backup Job
            </button>
        </div>
    </div>

    <!-- Alert container -->
    <div id="jobs-alert-box"></div>

    <!-- Jobs Table -->
    <div class="panel panel-default" style="border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
        <div class="table-responsive">
            <table class="table table-hover table-striped" id="table-backup-jobs" style="margin-bottom: 0; vertical-align: middle;">
                <thead style="background: #f8f9fa;">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Job Name & Type</th>
                        <th>Storage Destination</th>
                        <th>Target Accounts</th>
                        <th>Components</th>
                        <th style="width: 90px;">Retention</th>
                        <th>Last Execution</th>
                        <th style="width: 200px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="jobs-table-body">
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 30px; color: #7f8c8d;">
                            <i class="fa fa-spinner fa-spin fa-2x"></i>
                            <div style="margin-top: 10px;">Loading backup jobs...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADD / EDIT BACKUP JOB                                              -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-backup-job" tabindex="-1" role="dialog" aria-labelledby="modal-job-title" data-backdrop="static">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 4px;">
            <div class="modal-header" style="background: #2c3e50; color: #fff; border-top-left-radius: 4px; border-top-right-radius: 4px;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="modal-job-title" style="font-weight: 600;">
                    <i class="fa fa-plus-circle" style="color: #3498db; margin-right: 6px;"></i> Create Backup Job
                </h4>
            </div>
            <form id="form-backup-job">
                <input type="hidden" name="id" id="job-id" value="0">
                <div class="modal-body" style="padding: 20px 25px;">
                    <div id="modal-job-alert"></div>

                    <!-- Row 1: Name & Destination -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="job-name">Job Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="job-name" name="name" required placeholder="e.g. Daily Production Offsite" maxlength="100">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="job-destination">Storage Destination <span class="text-danger">*</span></label>
                                <select class="form-control" id="job-destination" name="destination_id" required>
                                    <option value="">-- Select Destination --</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Type & Retention -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="job-type">Backup Mode <span class="text-danger">*</span></label>
                                <select class="form-control" id="job-type" name="job_type">
                                    <option value="incremental" selected>Incremental (Sync new/modified files directly to destination)</option>
                                    <option value="full">Full Snapshot (Complete tar/gz archive for every run)</option>
                                    <option value="selective">Selective (Only chosen components/databases)</option>
                                </select>
                                <span class="help-block" style="font-size: 11px; margin-bottom: 0;">
                                    Incremental drastically reduces cloud egress and transfer time by using checksum tracking.
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="job-retention">Retention Policy (Days)</label>
                                <input type="number" class="form-control" id="job-retention" name="retention_days" value="7" min="1" max="3650">
                                <span class="help-block" style="font-size: 11px; margin-bottom: 0;">
                                    Backups older than this number of days will be automatically pruned from the destination.
                                </span>
                            </div>
                        </div>
                    </div>

                    <hr style="margin: 15px 0;">

                    <!-- Row 3: Target Accounts Selection -->
                    <div class="form-group">
                        <label>Target CWP User Accounts <span class="text-danger">*</span></label>
                        <div style="margin-bottom: 8px;">
                            <label class="radio-inline" style="font-weight: bold;">
                                <input type="radio" name="account_mode" id="acc-mode-all" value="all" checked>
                                Back up ALL current and future CWP accounts (<code>*</code>)
                            </label>
                            <label class="radio-inline" style="font-weight: bold; margin-left: 15px;">
                                <input type="radio" name="account_mode" id="acc-mode-selected" value="selected">
                                Select specific accounts
                            </label>
                        </div>

                        <div id="accounts-selection-box" style="display: none; background: #fdfdfd; border: 1px solid #ddd; padding: 12px; border-radius: 4px; max-height: 160px; overflow-y: auto;">
                            <div id="accounts-checkboxes-list">
                                <span class="text-muted">Loading user accounts...</span>
                            </div>
                        </div>
                    </div>

                    <!-- Row 4: Account Components -->
                    <div class="form-group">
                        <label>Included Account Components</label>
                        <div class="row">
                            <div class="col-sm-4">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="components[]" value="files" checked>
                                        <i class="fa fa-folder-open text-primary"></i> <strong>User Files & Web</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="components[]" value="databases" checked>
                                        <i class="fa fa-database text-success"></i> <strong>MariaDB Databases</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="components[]" value="dns" checked>
                                        <i class="fa fa-globe text-info"></i> <strong>DNS Zone Records</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="components[]" value="ssl" checked>
                                        <i class="fa fa-lock text-warning"></i> <strong>SSL Certificates & Keys</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="components[]" value="mail" checked>
                                        <i class="fa fa-envelope text-danger"></i> <strong>Email & Postfix Accounts</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="components[]" value="cron" checked>
                                        <i class="fa fa-clock-o text-muted"></i> <strong>User Crontabs</strong>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <span class="help-block" style="font-size: 11px; margin-bottom: 0;">
                            Account metadata (packages, domains, quotas) is always automatically included.
                        </span>
                    </div>

                    <hr style="margin: 15px 0;">

                    <!-- Row 5: Flags & Compression -->
                    <div class="row">
                        <div class="col-sm-4">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="compression" id="job-compression" value="1" checked>
                                    <strong>Enable Gzip Compression</strong>
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="notify_on_failure" id="job-notify-fail" value="1" checked>
                                    Notify on Failure
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="notify_on_success" id="job-notify-succ" value="1">
                                    Notify on Success
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: #f8f9fa;">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-job">
                        <i class="fa fa-save"></i> Save Backup Job
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: BACKUP RUN HISTORY                                                 -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-backup-history" tabindex="-1" role="dialog" aria-labelledby="modal-hist-title">
    <div class="modal-dialog modal-lg" role="document" style="width: 85%;">
        <div class="modal-content" style="border-radius: 4px;">
            <div class="modal-header" style="background: #2c3e50; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="modal-hist-title" style="font-weight: 600;">
                    <i class="fa fa-history" style="color: #3498db; margin-right: 6px;"></i> Backup Execution History
                </h4>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered table-striped" style="font-size: 13px;">
                        <thead style="background: #eaeded;">
                            <tr>
                                <th style="width: 60px;">Run #</th>
                                <th>Job Name</th>
                                <th>Type</th>
                                <th>Destination</th>
                                <th>Started At</th>
                                <th>Duration</th>
                                <th>Files</th>
                                <th>Transferred</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="history-table-body">
                            <tr>
                                <td colspan="9" class="text-center" style="padding: 30px;">
                                    <i class="fa fa-spinner fa-spin"></i> Loading execution history...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="background: #f8f9fa;">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            </div>
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

    var availableDestinations = [];
    var availableAccounts = [];

    // Helper: format bytes
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Helper: format duration
    function formatDuration(sec) {
        if (sec === null || sec === undefined) return '--';
        if (sec < 60) return sec + 's';
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return m + 'm ' + s + 's';
    }

    // Load Backup Jobs
    function loadJobs() {
        $('#jobs-table-body').html(
            '<tr><td colspan="8" class="text-center" style="padding: 30px; color: #7f8c8d;">' +
            '<i class="fa fa-spinner fa-spin fa-2x"></i>' +
            '<div style="margin-top: 10px;">Loading backup jobs...</div></td></tr>'
        );

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_jobs' },
            dataType: 'json',
            success: function(resp) {
                if (!resp.ok) {
                    $('#jobs-table-body').html(
                        '<tr><td colspan="8" class="text-center text-danger" style="padding: 20px;">' +
                        '<i class="fa fa-exclamation-triangle"></i> ' + (resp.error || 'Failed to load jobs') + '</td></tr>'
                    );
                    return;
                }

                var jobs = resp.jobs || [];
                $('#tab-badge-jobs-count').text(jobs.length);

                if (jobs.length === 0) {
                    $('#jobs-table-body').html(
                        '<tr><td colspan="8" class="text-center" style="padding: 40px 20px; color: #7f8c8d;">' +
                        '<i class="fa fa-folder-open-o fa-3x" style="color: #bdc3c7; margin-bottom: 10px;"></i>' +
                        '<p style="font-size: 15px; margin: 0 0 10px 0;">No backup jobs defined yet.</p>' +
                        '<button class="btn btn-primary btn-sm" id="btn-empty-add-job"><i class="fa fa-plus-circle"></i> Create Your First Backup Job</button>' +
                        '</td></tr>'
                    );
                    $('#btn-empty-add-job').on('click', function() { openJobModal(); });
                    return;
                }

                var html = '';
                $.each(jobs, function(i, j) {
                    var typeBadge = '<span class="label label-info">Incremental</span>';
                    if (j.job_type === 'full') {
                        typeBadge = '<span class="label label-primary">Full</span>';
                    } else if (j.job_type === 'selective') {
                        typeBadge = '<span class="label label-warning">Selective</span>';
                    }

                    // Accounts text
                    var accountsText = '';
                    if (j.accounts.indexOf('*') !== -1 || j.accounts.indexOf('all') !== -1) {
                        accountsText = '<span class="label label-default" style="font-weight: bold;">All Accounts (*)</span>';
                    } else {
                        accountsText = '<span class="badge" style="background: #2c3e50;">' + j.accounts.length + ' accounts</span>';
                    }

                    // Components list
                    var compBadges = [];
                    $.each(j.components || [], function(ci, c) {
                        if (c === 'files') compBadges.push('<span title="User Files" style="color: #3498db; margin-right: 4px;"><i class="fa fa-folder-open"></i></span>');
                        if (c === 'databases') compBadges.push('<span title="Databases" style="color: #27ae60; margin-right: 4px;"><i class="fa fa-database"></i></span>');
                        if (c === 'dns') compBadges.push('<span title="DNS Zones" style="color: #17a2b8; margin-right: 4px;"><i class="fa fa-globe"></i></span>');
                        if (c === 'ssl') compBadges.push('<span title="SSL Certificates" style="color: #f39c12; margin-right: 4px;"><i class="fa fa-lock"></i></span>');
                        if (c === 'mail') compBadges.push('<span title="Mailboxes" style="color: #e74c3c; margin-right: 4px;"><i class="fa fa-envelope"></i></span>');
                        if (c === 'cron') compBadges.push('<span title="Crontabs" style="color: #7f8c8d; margin-right: 4px;"><i class="fa fa-clock-o"></i></span>');
                    });

                    // Last run status
                    var lastRunHtml = '<span class="text-muted" style="font-size: 12px;">Never executed</span>';
                    if (j.last_run) {
                        var lr = j.last_run;
                        var stBadge = '<span class="label label-success"><i class="fa fa-check"></i> Success</span>';
                        if (lr.status === 'running') {
                            stBadge = '<span class="label label-warning"><i class="fa fa-spinner fa-spin"></i> Running</span>';
                        } else if (lr.status === 'failed') {
                            stBadge = '<span class="label label-danger" title="' + escapeHtml(lr.error_message || '') + '"><i class="fa fa-times"></i> Failed</span>';
                        }

                        lastRunHtml = '<div>' + stBadge + ' <small class="text-muted" style="margin-left: 4px;">' + (lr.started_at ? lr.started_at.substring(5, 16) : '') + '</small></div>' +
                                      '<div style="font-size: 11px; color: #7f8c8d; margin-top: 2px;">' +
                                      formatDuration(lr.duration_seconds) + ' &bull; ' + formatBytes(lr.bytes_transferred) +
                                      '</div>';
                    }

                    html += '<tr data-id="' + j.id + '">' +
                        '<td><code>#' + j.id + '</code></td>' +
                        '<td>' +
                            '<div style="font-weight: 600; color: #2c3e50;">' + $('<div>').text(j.name).html() + '</div>' +
                            '<div style="margin-top: 3px;">' + typeBadge + '</div>' +
                        '</td>' +
                        '<td>' +
                            '<div><i class="fa fa-cloud text-primary"></i> <strong>' + $('<div>').text(j.destination_name).html() + '</strong></div>' +
                            '<small class="text-muted">' + (j.destination_type || '').toUpperCase() + '</small>' +
                        '</td>' +
                        '<td>' + accountsText + '</td>' +
                        '<td>' + compBadges.join('') + '</td>' +
                        '<td><span class="label label-default">' + j.retention_days + ' days</span></td>' +
                        '<td>' + lastRunHtml + '</td>' +
                        '<td style="text-align: right;">' +
                            '<div class="btn-group">' +
                                '<button class="btn btn-xs btn-success btn-run-job" data-id="' + j.id + '" title="Run Now">' +
                                    '<i class="fa fa-play"></i> Run' +
                                '</button>' +
                                '<button class="btn btn-xs btn-info btn-job-history" data-id="' + j.id + '" title="History">' +
                                    '<i class="fa fa-history"></i>' +
                                '</button>' +
                                '<button class="btn btn-xs btn-default btn-edit-job" data-id="' + j.id + '" title="Edit">' +
                                    '<i class="fa fa-pencil"></i>' +
                                '</button>' +
                                '<button class="btn btn-xs btn-danger btn-delete-job" data-id="' + j.id + '" title="Delete">' +
                                    '<i class="fa fa-trash"></i>' +
                                '</button>' +
                            '</div>' +
                        '</td>' +
                    '</tr>';
                });

                $('#jobs-table-body').html(html);
            },
            error: function() {
                $('#jobs-table-body').html(
                    '<tr><td colspan="8" class="text-center text-danger" style="padding: 20px;">' +
                    '<i class="fa fa-exclamation-triangle"></i> Network error loading backup jobs.</td></tr>'
                );
            }
        });
    }

    // Load destinations for the dropdown
    function loadDestinationsDropdown() {
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_destinations' },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok && resp.destinations) {
                    availableDestinations = resp.destinations;
                    var opts = '<option value="">-- Select Destination --</option>';
                    $.each(availableDestinations, function(i, d) {
                        if (d.enabled) {
                            opts += '<option value="' + d.id + '">' + $('<div>').text(d.name + ' (' + d.type_name + ')').html() + '</option>';
                        }
                    });
                    $('#job-destination').html(opts);
                }
            }
        });
    }

    // Load user accounts for the selection list
    function loadAccountsList() {
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_accounts' },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok && resp.accounts) {
                    availableAccounts = resp.accounts;
                    var html = '';
                    $.each(availableAccounts, function(i, a) {
                        var uName = escapeHtml(a.username);
                        var uDomain = escapeHtml(a.primary_domain || "no domain");
                        var uDbs = escapeHtml(a.databases);
                        html += '<div class="checkbox" style="margin: 4px 0;">' +
                            '<label>' +
                                '<input type="checkbox" name="accounts[]" value="' + uName + '"> ' +
                                '<strong>' + uName + '</strong> ' +
                                '<span class="text-muted">(' + uDomain + ' &bull; ' + uDbs + ' DBs)</span>' +
                            '</label>' +
                        '</div>';
                    });
                    $('#accounts-checkboxes-list').html(html);
                }
            }
        });
    }

    // Open Create/Edit Modal
    function openJobModal(jobId) {
        $('#form-backup-job')[0].reset();
        $('#modal-job-alert').empty();
        $('#acc-mode-all').prop('checked', true);
        $('#accounts-selection-box').hide();
        loadDestinationsDropdown();

        if (jobId) {
            $('#modal-job-title').html('<i class="fa fa-pencil" style="color: #3498db; margin-right: 6px;"></i> Edit Backup Job #' + jobId);
            $.ajax({
                url: window.location.href,
                type: 'GET',
                data: { ajax: 1, action: 'get_job', id: jobId },
                dataType: 'json',
                success: function(resp) {
                    if (resp.ok && resp.job) {
                        var j = resp.job;
                        $('#job-id').val(j.id);
                        $('#job-name').val(j.name);
                        $('#job-destination').val(j.destination_id);
                        $('#job-type').val(j.job_type);
                        $('#job-retention').val(j.retention_days);
                        $('#job-compression').prop('checked', j.compression == 1);
                        $('#job-notify-succ').prop('checked', j.notify_on_success == 1);
                        $('#job-notify-fail').prop('checked', j.notify_on_failure == 1);

                        // Accounts
                        if (j.accounts.indexOf('*') !== -1 || j.accounts.indexOf('all') !== -1) {
                            $('#acc-mode-all').prop('checked', true);
                            $('#accounts-selection-box').hide();
                        } else {
                            $('#acc-mode-selected').prop('checked', true);
                            $('#accounts-selection-box').show();
                            $('#accounts-checkboxes-list input[type="checkbox"]').each(function() {
                                var val = $(this).val();
                                $(this).prop('checked', j.accounts.indexOf(val) !== -1);
                            });
                        }

                        // Components
                        $('input[name="components[]"]').each(function() {
                            var val = $(this).val();
                            $(this).prop('checked', j.components.indexOf(val) !== -1);
                        });

                        $('#modal-backup-job').modal('show');
                    }
                }
            });
        } else {
            $('#job-id').val(0);
            $('#modal-job-title').html('<i class="fa fa-plus-circle" style="color: #3498db; margin-right: 6px;"></i> Create Backup Job');
            $('#modal-backup-job').modal('show');
        }
    }

    // Toggle Account mode
    $('input[name="account_mode"]').on('change', function() {
        if ($(this).val() === 'selected') {
            $('#accounts-selection-box').slideDown(150);
        } else {
            $('#accounts-selection-box').slideUp(150);
        }
    });

    // Save Backup Job Form Submit
    $('#form-backup-job').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#btn-save-job');
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        $('#modal-job-alert').empty();

        var formData = $(this).serializeArray();
        formData.push({ name: 'ajax', value: 1 });
        formData.push({ name: 'action', value: 'save_job' });
        formData.push({ name: 'csrf_token', value: CSRF_TOKEN });

        // If all accounts selected, force accounts[] = ['*']
        if ($('#acc-mode-all').is(':checked')) {
            // Remove any accounts[] from serialization
            formData = formData.filter(function(item) { return item.name !== 'accounts[]'; });
            formData.push({ name: 'accounts[]', value: '*' });
        }

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(resp) {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Backup Job');
                if (resp.ok) {
                    $('#modal-backup-job').modal('hide');
                    loadJobs();
                } else {
                    $('#modal-job-alert').html(
                        '<div class="alert alert-danger" style="margin-bottom: 15px;">' +
                        '<i class="fa fa-exclamation-circle"></i> ' + (resp.error || 'Failed to save job.') +
                        '</div>'
                    );
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Backup Job');
                $('#modal-job-alert').html(
                    '<div class="alert alert-danger" style="margin-bottom: 15px;">' +
                    '<i class="fa fa-exclamation-circle"></i> Server communication error.' +
                    '</div>'
                );
            }
        });
    });

    // Run Job Now
    $(document).on('click', '.btn-run-job', function(e) {
        e.preventDefault();
        var jobId = $(this).data('id');
        var $btn = $(this);

        if (!confirm('Start execution of backup job #' + jobId + ' now? This will capture user accounts and transfer them to the remote destination.')) {
            return;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Starting...');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax: 1,
                action: 'run_job_now',
                id: jobId,
                csrf_token: CSRF_TOKEN
            },
            dataType: 'json',
            success: function(resp) {
                $btn.prop('disabled', false).html('<i class="fa fa-play"></i> Run');
                if (resp.ok) {
                    alert('Backup job #' + jobId + ' completed successfully in ' + resp.duration + 's!\n' +
                          'Files: ' + resp.files_count + '\n' +
                          'Transferred: ' + formatBytes(resp.bytes));
                    loadJobs();
                } else {
                    alert('Backup execution error: ' + (resp.error || 'Unknown error occurred.'));
                    loadJobs();
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-play"></i> Run');
                alert('Network error while initiating backup job execution.');
            }
        });
    });

    // View Job History
    $(document).on('click', '.btn-job-history, #btn-view-all-history', function(e) {
        e.preventDefault();
        var jobId = $(this).data('id') || 0;
        $('#history-table-body').html('<tr><td colspan="9" class="text-center" style="padding: 25px;"><i class="fa fa-spinner fa-spin"></i> Loading execution history...</td></tr>');
        $('#modal-backup-history').modal('show');

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_history', job_id: jobId },
            dataType: 'json',
            success: function(resp) {
                if (!resp.ok || !resp.history || resp.history.length === 0) {
                    $('#history-table-body').html('<tr><td colspan="9" class="text-center text-muted" style="padding: 25px;">No historical runs recorded.</td></tr>');
                    return;
                }

                var html = '';
                $.each(resp.history, function(i, h) {
                    var st = '<span class="label label-success"><i class="fa fa-check"></i> Completed</span>';
                    if (h.status === 'running') st = '<span class="label label-warning"><i class="fa fa-spinner fa-spin"></i> Running</span>';
                    if (h.status === 'failed') st = '<span class="label label-danger" title="' + escapeHtml(h.error_message || '') + '"><i class="fa fa-times"></i> Failed</span>';

                    html += '<tr>' +
                        '<td><code>#' + h.id + '</code></td>' +
                        '<td><strong>' + $('<div>').text(h.job_name || 'Job #' + h.job_id).html() + '</strong></td>' +
                        '<td><span class="label label-default">' + (h.backup_type || 'full') + '</span></td>' +
                        '<td>' + (h.destination_name || '--') + '</td>' +
                        '<td>' + (h.started_at || '--') + '</td>' +
                        '<td>' + formatDuration(h.duration_seconds) + '</td>' +
                        '<td>' + (h.files_count || 0) + '</td>' +
                        '<td>' + formatBytes(h.bytes_transferred) + '</td>' +
                        '<td>' + st + '</td>' +
                    '</tr>';
                });
                $('#history-table-body').html(html);
            }
        });
    });

    // Edit Job Click
    $(document).on('click', '.btn-edit-job', function() {
        openJobModal($(this).data('id'));
    });

    // Delete Job Click
    $(document).on('click', '.btn-delete-job', function() {
        var jobId = $(this).data('id');
        if (!confirm('Are you sure you want to delete backup job #' + jobId + '?')) {
            return;
        }

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_job',
                id: jobId,
                csrf_token: CSRF_TOKEN
            },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok) {
                    loadJobs();
                } else {
                    alert('Error deleting job: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    });

    // Button Events
    $('#btn-add-job').on('click', function() { openJobModal(); });
    $('#btn-refresh-jobs').on('click', function() { loadJobs(); });

    // Initial Load
    $(document).ready(function() {
        loadJobs();
        loadDestinationsDropdown();
        loadAccountsList();
    });

})(jQuery);
</script>
