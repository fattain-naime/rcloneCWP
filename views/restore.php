<?php
/**
 * rcloneCWP Restore Engine View
 *
 * Remote snapshot browser, granular component selection, and restore orchestration.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}
?>

<div class="row">
    <div class="col-md-12">
        <!-- Action Toolbar -->
        <div class="well well-sm" style="background: #fdfdfd; border-color: #e0e0e0; margin-bottom: 20px;">
            <div class="row">
                <div class="col-sm-5 form-inline">
                    <label for="restore-destination-select" style="font-weight: 600; margin-right: 10px;">
                        <i class="fa fa-database text-primary"></i> Backup Storage Destination:
                    </label>
                    <select id="restore-destination-select" class="form-control" style="min-width: 250px;">
                        <option value="">-- Select Destination --</option>
                    </select>
                </div>
                <div class="col-sm-4">
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-filter"></i></span>
                        <input type="text" id="snapshot-search-filter" class="form-control" placeholder="Filter by username or job name...">
                    </div>
                </div>
                <div class="col-sm-3 text-right">
                    <button type="button" class="btn btn-default" id="btn-refresh-snapshots">
                        <i class="fa fa-refresh"></i> Refresh Snapshots
                    </button>
                    <button type="button" class="btn btn-info" id="btn-view-restore-history" style="margin-left: 5px;">
                        <i class="fa fa-history"></i> Restore History
                    </button>
                </div>
            </div>
        </div>

        <!-- Snapshots Table Panel -->
        <div class="panel panel-default" style="border-radius: 4px;">
            <div class="panel-heading" style="background: #fafafa; border-bottom: 1px solid #e7e7e7; padding: 12px 15px;">
                <h4 class="panel-title" style="font-size: 15px; font-weight: 600; color: #333;">
                    <i class="fa fa-archive" style="color: #3498db; margin-right: 6px;"></i>
                    Available Remote Backup Snapshots
                </h4>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped" style="margin-bottom: 0;">
                    <thead>
                        <tr style="background: #f5f7fa; color: #555; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <th style="width: 160px;">CWP Account</th>
                            <th>Job Name / Slug</th>
                            <th>Created Timestamp</th>
                            <th>Snapshot Path</th>
                            <th style="width: 120px;">Manifest</th>
                            <th style="width: 140px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="snapshots-table-body">
                        <tr>
                            <td colspan="6" class="text-center text-muted" style="padding: 40px;">
                                <i class="fa fa-arrow-up" style="font-size: 20px; margin-bottom: 8px; display: block; color: #bdc3c7;"></i>
                                Please select a backup destination above to load remote snapshots.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- RESTORE MODAL -->
<div class="modal fade" id="modal-restore-snapshot" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 4px;">
            <div class="modal-header" style="background: #2c3e50; color: #fff; border-bottom: 2px solid #1a252f;">
                <button type="button" class="close" data-dismiss="modal" style="color: #fff; opacity: 0.8;">&times;</button>
                <h4 class="modal-title" style="font-weight: 600;">
                    <i class="fa fa-history" style="color: #3498db; margin-right: 6px;"></i>
                    Restore Account Snapshot: <span id="modal-restore-user" style="color: #3498db;">--</span>
                </h4>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div id="modal-restore-alert"></div>

                <!-- Snapshot Meta Details -->
                <div class="well well-sm" style="background: #f8f9fa; margin-bottom: 20px;">
                    <div class="row">
                        <div class="col-sm-6">
                            <p style="margin: 0 0 5px 0;"><strong>Snapshot Path:</strong> <code id="modal-meta-path" style="font-size: 11px;">--</code></p>
                            <p style="margin: 0;"><strong>Created:</strong> <span id="modal-meta-timestamp" class="text-muted">--</span></p>
                        </div>
                        <div class="col-sm-6">
                            <p style="margin: 0 0 5px 0;"><strong>Generator:</strong> <span id="modal-meta-generator" class="text-muted">--</span></p>
                            <p style="margin: 0;"><strong>Backup Type:</strong> <span id="modal-meta-type" class="label label-primary">--</span></p>
                        </div>
                    </div>
                </div>

                <form id="form-run-restore">
                    <input type="hidden" id="restore-dest-id" name="destination_id" value="">
                    <input type="hidden" id="restore-snap-path" name="path" value="">
                    <input type="hidden" id="restore-target-user" name="username" value="">

                    <h5 style="font-weight: 700; border-bottom: 2px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">
                        Select Components to Restore:
                    </h5>

                    <!-- Component Checkboxes -->
                    <div class="row">
                        <!-- Databases -->
                        <div class="col-sm-6">
                            <div class="panel panel-default">
                                <div class="panel-heading" style="padding: 8px 12px; background: #fdfdfd;">
                                    <div class="checkbox" style="margin: 0;">
                                        <label style="font-weight: 600;">
                                            <input type="checkbox" name="components[]" value="databases" id="comp-databases" checked>
                                            <i class="fa fa-database text-primary"></i> MySQL / MariaDB Databases
                                        </label>
                                    </div>
                                </div>
                                <div class="panel-body" id="databases-selection-body" style="padding: 10px; max-height: 120px; overflow-y: auto; font-size: 12px;">
                                    <div class="text-muted">Loading database list...</div>
                                </div>
                            </div>
                        </div>

                        <!-- User Files -->
                        <div class="col-sm-6">
                            <div class="panel panel-default">
                                <div class="panel-heading" style="padding: 8px 12px; background: #fdfdfd;">
                                    <div class="checkbox" style="margin: 0;">
                                        <label style="font-weight: 600;">
                                            <input type="checkbox" name="components[]" value="files" id="comp-files" checked>
                                            <i class="fa fa-folder-open text-warning"></i> User Files (/home)
                                        </label>
                                    </div>
                                </div>
                                <div class="panel-body" style="padding: 10px; font-size: 12px;">
                                    <div class="radio" style="margin-top: 0;">
                                        <label>
                                            <input type="radio" name="files_mode" value="all" checked>
                                            Restore entire home directory
                                        </label>
                                    </div>
                                    <div class="radio" style="margin-bottom: 0;">
                                        <label>
                                            <input type="radio" name="files_mode" value="public_html">
                                            Restore <code>public_html/</code> website files only
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- DNS Zones -->
                        <div class="col-sm-6">
                            <div class="checkbox" style="margin: 6px 0;">
                                <label style="font-weight: 600;">
                                    <input type="checkbox" name="components[]" value="dns" id="comp-dns" checked>
                                    <i class="fa fa-globe text-info"></i> BIND DNS Zone Files
                                </label>
                                <p class="help-block" style="font-size: 11px; margin-left: 20px;">Restores zone records to /var/named/ and reloads named.</p>
                            </div>
                        </div>

                        <!-- SSL Certificates -->
                        <div class="col-sm-6">
                            <div class="checkbox" style="margin: 6px 0;">
                                <label style="font-weight: 600;">
                                    <input type="checkbox" name="components[]" value="ssl" id="comp-ssl" checked>
                                    <i class="fa fa-lock text-success"></i> SSL / TLS Certificates & Keys
                                </label>
                                <p class="help-block" style="font-size: 11px; margin-left: 20px;">Restores certificates (0644) and private keys (0600).</p>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Cron Jobs -->
                        <div class="col-sm-6">
                            <div class="checkbox" style="margin: 6px 0;">
                                <label style="font-weight: 600;">
                                    <input type="checkbox" name="components[]" value="cron" id="comp-cron" checked>
                                    <i class="fa fa-clock-o text-muted"></i> User Scheduled Crontab
                                </label>
                                <p class="help-block" style="font-size: 11px; margin-left: 20px;">Restores crontab to /var/spool/cron/<username>.</p>
                            </div>
                        </div>

                        <!-- Mail Accounts & Spool -->
                        <div class="col-sm-6">
                            <div class="checkbox" style="margin: 6px 0;">
                                <label style="font-weight: 600;">
                                    <input type="checkbox" name="components[]" value="mail" id="comp-mail" checked>
                                    <i class="fa fa-envelope text-danger"></i> Email Mailboxes & Spool
                                </label>
                                <p class="help-block" style="font-size: 11px; margin-left: 20px;">Restores Postfix virtual mailboxes and user Maildir.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Disaster Recovery Options -->
                    <div class="alert alert-warning" style="margin-top: 15px; margin-bottom: 0; font-size: 12px;">
                        <i class="fa fa-exclamation-triangle"></i>
                        <strong>Notice:</strong> Restoring will overwrite existing live data for the selected components.
                        File permissions under <code>/home/&lt;user&gt;/</code> will strictly preserve <code>&lt;user&gt;:&lt;user&gt;</code> ownership.
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="background: #fafafa;">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-execute-restore">
                    <i class="fa fa-play"></i> Start Restoration
                </button>
            </div>
        </div>
    </div>
</div>

<!-- RESTORE HISTORY MODAL -->
<div class="modal fade" id="modal-restore-history" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document" style="width: 90%;">
        <div class="modal-content" style="border-radius: 4px;">
            <div class="modal-header" style="background: #2c3e50; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" style="color: #fff;">&times;</button>
                <h4 class="modal-title">
                    <i class="fa fa-history"></i> Restoration Execution History
                </h4>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table table-hover table-striped" style="margin-bottom: 0;">
                        <thead>
                            <tr style="background: #f5f7fa; font-size: 12px;">
                                <th>ID</th>
                                <th>Target User</th>
                                <th>Destination</th>
                                <th>Snapshot</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Duration</th>
                                <th>Bytes Restored</th>
                                <th>Error / Notes</th>
                            </tr>
                        </thead>
                        <tbody id="restore-history-table-body">
                            <tr><td colspan="9" class="text-center" style="padding: 25px;"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    var CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    var availableSnapshots = [];
    var currentManifest = null;

    // Helper: HTML escaping
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&')
                           .replace(/</g, '<')
                           .replace(/>/g, '>')
                           .replace(/"/g, '"')
                           .replace(/'/g, '&#039;');
    }

    // Helper: format bytes
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Load available destinations into select dropdown
    function loadDestinationsForRestore() {
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_destinations' },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok && resp.destinations) {
                    var opts = '<option value="">-- Select Destination --</option>';
                    $.each(resp.destinations, function(i, d) {
                        if (d.enabled) {
                            opts += '<option value="' + d.id + '">' + escapeHtml(d.name + ' (' + d.type_name + ')') + '</option>';
                        }
                    });
                    $('#restore-destination-select').html(opts);
                }
            }
        });
    }

    // Load remote snapshots for selected destination
    function loadSnapshots(destinationId) {
        if (!destinationId) {
            $('#snapshots-table-body').html(
                '<tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">' +
                '<i class="fa fa-arrow-up" style="font-size: 20px; margin-bottom: 8px; display: block; color: #bdc3c7;"></i>' +
                'Please select a backup destination above to load remote snapshots.</td></tr>'
            );
            return;
        }

        $('#snapshots-table-body').html(
            '<tr><td colspan="6" class="text-center" style="padding: 30px; color: #7f8c8d;">' +
            '<i class="fa fa-spinner fa-spin fa-2x"></i><div style="margin-top: 8px;">Querying remote destination via rclone...</div></td></tr>'
        );

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_snapshots', destination_id: destinationId },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok && resp.snapshots) {
                    availableSnapshots = resp.snapshots;
                    renderSnapshots(availableSnapshots);
                } else {
                    $('#snapshots-table-body').html(
                        '<tr><td colspan="6" class="text-center text-danger" style="padding: 30px;">' +
                        '<i class="fa fa-exclamation-triangle fa-2x"></i>' +
                        '<div style="margin-top: 8px;"><strong>Error loading snapshots:</strong> ' + escapeHtml(resp.error || 'Unknown error') + '</div></td></tr>'
                    );
                }
            },
            error: function(xhr, status, err) {
                $('#snapshots-table-body').html(
                    '<tr><td colspan="6" class="text-center text-danger" style="padding: 30px;">' +
                    '<i class="fa fa-exclamation-triangle fa-2x"></i><div style="margin-top: 8px;">Communication failed: ' + escapeHtml(err) + '</div></td></tr>'
                );
            }
        });
    }

    // Render snapshot rows
    function renderSnapshots(snapshots) {
        if (snapshots.length === 0) {
            $('#snapshots-table-body').html(
                '<tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">' +
                '<i class="fa fa-folder-open-o fa-2x" style="margin-bottom: 8px; display: block; color: #bdc3c7;"></i>' +
                'No backup snapshots found in <code>rcloneCWP-backups/</code> on this destination.</td></tr>'
            );
            return;
        }

        var html = '';
        $.each(snapshots, function(i, s) {
            var user = s.username ? escapeHtml(s.username) : '<span class="text-muted">Unknown</span>';
            var job = escapeHtml(s.job_slug || '--');
            var path = escapeHtml(s.path);
            var timestamp = escapeHtml(s.timestamp || '--');
            var hasManifest = s.has_manifest
                ? '<span class="label label-success"><i class="fa fa-check"></i> Verified</span>'
                : '<span class="label label-default">Raw</span>';

            html += '<tr>' +
                '<td><strong>' + user + '</strong></td>' +
                '<td>' + job + '</td>' +
                '<td><i class="fa fa-calendar text-muted"></i> ' + timestamp + '</td>' +
                '<td><code style="font-size: 11px;">' + path + '</code></td>' +
                '<td>' + hasManifest + '</td>' +
                '<td style="text-align: right;">' +
                    '<button type="button" class="btn btn-xs btn-primary btn-inspect-restore" data-dest-id="' + $('#restore-destination-select').val() + '" data-path="' + path + '" data-user="' + escapeHtml(s.username) + '">' +
                        '<i class="fa fa-history"></i> Restore...' +
                    '</button>' +
                '</td>' +
            '</tr>';
        });

        $('#snapshots-table-body').html(html);
    }

    // Destination select change handler
    $('#restore-destination-select').on('change', function() {
        var destId = $(this).val();
        loadSnapshots(destId);
    });

    // Refresh snapshots button
    $('#btn-refresh-snapshots').on('click', function() {
        var destId = $('#restore-destination-select').val();
        loadSnapshots(destId);
    });

    // Search/filter snapshots
    $('#snapshot-search-filter').on('keyup', function() {
        var term = $(this).val().toLowerCase().trim();
        if (term === '') {
            renderSnapshots(availableSnapshots);
            return;
        }

        var filtered = $.grep(availableSnapshots, function(s) {
            return (s.username && s.username.toLowerCase().indexOf(term) !== -1) ||
                   (s.job_slug && s.job_slug.toLowerCase().indexOf(term) !== -1) ||
                   (s.path && s.path.toLowerCase().indexOf(term) !== -1);
        });
        renderSnapshots(filtered);
    });

    // Inspect & Open Restore Modal
    $(document).on('click', '.btn-inspect-restore', function() {
        var $btn = $(this);
        var destId = $btn.data('dest-id');
        var path = $btn.data('path');
        var username = $btn.data('user');

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Reading...');

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'inspect_snapshot', destination_id: destId, path: path },
            dataType: 'json',
            success: function(resp) {
                $btn.prop('disabled', false).html('<i class="fa fa-history"></i> Restore...');
                if (resp.ok && resp.manifest) {
                    currentManifest = resp.manifest;
                    openRestoreModal(destId, path, username, resp.manifest, resp.components);
                } else {
                    alert('Failed to inspect snapshot: ' + (resp.error || 'Unknown error'));
                }
            },
            error: function(xhr, status, err) {
                $btn.prop('disabled', false).html('<i class="fa fa-history"></i> Restore...');
                alert('Communication error: ' + err);
            }
        });
    });

    // Open Restore Modal with Manifest Information
    function openRestoreModal(destId, path, username, manifest, components) {
        $('#modal-restore-alert').empty();
        $('#restore-dest-id').val(destId);
        $('#restore-snap-path').val(path);
        $('#restore-target-user').val(username || manifest.username || '');

        $('#modal-restore-user').text(username || manifest.username || 'Unknown');
        $('#modal-meta-path').text(path);
        $('#modal-meta-timestamp').text(manifest.started_at || '--');
        $('#modal-meta-generator').text(manifest.generator || '--');
        $('#modal-meta-type').text((manifest.type || 'full').toUpperCase());

        // Render database selection list if available
        var dbHtml = '';
        if (components.databases && components.databases.items && components.databases.items.length > 0) {
            $.each(components.databases.items, function(i, item) {
                var dbName = item.file.replace(/(\.sql|\.sql\.gz)$/, '');
                var safeDb = escapeHtml(dbName);
                dbHtml += '<div class="checkbox" style="margin: 3px 0;">' +
                    '<label>' +
                        '<input type="checkbox" name="databases[]" value="' + safeDb + '" checked> ' +
                        safeDb + ' <span class="text-muted">(' + formatBytes(item.bytes) + ')</span>' +
                    '</label>' +
                '</div>';
            });
        } else {
            dbHtml = '<div class="text-muted">No individual databases found in snapshot.</div>';
        }
        $('#databases-selection-body').html(dbHtml);

        $('#modal-restore-snapshot').modal('show');
    }

    // Execute Restore Operation
    $('#btn-execute-restore').on('click', function() {
        var $btn = $(this);
        var destId = $('#restore-dest-id').val();
        var path = $('#restore-snap-path').val();
        var username = $('#restore-target-user').val();

        var selectedComponents = [];
        $('input[name="components[]"]:checked').each(function() {
            selectedComponents.push($(this).val());
        });

        if (selectedComponents.length === 0) {
            alert('Please select at least one component to restore.');
            return;
        }

        var selectedDbs = [];
        $('input[name="databases[]"]:checked').each(function() {
            selectedDbs.push($(this).val());
        });

        var filesMode = $('input[name="files_mode"]:checked').val();
        var targetPaths = (filesMode === 'public_html') ? ['public_html'] : null;

        if (!confirm('Are you sure you want to restore the selected components for account "' + username + '"?\nThis will overwrite live files and databases.')) {
            return;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Restoring live data...');
        $('#modal-restore-alert').html(
            '<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> ' +
            'Downloading snapshot and executing component restorers. Please wait...</div>'
        );

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax: 1,
                action: 'run_restore',
                csrf_token: CSRF_TOKEN,
                destination_id: destId,
                path: path,
                username: username,
                components: selectedComponents,
                options: {
                    databases: selectedDbs,
                    target_paths: targetPaths
                }
            },
            dataType: 'json',
            success: function(resp) {
                $btn.prop('disabled', false).html('<i class="fa fa-play"></i> Start Restoration');
                if (resp.ok) {
                    $('#modal-restore-alert').html(
                        '<div class="alert alert-success"><i class="fa fa-check-circle"></i> ' +
                        '<strong>Restoration completed successfully!</strong> ' +
                        formatBytes(resp.bytes) + ' restored in ' + resp.duration + 's.</div>'
                    );
                } else {
                    $('#modal-restore-alert').html(
                        '<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> ' +
                        '<strong>Restoration failed or had errors:</strong> ' + escapeHtml(resp.error || 'Unknown error') + '</div>'
                    );
                }
            },
            error: function(xhr, status, err) {
                $btn.prop('disabled', false).html('<i class="fa fa-play"></i> Start Restoration');
                $('#modal-restore-alert').html(
                    '<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> ' +
                    '<strong>Request failed:</strong> ' + escapeHtml(err) + '</div>'
                );
            }
        });
    });

    // View Restore History
    $('#btn-view-restore-history').on('click', function() {
        $('#restore-history-table-body').html(
            '<tr><td colspan="9" class="text-center" style="padding: 25px;"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>'
        );
        $('#modal-restore-history').modal('show');

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { ajax: 1, action: 'list_restore_history' },
            dataType: 'json',
            success: function(resp) {
                if (resp.ok && resp.restores && resp.restores.length > 0) {
                    var html = '';
                    $.each(resp.restores, function(i, r) {
                        var statusLabel = (r.status === 'completed')
                            ? '<span class="label label-success">Success</span>'
                            : '<span class="label label-danger">' + escapeHtml(r.status) + '</span>';

                        var notes = {};
                        try { notes = JSON.parse(r.notes || '{}'); } catch(e) {}
                        var targetUser = escapeHtml(notes.username || '--');

                        html += '<tr>' +
                            '<td><code>#' + r.id + '</code></td>' +
                            '<td><strong>' + targetUser + '</strong></td>' +
                            '<td>' + escapeHtml(r.destination_name || 'Dest #' + r.destination_id) + '</td>' +
                            '<td><code style="font-size: 11px;">' + escapeHtml(r.config_id || '--') + '</code></td>' +
                            '<td>' + statusLabel + '</td>' +
                            '<td>' + escapeHtml(r.started_at) + '</td>' +
                            '<td>' + (r.duration_seconds || 0) + 's</td>' +
                            '<td>' + formatBytes(r.bytes_transferred) + '</td>' +
                            '<td><small class="text-muted">' + escapeHtml(r.error_message || '--') + '</small></td>' +
                        '</tr>';
                    });
                    $('#restore-history-table-body').html(html);
                } else {
                    $('#restore-history-table-body').html(
                        '<tr><td colspan="9" class="text-center text-muted" style="padding: 25px;">No historical restores recorded.</td></tr>'
                    );
                }
            }
        });
    });

    // Auto-init
    loadDestinationsForRestore();

})(jQuery);
</script>
