<?php
/**
 * rcloneCWP Hooks Management View
 *
 * Provides administrative UI for configuring pre/post backup and restore lifecycle hooks.
 * Supports Shell, PHP, Python, and Webhook runtimes with SSRF defense and isolation.
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
<!-- HOOKS ACTION BAR                                                          -->
<!-- ========================================================================= -->
<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-6">
        <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: #2c3e50;">
            <i class="fa fa-code-fork" style="color: #3498db; margin-right: 8px;"></i> Lifecycle Hooks
        </h3>
        <p class="text-muted" style="margin: 3px 0 0 0; font-size: 12px;">
            Execute custom Bash, PHP, Python scripts or URL webhooks before and after backup and restore events.
        </p>
    </div>
    <div class="col-sm-6 text-right">
        <button type="button" class="btn btn-default" id="btn-refresh-hooks" style="margin-right: 5px;">
            <i class="fa fa-refresh"></i> Refresh
        </button>
        <button type="button" class="btn btn-success" id="btn-create-hook">
            <i class="fa fa-plus"></i> Add Hook
        </button>
    </div>
</div>

<!-- Alert notification box -->
<div id="hooks-alert" class="alert alert-dismissible" style="display: none;">
    <button type="button" class="close" onclick="$(this).parent().hide();">&times;</button>
    <span></span>
</div>

<!-- ========================================================================= -->
<!-- HOOKS TABLE                                                               -->
<!-- ========================================================================= -->
<div class="table-responsive">
    <table class="table table-hover table-bordered table-striped" id="hooks-table" style="background: #fff;">
        <thead style="background: #eaeded; font-weight: 600;">
            <tr>
                <th style="width: 50px;">ID</th>
                <th>Name</th>
                <th>Lifecycle Event</th>
                <th>Runtime Type</th>
                <th style="width: 90px;" class="text-center">Order</th>
                <th style="width: 90px;" class="text-center">Timeout</th>
                <th style="width: 100px;" class="text-center">Status</th>
                <th style="width: 170px;" class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody id="hooks-table-body">
            <tr>
                <td colspan="8" class="text-center" style="padding: 30px; color: #7f8c8d;">
                    <i class="fa fa-spinner fa-spin fa-2x"></i>
                    <div style="margin-top: 10px;">Loading hooks...</div>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADD / EDIT HOOK                                                    -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-hook" tabindex="-1" role="dialog" aria-labelledby="modal-hook-title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 4px;">
            <form id="form-hook">
                <input type="hidden" name="id" id="hook-id" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="modal-header" style="background: #2c3e50; color: #fff;">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title" id="modal-hook-title" style="font-weight: 600;">
                        <i class="fa fa-code-fork" style="color: #3498db; margin-right: 6px;"></i> Configure Hook
                    </h4>
                </div>

                <div class="modal-body" style="padding: 20px;">
                    <div id="modal-hook-alert" class="alert alert-danger" style="display: none;"></div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="hook-name">Hook Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control input-sm" id="hook-name" name="name" required
                                   placeholder="e.g. Notify External API or MySQL Freeze" maxlength="255">
                        </div>

                        <div class="col-md-6 form-group">
                            <label for="hook-event">Lifecycle Event <span class="text-danger">*</span></label>
                            <select class="form-control input-sm" id="hook-event" name="event" required>
                                <option value="backup_start">backup_start (Pre-Backup)</option>
                                <option value="backup_complete">backup_complete (Post-Backup Success)</option>
                                <option value="backup_fail">backup_fail (Backup Failure)</option>
                                <option value="restore_start">restore_start (Pre-Restore)</option>
                                <option value="restore_complete">restore_complete (Post-Restore Success)</option>
                                <option value="restore_fail">restore_fail (Restore Failure)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label for="hook-type">Runtime Type <span class="text-danger">*</span></label>
                            <select class="form-control input-sm" id="hook-type" name="type" required>
                                <option value="shell">Shell Script (/bin/bash)</option>
                                <option value="php">PHP Script (CWP php71)</option>
                                <option value="python">Python Script (/usr/bin/python3)</option>
                                <option value="url">URL Webhook (HTTP POST)</option>
                            </select>
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="hook-run-order">Run Order (Priority)</label>
                            <input type="number" class="form-control input-sm" id="hook-run-order" name="run_order"
                                   value="10" min="1" max="999">
                            <span class="help-block" style="font-size: 11px; margin-bottom: 0;">Lower numbers execute first.</span>
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="hook-timeout">Timeout (seconds)</label>
                            <input type="number" class="form-control input-sm" id="hook-timeout" name="timeout"
                                   value="300" min="5" max="3600">
                            <span class="help-block" style="font-size: 11px; margin-bottom: 0;">Execution kill deadline.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="hook-command" id="hook-command-label">Script / Command Code <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="hook-command" name="command" rows="8" required
                                  style="font-family: monospace; font-size: 12px; background: #282c34; color: #abb2bf;"
                                  placeholder="#!/bin/bash&#10;echo 'Job ID:' $RCLONE_JOB_ID"></textarea>
                        <span class="help-block" id="hook-command-help" style="font-size: 11px;">
                            Context injected via environment variables: <code>$RCLONE_JOB_ID</code>, <code>$RCLONE_HOOK_POINT</code>, <code>$RCLONE_CTX_*</code>.
                        </span>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" id="hook-enabled" name="enabled" value="1" checked>
                            <strong>Enable this hook</strong>
                        </label>
                    </div>
                </div>

                <div class="modal-footer" style="background: #f8f9fa;">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-hook">
                        <i class="fa fa-save"></i> Save Hook
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TEST HOOK OUTPUT                                                   -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-test-hook" tabindex="-1" role="dialog" aria-labelledby="modal-test-hook-title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 4px;">
            <div class="modal-header" id="modal-test-hook-header" style="background: #2c3e50; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="modal-test-hook-title" style="font-weight: 600;">
                    <i class="fa fa-bolt" style="color: #f39c12; margin-right: 6px;"></i> Test Hook Execution
                </h4>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div id="test-hook-status-banner" class="alert alert-info">
                    <i class="fa fa-spinner fa-spin"></i> Executing hook test...
                </div>
                <div class="row" style="margin-bottom: 10px;">
                    <div class="col-xs-6">
                        <strong>Execution Time:</strong> <span id="test-hook-duration">--</span>
                    </div>
                    <div class="col-xs-6 text-right">
                        <strong>Exit Code:</strong> <span id="test-hook-code">--</span>
                    </div>
                </div>
                <label>Output Console:</label>
                <pre id="test-hook-output" style="background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;"></pre>
            </div>
            <div class="modal-footer" style="background: #f8f9fa;">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: DELETE CONFIRMATION                                                -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-delete-hook" tabindex="-1" role="dialog" aria-labelledby="modal-delete-hook-title">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: #d9534f; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                <h4 class="modal-title" id="modal-delete-hook-title"><i class="fa fa-trash"></i> Delete Hook</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete hook <strong id="delete-hook-name-display"></strong>?</p>
                <input type="hidden" id="delete-hook-id" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete-hook">Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    var HooksController = {
        apiUrl: 'index.php?module=rcloneCWP&ajax=1',
        csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
        hooks: [],

        init: function() {
            var self = this;
            self.bindEvents();
            self.loadHooks();
        },

        bindEvents: function() {
            var self = this;

            $('#btn-refresh-hooks').on('click', function() {
                self.loadHooks();
            });

            $('#btn-create-hook').on('click', function() {
                self.openCreateModal();
            });

            $('#hook-type').on('change', function() {
                self.updateCommandHints($(this).val());
            });

            $('#form-hook').on('submit', function(e) {
                e.preventDefault();
                self.saveHook();
            });

            $(document).on('click', '.btn-edit-hook', function() {
                var id = $(this).data('id');
                self.openEditModal(id);
            });

            $(document).on('click', '.btn-delete-hook', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#delete-hook-id').val(id);
                $('#delete-hook-name-display').text(name);
                $('#modal-delete-hook').modal('show');
            });

            $('#btn-confirm-delete-hook').on('click', function() {
                self.deleteHook($('#delete-hook-id').val());
            });

            $(document).on('click', '.btn-toggle-hook', function() {
                var id = $(this).data('id');
                var current = $(this).data('enabled');
                self.toggleHook(id, current ? 0 : 1);
            });

            $(document).on('click', '.btn-test-hook', function() {
                var id = $(this).data('id');
                self.testHook(id);
            });
        },

        updateCommandHints: function(type) {
            var $label = $('#hook-command-label');
            var $help = $('#hook-command-help');
            var $cmd = $('#hook-command');

            if (type === 'url') {
                $label.html('Target Webhook URL <span class="text-danger">*</span>');
                $help.text('Safe HTTP/HTTPS URL. SSRF protection prohibits loopback, internal RFC1918 addresses, and cloud metadata.');
                $cmd.attr('rows', 2).attr('placeholder', 'https://api.example.com/webhooks/rclone-event');
            } else if (type === 'php') {
                $label.html('PHP Script Code <span class="text-danger">*</span>');
                $help.html('Executed via CWP PHP (<code>/usr/local/cwp/php71/bin/php</code>). Context available in <code>$_SERVER[\'RCLONE_CTX_*\']</code>.');
                var phpPlaceholder = '<' + '?php' + "\n";
                phpPlaceholder += '$jobId = getenv("RCLONE_JOB_ID");' + "\n";
                phpPlaceholder += '// Custom logic';
                $cmd.attr('rows', 8).attr('placeholder', phpPlaceholder);
            } else if (type === 'python') {
                $label.html('Python Script Code <span class="text-danger">*</span>');
                $help.html('Executed via Python 3 (<code>/usr/bin/python3</code>). Context available in <code>os.environ</code>.');
                $cmd.attr('rows', 8).attr('placeholder', '#!/usr/bin/python3\nimport os, sys\nprint("Running hook for job", os.getenv("RCLONE_JOB_ID"))\n');
            } else {
                $label.html('Bash Script Code <span class="text-danger">*</span>');
                $help.html('Executed via <code>/bin/bash</code> in an isolated runtime with timeout enforcement.');
                $cmd.attr('rows', 8).attr('placeholder', '#!/bin/bash\necho "Running hook for job: $RCLONE_JOB_ID"\n');
            }
        },

        loadHooks: function() {
            var self = this;
            $('#hooks-table-body').html(
                '<tr><td colspan="8" class="text-center" style="padding: 30px; color: #7f8c8d;">' +
                '<i class="fa fa-spinner fa-spin fa-2x"></i>' +
                '<div style="margin-top: 10px;">Loading hooks...</div></td></tr>'
            );

            $.getJSON(self.apiUrl + '&action=list_hooks', function(res) {
                if (res && res.ok) {
                    self.hooks = res.hooks || [];
                    self.renderTable(self.hooks);
                } else {
                    $('#hooks-table-body').html(
                        '<tr><td colspan="8" class="text-center text-danger" style="padding: 20px;">' +
                        'Error loading hooks: ' + self.escapeHtml(res.error || 'Unknown error') + '</td></tr>'
                    );
                }
            }).fail(function() {
                $('#hooks-table-body').html(
                    '<tr><td colspan="8" class="text-center text-danger" style="padding: 20px;">' +
                    'Network error loading hooks. Please check your session.</td></tr>'
                );
            });
        },

        renderTable: function(items) {
            var self = this;
            if (!items || items.length === 0) {
                $('#hooks-table-body').html(
                    '<tr><td colspan="8" class="text-center" style="padding: 30px; color: #7f8c8d;">' +
                    '<i class="fa fa-info-circle fa-2x" style="margin-bottom: 10px; color: #bdc3c7;"></i>' +
                    '<div>No lifecycle hooks configured. Click <strong>Add Hook</strong> to create one.</div></td></tr>'
                );
                return;
            }

            var html = '';
            for (var i = 0; i < items.length; i++) {
                var h = items[i];
                var eventBadge = self.getEventBadge(h.event);
                var typeBadge = self.getTypeBadge(h.type);

                var statusHtml = h.enabled
                    ? '<span class="label label-success" style="cursor:pointer;" title="Click to disable"><i class="fa fa-check"></i> Enabled</span>'
                    : '<span class="label label-default" style="cursor:pointer;" title="Click to enable"><i class="fa fa-pause"></i> Disabled</span>';

                html += '<tr>';
                html += '<td style="text-align: center; color: #888;">' + h.id + '</td>';
                html += '<td><strong>' + self.escapeHtml(h.name) + '</strong></td>';
                html += '<td>' + eventBadge + '</td>';
                html += '<td>' + typeBadge + '</td>';
                html += '<td style="text-align: center;">' + (h.run_order || 10) + '</td>';
                html += '<td style="text-align: center;">' + (h.timeout || 300) + 's</td>';
                html += '<td style="text-align: center;"><button type="button" class="btn btn-link btn-xs btn-toggle-hook" data-id="' + h.id + '" data-enabled="' + (h.enabled ? 1 : 0) + '" style="padding:0; text-decoration:none;">' + statusHtml + '</button></td>';
                html += '<td style="text-align: right;">';
                html += '<button type="button" class="btn btn-warning btn-xs btn-test-hook" data-id="' + h.id + '" title="Test Execution" aria-label="Test Hook Execution" style="margin-right: 3px;"><i class="fa fa-bolt"></i> Test</button>';
                html += '<button type="button" class="btn btn-default btn-xs btn-edit-hook" data-id="' + h.id + '" title="Edit Hook" aria-label="Edit Hook" style="margin-right: 3px;"><i class="fa fa-pencil"></i></button>';
                html += '<button type="button" class="btn btn-danger btn-xs btn-delete-hook" data-id="' + h.id + '" data-name="' + self.escapeHtml(h.name) + '" title="Delete Hook" aria-label="Delete Hook"><i class="fa fa-trash"></i></button>';
                html += '</td>';
                html += '</tr>';
            }

            $('#hooks-table-body').html(html);
        },

        getEventBadge: function(event) {
            var color = '#3498db';
            if (event.indexOf('fail') !== -1) {
                color = '#e74c3c';
            } else if (event.indexOf('complete') !== -1) {
                color = '#2ecc71';
            } else if (event.indexOf('start') !== -1) {
                color = '#f39c12';
            }
            return '<span class="label" style="background-color: ' + color + '; font-size: 11px;">' + this.escapeHtml(event) + '</span>';
        },

        getTypeBadge: function(type) {
            var bg = '#95a5a6';
            if (type === 'shell') bg = '#34495e';
            if (type === 'php') bg = '#8e44ad';
            if (type === 'python') bg = '#16a085';
            if (type === 'url') bg = '#2980b9';
            return '<span class="label" style="background-color: ' + bg + '; font-size: 11px; text-transform: uppercase;">' + this.escapeHtml(type) + '</span>';
        },

        openCreateModal: function() {
            var self = this;
            $('#modal-hook-title').html('<i class="fa fa-plus-circle"></i> Create Lifecycle Hook');
            $('#hook-id').val('');
            $('#hook-name').val('');
            $('#hook-event').val('backup_complete');
            $('#hook-type').val('shell');
            $('#hook-run-order').val('10');
            $('#hook-timeout').val('300');
            $('#hook-command').val('');
            $('#hook-enabled').prop('checked', true);
            $('#modal-hook-alert').hide();
            self.updateCommandHints('shell');
            $('#modal-hook').modal('show');
        },

        openEditModal: function(id) {
            var self = this;
            $('#modal-hook-alert').hide();
            $.getJSON(self.apiUrl + '&action=get_hook&id=' + id, function(res) {
                if (res && res.ok && res.hook) {
                    var h = res.hook;
                    $('#modal-hook-title').html('<i class="fa fa-pencil"></i> Edit Hook #' + h.id);
                    $('#hook-id').val(h.id);
                    $('#hook-name').val(h.name);
                    $('#hook-event').val(h.event);
                    $('#hook-type').val(h.type);
                    $('#hook-run-order').val(h.run_order || 10);
                    $('#hook-timeout').val(h.timeout || 300);
                    $('#hook-command').val(h.command);
                    $('#hook-enabled').prop('checked', !!h.enabled);
                    self.updateCommandHints(h.type);
                    $('#modal-hook').modal('show');
                } else {
                    self.showAlert('danger', 'Failed to fetch hook details: ' + (res.error || 'Unknown error'));
                }
            }).fail(function() {
                self.showAlert('danger', 'Network error fetching hook details.');
            });
        },

        saveHook: function() {
            var self = this;
            var data = {
                id: $('#hook-id').val(),
                name: $('#hook-name').val(),
                event: $('#hook-event').val(),
                type: $('#hook-type').val(),
                run_order: $('#hook-run-order').val(),
                timeout: $('#hook-timeout').val(),
                command: $('#hook-command').val(),
                enabled: $('#hook-enabled').is(':checked') ? 1 : 0,
                csrf_token: self.csrfToken
            };

            var $btn = $('#btn-save-hook');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

            $.post(self.apiUrl + '&action=save_hook', data, function(res) {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Hook');
                if (res && res.ok) {
                    $('#modal-hook').modal('hide');
                    self.showAlert('success', 'Hook saved successfully.');
                    self.loadHooks();
                } else {
                    $('#modal-hook-alert').text(res.error || 'Failed to save hook.').show();
                }
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Hook');
                $('#modal-hook-alert').text('Network error while saving hook.').show();
            });
        },

        deleteHook: function(id) {
            var self = this;
            $.post(self.apiUrl + '&action=delete_hook&id=' + id, { csrf_token: self.csrfToken }, function(res) {
                $('#modal-delete-hook').modal('hide');
                if (res && res.ok) {
                    self.showAlert('success', 'Hook #' + id + ' deleted successfully.');
                    self.loadHooks();
                } else {
                    self.showAlert('danger', 'Failed to delete hook: ' + (res.error || 'Unknown error'));
                }
            }, 'json').fail(function() {
                $('#modal-delete-hook').modal('hide');
                self.showAlert('danger', 'Network error deleting hook.');
            });
        },

        toggleHook: function(id, status) {
            var self = this;
            $.post(self.apiUrl + '&action=toggle_hook&id=' + id + '&enabled=' + status, { csrf_token: self.csrfToken }, function(res) {
                if (res && res.ok) {
                    self.loadHooks();
                } else {
                    self.showAlert('danger', 'Failed to update hook status: ' + (res.error || 'Unknown error'));
                }
            }, 'json').fail(function() {
                self.showAlert('danger', 'Network error toggling hook.');
            });
        },

        testHook: function(id) {
            var self = this;
            $('#modal-test-hook-title').html('<i class="fa fa-bolt"></i> Testing Hook #' + id);
            $('#test-hook-status-banner').removeClass('alert-success alert-danger').addClass('alert-info')
                .html('<i class="fa fa-spinner fa-spin"></i> Executing hook test...');
            $('#test-hook-duration').text('Running...');
            $('#test-hook-code').text('--');
            $('#test-hook-output').text('Awaiting response...');
            $('#modal-test-hook').modal('show');

            $.post(self.apiUrl + '&action=test_hook&id=' + id, { csrf_token: self.csrfToken }, function(res) {
                if (res && res.ok && res.result) {
                    var r = res.result;
                    var isOk = (r.success === true || r.returnCode === 0);
                    if (isOk) {
                        $('#test-hook-status-banner').removeClass('alert-info alert-danger').addClass('alert-success')
                            .html('<i class="fa fa-check-circle"></i> Hook executed successfully!');
                    } else {
                        $('#test-hook-status-banner').removeClass('alert-info alert-success').addClass('alert-danger')
                            .html('<i class="fa fa-times-circle"></i> Hook execution failed.');
                    }
                    $('#test-hook-duration').text((res.duration || '0') + 's');
                    $('#test-hook-code').text(r.returnCode !== undefined ? r.returnCode : (r.httpCode || 'N/A'));
                    $('#test-hook-output').text(r.output || (r.error ? 'Error: ' + r.error : '(No output returned)'));
                } else {
                    $('#test-hook-status-banner').removeClass('alert-info alert-success').addClass('alert-danger')
                        .html('<i class="fa fa-times-circle"></i> Error testing hook: ' + self.escapeHtml(res.error || 'Unknown error'));
                    $('#test-hook-output').text(res.error || 'Execution failed');
                }
            }, 'json').fail(function() {
                $('#test-hook-status-banner').removeClass('alert-info alert-success').addClass('alert-danger')
                    .html('<i class="fa fa-times-circle"></i> Network error initiating hook test.');
            });
        },

        showAlert: function(type, message) {
            var $alert = $('#hooks-alert');
            $alert.removeClass('alert-success alert-danger alert-info alert-warning')
                  .addClass('alert-' + type)
                  .find('span').html(message);
            $alert.show();
        },

        escapeHtml: function(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };

    $(document).ready(function() {
        HooksController.init();
    });

})(jQuery);
</script>
