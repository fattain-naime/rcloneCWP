<?php
/**
 * rcloneCWP Main Admin Layout
 *
 * Master tabbed layout, styling, and JavaScript client.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}

use CWP\RcloneCWP\CSRF;

$csrfToken = CSRF::generateToken();
?>

<div class="container-fluid" style="padding-top: 15px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Main Panel -->
    <div class="panel panel-default" style="border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
        <!-- Panel Header -->
        <div class="panel-heading" style="background: #2c3e50; color: #fff; border-bottom: 2px solid #1a252f; padding: 15px 20px;">
            <div class="row">
                <div class="col-sm-8">
                    <h3 class="panel-title" style="font-size: 20px; font-weight: 600; letter-spacing: -0.2px;">
                        <i class="fa fa-hdd-o" style="margin-right: 8px; color: #3498db;"></i>
                        rcloneCWP
                        <span class="badge" style="background: #3498db; font-size: 11px; margin-left: 8px; vertical-align: middle;">
                            v<?php echo htmlspecialchars(RCLONE_VERSION); ?>
                        </span>
                        <span class="text-muted" style="color: #bdc3c7; font-size: 13px; font-weight: normal; margin-left: 10px;">
                            Native Enterprise Cloud Backup Engine for CWP
                        </span>
                    </h3>
                </div>
                <div class="col-sm-4 text-right" style="padding-top: 2px;">
                    <span class="label label-success" style="font-size: 11px; padding: 5px 10px;">
                        <i class="fa fa-shield"></i> AES-256-GCM
                    </span>
                    <span class="label label-info" style="font-size: 11px; padding: 5px 10px; margin-left: 5px;">
                        <i class="fa fa-terminal"></i> rclone v1.75+
                    </span>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div style="background: #eaeded; border-bottom: 1px solid #d5dbdb; padding: 10px 20px 0 20px;">
            <ul class="nav nav-tabs" id="rclonecwp-tabs" style="border-bottom: none; margin-bottom: 0;">
                <li class="active">
                    <a href="#tab-destinations" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-cloud"></i> Destinations
                        <span id="tab-badge-dest-count" class="badge" style="background: #337ab7; margin-left: 4px;">0</span>
                    </a>
                </li>
                <li>
                    <a href="#tab-overview" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-dashboard"></i> Overview & Status
                    </a>
                </li>
                <li>
                    <a href="#tab-jobs" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-tasks"></i> Backup Jobs
                        <span id="tab-badge-jobs-count" class="badge" style="background: #337ab7; margin-left: 4px;">0</span>
                    </a>
                </li>
                <li>
                    <a href="#tab-restore" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-history"></i> Restore
                    </a>
                </li>
                <li>
                    <a href="#tab-schedules" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-calendar"></i> Schedules
                    </a>
                </li>
                <li>
                    <a href="#tab-logs" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-list-alt"></i> Activity Logs
                    </a>
                </li>
                <li>
                    <a href="#tab-hooks" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-code-fork"></i> Hooks
                        <span id="tab-badge-hooks-count" class="badge" style="background: #8e44ad; margin-left: 4px;">0</span>
                    </a>
                </li>
                <li>
                    <a href="#tab-notifications" data-toggle="tab" style="font-weight: 600;">
                        <i class="fa fa-bell"></i> Notifications
                        <span id="tab-badge-notifs-count" class="badge" style="background: #2980b9; margin-left: 4px;">0</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Tab Panes Content -->
        <div class="panel-body" style="padding: 25px;">
            <div class="tab-content">
                <!-- TAB 1: DESTINATIONS -->
                <div class="tab-pane active" id="tab-destinations">
                    <?php require __DIR__ . '/destinations.php'; ?>
                </div>

                <!-- TAB 2: OVERVIEW & STATUS -->
                <div class="tab-pane" id="tab-overview">
                    <?php require __DIR__ . '/overview.php'; ?>
                </div>

                <!-- TAB 3: BACKUP JOBS -->
                <div class="tab-pane" id="tab-jobs">
                    <?php require __DIR__ . '/backup_jobs.php'; ?>
                </div>

                <!-- TAB 4: RESTORE -->
                <div class="tab-pane" id="tab-restore">
                    <?php require __DIR__ . '/restore.php'; ?>
                </div>

                <!-- TAB 5: SCHEDULES -->
                <div class="tab-pane" id="tab-schedules">
                    <?php require __DIR__ . '/schedules.php'; ?>
                </div>

                <!-- TAB 6: LOGS -->
                <div class="tab-pane" id="tab-logs">
                    <?php require __DIR__ . '/logs.php'; ?>
                </div>

                <!-- TAB 7: HOOKS -->
                <div class="tab-pane" id="tab-hooks">
                    <?php require __DIR__ . '/hooks.php'; ?>
                </div>

                <!-- TAB 8: NOTIFICATIONS -->
                <div class="tab-pane" id="tab-notifications">
                    <?php require __DIR__ . '/notifications.php'; ?>
                </div>
            </div>
        </div>

        <!-- Panel Footer -->
        <div class="panel-footer text-muted" style="background: #fafafa; font-size: 11px; padding: 10px 20px;">
            <div class="row">
                <div class="col-xs-6">
                    rcloneCWP &bull; Open-Source JetBackup5 Alternative &bull; MIT License
                </div>
                <div class="col-xs-6 text-right">
                    Zero Plaintext Secrets on Disk &bull; Off-Htdocs Placement
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================== -->
<!-- STYLING ENHANCEMENTS                                                     -->
<!-- ======================================================================== -->
<style>
.destinations-view table > tbody > tr > td {
    vertical-align: middle !important;
}
.provider-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
}
.provider-badge-local   { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
.provider-badge-s3      { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
.provider-badge-gs      { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
.provider-badge-azure   { background: #e1f5fe; color: #0277bd; border: 1px solid #b3e5fc; }
.provider-badge-b2      { background: #fce4ec; color: #c2185b; border: 1px solid #f8bbd0; }
.provider-badge-sftp    { background: #ede7f6; color: #512da8; border: 1px solid #d1c4e9; }
.provider-badge-ftp     { background: #f3e5f5; color: #7b1fa2; border: 1px solid #e1bee7; }
.provider-badge-webdav  { background: #e0f2f1; color: #00695c; border: 1px solid #b2dfdb; }
.provider-badge-dropbox { background: #e1f5fe; color: #0288d1; border: 1px solid #b3e5fc; }
.provider-badge-onedrive{ background: #e8eaf6; color: #283593; border: 1px solid #c5cae9; }
.provider-badge-swift   { background: #efebe9; color: #4e342e; border: 1px solid #d7ccc8; }
.provider-badge-tencent { background: #e0f7fa; color: #00838f; border: 1px solid #b2ebf2; }

.status-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 5px;
}
.status-indicator-green { background: #5cb85c; box-shadow: 0 0 5px #5cb85c; }
.status-indicator-red   { background: #d9534f; box-shadow: 0 0 5px #d9534f; }
.status-indicator-gray  { background: #bbb; }

.btn-action-group .btn {
    padding: 3px 7px;
    font-size: 11px;
}
</style>

<!-- ======================================================================== -->
<!-- JAVASCRIPT CONTROLLER                                                    -->
<!-- ======================================================================== -->
<script>
(function($) {
    'use strict';

    var RcloneCWP = {
        csrfToken: '<?php echo $csrfToken; ?>',
        apiUrl: 'index.php?module=rcloneCWP&ajax=1',
        types: {},
        destinations: [],

        init: function() {
            var self = this;

            // Setup global AJAX CSRF header
            $.ajaxSetup({
                headers: {
                    'X-CSRF-Token': self.csrfToken
                }
            });

            // Bind UI event handlers
            self.bindEvents();

            // Fetch provider schemas and destinations list
            self.loadTypes(function() {
                self.loadDestinations();
            });

            // Tab switching via hash if present
            if (window.location.hash) {
                $('#rclonecwp-tabs a[href="' + window.location.hash + '"]').tab('show');
            }
        },

        bindEvents: function() {
            var self = this;

            // Switch to destinations tab from overview card link
            $(document).on('click', '.switch-to-destinations', function(e) {
                e.preventDefault();
                $('#rclonecwp-tabs a[href="#tab-destinations"]').tab('show');
            });

            // Refresh button
            $('#btn-refresh-destinations').on('click', function() {
                self.loadDestinations();
            });

            // Add Destination buttons
            $('#btn-add-destination, #btn-empty-add-destination').on('click', function() {
                self.openAddModal();
            });

            // Provider dropdown change inside modal
            $('#dest-type').on('change', function() {
                var selectedType = $(this).val();
                self.renderDynamicFields(selectedType, {});
            });

            // Save Destination form submit
            $('#form-destination').on('submit', function(e) {
                e.preventDefault();
                self.saveDestination();
            });

            // Test Connection button inside modal (tests current unsaved inputs)
            $('#btn-test-modal-dest').on('click', function() {
                self.testModalDestination();
            });

            // Row action: Test Connection
            $(document).on('click', '.btn-test-dest', function() {
                var id = $(this).data('id');
                self.testDestinationRow(id, $(this));
            });

            // Row action: Edit Destination
            $(document).on('click', '.btn-edit-dest', function() {
                var id = $(this).data('id');
                self.openEditModal(id);
            });

            // Row action: Delete Destination
            $(document).on('click', '.btn-delete-dest', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#delete-dest-id').val(id);
                $('#delete-dest-name-display').text(name);
                $('#modal-delete-dest').modal('show');
            });

            // Confirm Delete
            $('#btn-confirm-delete-dest').on('click', function() {
                self.deleteDestination($('#delete-dest-id').val());
            });

            // Row action: Toggle Enabled Status
            $(document).on('click', '.btn-toggle-dest', function() {
                var id = $(this).data('id');
                var current = $(this).data('enabled');
                self.toggleStatus(id, current ? 0 : 1);
            });

            // Show test details modal on clicking test badge
            $(document).on('click', '.view-test-details', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                self.showTestDetails(id);
            });
        },

        showAlert: function(containerId, message, type) {
            var $alert = $(containerId);
            $alert.removeClass('alert-success alert-danger alert-info alert-warning')
                  .addClass('alert-' + type)
                  .find('span').html(message);
            $alert.show();
        },

        hideAlert: function(containerId) {
            $(containerId).hide();
        },

        loadTypes: function(callback) {
            var self = this;
            $.getJSON(self.apiUrl + '&action=get_types', function(res) {
                if (res && res.ok && res.types) {
                    self.types = res.types;
                    if (callback) callback();
                } else {
                    self.showAlert('#destinations-alert', 'Failed to load provider types: ' + (res.error || 'Unknown error'), 'danger');
                }
            }).fail(function() {
                self.showAlert('#destinations-alert', 'Network error loading provider types.', 'danger');
            });
        },

        loadDestinations: function() {
            var self = this;
            $('#destinations-tbody').html(
                '<tr><td colspan="7" class="text-center" style="padding: 30px;">' +
                '<i class="fa fa-spinner fa-spin fa-2x fa-fw text-muted"></i>' +
                '<div style="margin-top: 10px; color: #777;">Loading destinations...</div></td></tr>'
            );

            $.getJSON(self.apiUrl + '&action=list_destinations', function(res) {
                if (res && res.ok) {
                    self.destinations = res.destinations || [];
                    self.renderDestinationsTable(self.destinations);
                } else {
                    $('#destinations-tbody').html(
                        '<tr><td colspan="7" class="text-center text-danger" style="padding: 20px;">' +
                        'Error loading destinations: ' + (res.error || 'Unknown error') + '</td></tr>'
                    );
                }
            }).fail(function() {
                $('#destinations-tbody').html(
                    '<tr><td colspan="7" class="text-center text-danger" style="padding: 20px;">' +
                    'Network error loading destinations. Please check your session.</td></tr>'
                );
            });
        },

        renderDestinationsTable: function(items) {
            var count = items.length;
            var activeCount = 0;
            $('#dest-count').text(count);
            $('#tab-badge-dest-count').text(count);
            $('#stat-dest-count').text(count);

            if (count === 0) {
                $('#table-destinations').hide();
                $('#destinations-empty').show();
                $('#stat-dest-active').text('0');
                return;
            }

            $('#destinations-empty').hide();
            $('#table-destinations').show();

            var html = '';
            for (var i = 0; i < items.length; i++) {
                var d = items[i];
                if (d.enabled) activeCount++;

                var typeBadge = '<span class="provider-badge provider-badge-' + selfEscape(d.type) + '">' +
                                selfEscape(d.type_name || d.type.toUpperCase()) + '</span>';

                var statusHtml = d.enabled
                    ? '<span class="label label-success" style="cursor:pointer;" title="Click to disable"><i class="fa fa-check"></i> Active</span>'
                    : '<span class="label label-default" style="cursor:pointer;" title="Click to enable"><i class="fa fa-pause"></i> Paused</span>';

                var testBadge = '<span class="text-muted" style="font-size: 11px;"><i class="status-indicator status-indicator-gray"></i> Not tested</span>';
                if (d.last_tested) {
                    if (d.last_test_ok) {
                        testBadge = '<a href="#" class="view-test-details" data-id="' + d.id + '" style="text-decoration:none;">' +
                                    '<span class="label label-success" style="font-size: 11px;">' +
                                    '<i class="fa fa-check-circle"></i> Verified (' + selfEscape(d.last_tested_short) + ')</span></a>';
                    } else {
                        testBadge = '<a href="#" class="view-test-details" data-id="' + d.id + '" style="text-decoration:none;">' +
                                    '<span class="label label-danger" style="font-size: 11px;">' +
                                    '<i class="fa fa-times-circle"></i> Failed (' + selfEscape(d.last_tested_short) + ')</span></a>';
                    }
                }

                html += '<tr id="dest-row-' + d.id + '">';
                html += '<td style="text-align: center; color: #888;">' + d.id + '</td>';
                html += '<td><strong>' + selfEscape(d.name) + '</strong></td>';
                html += '<td>' + typeBadge + '</td>';
                html += '<td><code style="font-size: 11px; background: #f7f7f9; padding: 2px 5px; border-radius: 3px;">' + selfEscape(d.remote_target || '--') + '</code></td>';
                html += '<td style="text-align: center;"><button type="button" class="btn btn-link btn-xs btn-toggle-dest" data-id="' + d.id + '" data-enabled="' + (d.enabled ? 1 : 0) + '" style="padding:0; text-decoration:none;">' + statusHtml + '</button></td>';
                html += '<td>' + testBadge + '</td>';
                html += '<td style="text-align: right;" class="btn-action-group">';
                html += '<button type="button" class="btn btn-info btn-xs btn-test-dest" data-id="' + d.id + '" title="Test Connection"><i class="fa fa-bolt"></i> Test</button> ';
                html += '<button type="button" class="btn btn-default btn-xs btn-edit-dest" data-id="' + d.id + '" title="Edit Destination"><i class="fa fa-pencil"></i> Edit</button> ';
                html += '<button type="button" class="btn btn-danger btn-xs btn-delete-dest" data-id="' + d.id + '" data-name="' + selfEscape(d.name) + '" title="Delete Destination"><i class="fa fa-trash"></i></button>';
                html += '</td>';
                html += '</tr>';
            }

            $('#destinations-tbody').html(html);
            $('#stat-dest-active').text(activeCount);
        },

        renderDynamicFields: function(type, currentConfig) {
            var self = this;
            var typeDef = self.types[type];
            if (!typeDef || !typeDef.fields) {
                $('#dest-config-fields').html('<div class="alert alert-warning">No field definitions available for provider: ' + selfEscape(type) + '</div>');
                return;
            }

            $('#dest-provider-badge').text(typeDef.name);

            var html = '<div class="row">';
            var fields = typeDef.fields;

            for (var key in fields) {
                if (!fields.hasOwnProperty(key)) continue;
                var f = fields[key];
                var val = currentConfig[key] !== undefined ? currentConfig[key] : (f.default || '');
                var isRequired = !!f.required;
                var reqHtml = isRequired ? ' <span class="text-danger">*</span>' : '';
                var helpHtml = f.help ? '<span class="help-block" style="font-size: 11px; margin-bottom: 2px;">' + selfEscape(f.help) + '</span>' : '';

                html += '<div class="col-md-6 form-group" style="margin-bottom: 12px;">';
                html += '<label style="font-size: 12px;">' + selfEscape(f.label) + reqHtml + '</label>';

                if (f.type === 'select') {
                    html += '<select class="form-control input-sm config-field" name="config[' + key + ']" data-key="' + key + '" ' + (isRequired ? 'required' : '') + '>';
                    for (var optVal in f.options) {
                        if (f.options.hasOwnProperty(optVal)) {
                            var selected = (optVal === String(val)) ? 'selected' : '';
                            html += '<option value="' + selfEscape(optVal) + '" ' + selected + '>' + selfEscape(f.options[optVal]) + '</option>';
                        }
                    }
                    html += '</select>';
                } else if (f.type === 'password') {
                    html += '<div class="input-group input-group-sm">';
                    html += '<input type="password" class="form-control config-field" name="config[' + key + ']" data-key="' + key + '" ' +
                            'value="' + selfEscape(val) + '" placeholder="' + selfEscape(f.placeholder || '') + '" ' +
                            (isRequired && !val ? 'required' : '') + ' autocomplete="new-password">';
                    html += '<span class="input-group-btn">';
                    html += '<button type="button" class="btn btn-default btn-toggle-pw" tabindex="-1" title="Toggle Show/Hide Password"><i class="fa fa-eye"></i></button>';
                    html += '</span>';
                    html += '</div>';
                } else if (f.type === 'textarea') {
                    html += '<textarea class="form-control input-sm config-field" name="config[' + key + ']" data-key="' + key + '" rows="3" ' +
                            'placeholder="' + selfEscape(f.placeholder || '') + '" ' + (isRequired ? 'required' : '') + '>' + selfEscape(val) + '</textarea>';
                } else {
                    var inputType = (f.type === 'number') ? 'number' : 'text';
                    html += '<input type="' + inputType + '" class="form-control input-sm config-field" name="config[' + key + ']" data-key="' + key + '" ' +
                            'value="' + selfEscape(val) + '" placeholder="' + selfEscape(f.placeholder || '') + '" ' + (isRequired ? 'required' : '') + '>';
                }

                html += helpHtml;
                html += '</div>';
            }

            html += '</div>';
            $('#dest-config-fields').html(html);

            // Bind password toggle inside dynamic container
            $('#dest-config-fields .btn-toggle-pw').on('click', function() {
                var $input = $(this).closest('.input-group').find('input');
                var isPw = $input.attr('type') === 'password';
                $input.attr('type', isPw ? 'text' : 'password');
                $(this).find('i').toggleClass('fa-eye fa-eye-slash');
            });
        },

        collectFormData: function() {
            var data = {
                id: $('#dest-id').val(),
                name: $('#dest-name').val(),
                type: $('#dest-type').val(),
                enabled: $('#dest-enabled').is(':checked') ? 1 : 0,
                csrf_token: RcloneCWP.csrfToken,
                config: {}
            };

            $('#dest-config-fields .config-field').each(function() {
                var key = $(this).data('key');
                data.config[key] = $(this).val();
            });

            return data;
        },

        openAddModal: function() {
            var self = this;
            self.hideAlert('#modal-dest-alert');
            $('#modal-dest-title').html('<i class="fa fa-plus-circle"></i> Add Backup Destination');
            $('#dest-id').val('0');
            $('#dest-name').val('');
            $('#dest-type').val('s3').prop('disabled', false);
            $('#dest-enabled').prop('checked', true);

            self.renderDynamicFields('s3', {});
            $('#modal-destination').modal('show');
        },

        openEditModal: function(id) {
            var self = this;
            self.hideAlert('#modal-dest-alert');
            $('#modal-dest-title').html('<i class="fa fa-pencil"></i> Edit Backup Destination #' + id);

            $.getJSON(self.apiUrl + '&action=get_destination&id=' + id, function(res) {
                if (res && res.ok && res.destination) {
                    var d = res.destination;
                    $('#dest-id').val(d.id);
                    $('#dest-name').val(d.name);
                    $('#dest-type').val(d.type).prop('disabled', true); // type is immutable after creation
                    $('#dest-enabled').prop('checked', !!d.enabled);

                    self.renderDynamicFields(d.type, d.config || {});
                    $('#modal-destination').modal('show');
                } else {
                    self.showAlert('#destinations-alert', 'Error fetching destination details: ' + (res.error || 'Unknown error'), 'danger');
                }
            }).fail(function() {
                self.showAlert('#destinations-alert', 'Network error loading destination details.', 'danger');
            });
        },

        saveDestination: function() {
            var self = this;
            var data = self.collectFormData();

            var $btn = $('#btn-save-dest');
            var originalBtnHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
            self.hideAlert('#modal-dest-alert');

            $.post(self.apiUrl + '&action=save_destination', data, function(res) {
                $btn.prop('disabled', false).html(originalBtnHtml);

                if (res && res.ok) {
                    $('#modal-destination').modal('hide');
                    self.showAlert('#destinations-alert', '<strong>Success!</strong> ' + (res.message || 'Destination saved successfully.'), 'success');
                    self.loadDestinations();
                } else {
                    var errorMsg = res.error || 'Failed to save destination.';
                    if (res.errors) {
                        errorMsg += '<ul style="margin-top: 5px; margin-bottom: 0; padding-left: 20px;">';
                        for (var field in res.errors) {
                            errorMsg += '<li><strong>' + selfEscape(field) + ':</strong> ' + selfEscape(res.errors[field]) + '</li>';
                        }
                        errorMsg += '</ul>';
                    }
                    self.showAlert('#modal-dest-alert', errorMsg, 'danger');
                }
            }, 'json').fail(function(xhr) {
                $btn.prop('disabled', false).html(originalBtnHtml);
                self.showAlert('#modal-dest-alert', 'Server error while saving. Status: ' + xhr.status, 'danger');
            });
        },

        testModalDestination: function() {
            var self = this;
            var data = self.collectFormData();

            var $btn = $('#btn-test-modal-dest');
            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');
            self.showAlert('#modal-dest-alert', '<i class="fa fa-spinner fa-spin"></i> Testing connection to ' + selfEscape(data.type) + '...', 'info');

            $.post(self.apiUrl + '&action=test_raw_config', data, function(res) {
                $btn.prop('disabled', false).html(origHtml);
                if (res && res.ok) {
                    var successMsg = '<strong><i class="fa fa-check-circle"></i> Connection Verified!</strong> ' +
                                     (res.message || 'Remote reachable and authenticated.') +
                                     (res.latency_ms ? ' (' + res.latency_ms + ' ms)' : '');
                    self.showAlert('#modal-dest-alert', successMsg, 'success');
                } else {
                    var failMsg = '<strong><i class="fa fa-times-circle"></i> Connection Failed:</strong> ' +
                                  (res.message || res.error || 'Failed to reach storage remote.');
                    self.showAlert('#modal-dest-alert', failMsg, 'danger');
                }
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html(origHtml);
                self.showAlert('#modal-dest-alert', 'Network error while initiating connection test.', 'danger');
            });
        },

        testDestinationRow: function(id, $btn) {
            var self = this;
            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');

            $.post(self.apiUrl + '&action=test_destination&id=' + id, { csrf_token: self.csrfToken }, function(res) {
                $btn.prop('disabled', false).html(origHtml);
                if (res && res.ok) {
                    self.showAlert('#destinations-alert', '<strong>Verified Destination #' + id + ':</strong> ' + (res.message || 'Connection test successful.'), 'success');
                } else {
                    self.showAlert('#destinations-alert', '<strong>Test Failed for Destination #' + id + ':</strong> ' + (res.message || res.error || 'Check credentials.'), 'danger');
                }
                self.loadDestinations();
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html(origHtml);
                self.showAlert('#destinations-alert', 'Network error testing destination #' + id, 'danger');
            });
        },

        deleteDestination: function(id) {
            var self = this;
            var $btn = $('#btn-confirm-delete-dest');
            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Deleting...');

            $.post(self.apiUrl + '&action=delete_destination&id=' + id, { csrf_token: self.csrfToken }, function(res) {
                $btn.prop('disabled', false).html(origHtml);
                $('#modal-delete-dest').modal('hide');

                if (res && res.ok) {
                    self.showAlert('#destinations-alert', 'Destination #' + id + ' deleted successfully.', 'success');
                    self.loadDestinations();
                } else {
                    self.showAlert('#destinations-alert', 'Failed to delete destination: ' + (res.error || 'Unknown error'), 'danger');
                }
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html(origHtml);
                $('#modal-delete-dest').modal('hide');
                self.showAlert('#destinations-alert', 'Network error while deleting destination.', 'danger');
            });
        },

        toggleStatus: function(id, newStatus) {
            var self = this;
            $.post(self.apiUrl + '&action=toggle_status&id=' + id + '&enabled=' + newStatus, { csrf_token: self.csrfToken }, function(res) {
                if (res && res.ok) {
                    self.loadDestinations();
                } else {
                    self.showAlert('#destinations-alert', 'Failed to update destination status: ' + (res.error || 'Unknown error'), 'danger');
                }
            }, 'json').fail(function() {
                self.showAlert('#destinations-alert', 'Network error toggling status.', 'danger');
            });
        },

        showTestDetails: function(id) {
            var self = this;
            var dest = null;
            for (var i = 0; i < self.destinations.length; i++) {
                if (String(self.destinations[i].id) === String(id)) {
                    dest = self.destinations[i];
                    break;
                }
            }
            if (!dest) return;

            $('#test-details-title').text('Test Diagnostic: ' + dest.name);
            $('#test-details-target').val(dest.remote_target || '--');
            $('#test-details-time').text(dest.last_tested || '--');
            $('#test-details-latency').text(dest.last_test_latency ? dest.last_test_latency + ' ms' : 'N/A');

            var $status = $('#test-details-status');
            if (dest.last_test_ok) {
                $status.removeClass('alert-danger alert-info').addClass('alert-success')
                       .html('<i class="fa fa-check-circle"></i> <strong>Passed:</strong> ' + selfEscape(dest.last_test_msg || 'Connection succeeded.'));
                $('#test-details-header').css('background', '#5cb85c');
            } else {
                $status.removeClass('alert-success alert-info').addClass('alert-danger')
                       .html('<i class="fa fa-times-circle"></i> <strong>Failed:</strong> ' + selfEscape(dest.last_test_msg || 'Connection failed.'));
                $('#test-details-header').css('background', '#d9534f');
            }

            $('#test-details-raw').text(JSON.stringify(dest.last_test_details || { result: dest.last_test_msg }, null, 2));
            $('#modal-test-details').modal('show');
        }
    };

    function selfEscape(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Initialize once DOM is ready
    $(document).ready(function() {
        RcloneCWP.init();
    });

})(jQuery);
</script>
