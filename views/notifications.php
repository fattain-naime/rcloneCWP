<?php
/**
 * rcloneCWP Notifications Management View
 *
 * Provides administrative UI for managing multi-channel notifications
 * including SMTP/PHP Mail, Telegram Bot API, and Webhooks (Slack/Discord/Generic).
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
<!-- NOTIFICATIONS ACTION BAR                                                  -->
<!-- ========================================================================= -->
<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-6">
        <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: #2c3e50;">
            <i class="fa fa-bell" style="color: #3498db; margin-right: 8px;"></i> Notification Channels
        </h3>
        <p class="text-muted" style="margin: 3px 0 0 0; font-size: 12px;">
            Configure Email (SMTP/mail), Telegram Bot, and Webhook alert channels for backup and restore lifecycle events.
        </p>
    </div>
    <div class="col-sm-6 text-right">
        <button type="button" class="btn btn-default" id="btn-refresh-notifs" style="margin-right: 5px;">
            <i class="fa fa-refresh"></i> Refresh
        </button>
        <button type="button" class="btn btn-success" id="btn-create-notif">
            <i class="fa fa-plus"></i> Add Channel
        </button>
    </div>
</div>

<!-- Alert notification box -->
<div id="notifs-alert" class="alert alert-dismissible" style="display: none;">
    <button type="button" class="close" onclick="$(this).parent().hide();">&times;</button>
    <span></span>
</div>

<!-- ========================================================================= -->
<!-- NOTIFICATIONS TABLE                                                       -->
<!-- ========================================================================= -->
<div class="table-responsive">
    <table class="table table-hover table-bordered table-striped" id="notifs-table" style="background: #fff;">
        <thead style="background: #eaeded; font-weight: 600;">
            <tr>
                <th style="width: 50px;">ID</th>
                <th>Channel Name</th>
                <th>Type</th>
                <th>Destination / Endpoint</th>
                <th style="width: 100px;" class="text-center">Status</th>
                <th style="width: 180px;" class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody id="notifs-table-body">
            <tr>
                <td colspan="6" class="text-center" style="padding: 30px; color: #7f8c8d;">
                    <i class="fa fa-spinner fa-spin fa-2x"></i>
                    <div style="margin-top: 10px;">Loading notification channels...</div>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADD / EDIT NOTIFICATION CHANNEL                                    -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-notif" tabindex="-1" role="dialog" aria-labelledby="modal-notif-title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 4px;">
            <form id="form-notif">
                <input type="hidden" name="id" id="notif-id" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="modal-header" style="background: #2c3e50; color: #fff;">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title" id="modal-notif-title" style="font-weight: 600;">
                        <i class="fa fa-bell" style="color: #3498db; margin-right: 6px;"></i> Configure Channel
                    </h4>
                </div>

                <div class="modal-body" style="padding: 20px;">
                    <div id="modal-notif-alert" class="alert alert-danger" style="display: none;"></div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="notif-name">Channel Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control input-sm" id="notif-name" name="name" required
                                   placeholder="e.g. Primary Ops Team Email or Slack Alerts" maxlength="255">
                        </div>

                        <div class="col-md-6 form-group">
                            <label for="notif-type">Channel Type <span class="text-danger">*</span></label>
                            <select class="form-control input-sm" id="notif-type" name="type" required>
                                <option value="email">Email (Native SMTP / mail)</option>
                                <option value="telegram">Telegram Bot API</option>
                                <option value="webhook">Webhook (Slack / Discord / Generic)</option>
                            </select>
                        </div>
                    </div>

                    <hr style="margin: 10px 0 15px 0;">

                    <!-- DYNAMIC FIELDS: EMAIL -->
                    <div id="fields-email" class="channel-config-section">
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="email-mailer">Mailer Driver <span class="text-danger">*</span></label>
                                <select class="form-control input-sm" id="email-mailer" name="config[mailer]">
                                    <option value="smtp">Native Socket SMTP (Recommended)</option>
                                    <option value="mail">PHP mail() Local Sendmail</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="email-from-email">Sender Email (From) <span class="text-danger">*</span></label>
                                <input type="email" class="form-control input-sm" id="email-from-email" name="config[from_email]"
                                       placeholder="noreply@example.com">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="email-from-name">Sender Name</label>
                                <input type="text" class="form-control input-sm" id="email-from-name" name="config[from_name]"
                                       value="rcloneCWP" placeholder="rcloneCWP Backup Engine">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email-to">Recipient Emails (comma-separated) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control input-sm" id="email-to" name="config[to]"
                                   placeholder="admin@example.com, devops@example.com">
                        </div>

                        <!-- SMTP Specific Fields -->
                        <div id="fields-smtp-options">
                            <div class="well well-sm" style="background: #fdfefe; border: 1px solid #d5dbdb;">
                                <div class="row">
                                    <div class="col-md-5 form-group">
                                        <label for="smtp-host">SMTP Host</label>
                                        <input type="text" class="form-control input-sm" id="smtp-host" name="config[smtp_host]"
                                               placeholder="smtp.example.com or 127.0.0.1">
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label for="smtp-port">SMTP Port</label>
                                        <input type="number" class="form-control input-sm" id="smtp-port" name="config[smtp_port]"
                                               value="587" min="1" max="65535">
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="smtp-secure">Encryption Protocol</label>
                                        <select class="form-control input-sm" id="smtp-secure" name="config[smtp_secure]">
                                            <option value="tls">STARTTLS (Port 587)</option>
                                            <option value="ssl">SMTPS / SSL (Port 465)</option>
                                            <option value="none">Plain / None (Port 25)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="smtp-user">SMTP Username</label>
                                        <input type="text" class="form-control input-sm" id="smtp-user" name="config[smtp_user]"
                                               placeholder="smtp_user@example.com" autocomplete="off">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="smtp-pass">SMTP Password</label>
                                        <div class="input-group input-group-sm">
                                            <input type="password" class="form-control" id="smtp-pass" name="config[smtp_pass]"
                                                   placeholder="••••••••" autocomplete="new-password">
                                            <span class="input-group-btn">
                                                <button type="button" class="btn btn-default btn-toggle-pw" tabindex="-1">
                                                    <i class="fa fa-eye"></i>
                                                </button>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DYNAMIC FIELDS: TELEGRAM -->
                    <div id="fields-telegram" class="channel-config-section" style="display: none;">
                        <div class="row">
                            <div class="col-md-7 form-group">
                                <label for="tg-token">Telegram Bot Token <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <input type="password" class="form-control" id="tg-token" name="config[bot_token]"
                                           placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ" autocomplete="new-password">
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-default btn-toggle-pw" tabindex="-1">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                    </span>
                                </div>
                                <span class="help-block" style="font-size: 11px;">Generated via @BotFather in Telegram.</span>
                            </div>
                            <div class="col-md-5 form-group">
                                <label for="tg-chat-id">Chat ID or Group ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control input-sm" id="tg-chat-id" name="config[chat_id]"
                                       placeholder="e.g. -1001234567890">
                                <span class="help-block" style="font-size: 11px;">User ID, Group ID, or Channel (@channel).</span>
                            </div>
                        </div>
                    </div>

                    <!-- DYNAMIC FIELDS: WEBHOOK -->
                    <div id="fields-webhook" class="channel-config-section" style="display: none;">
                        <div class="row">
                            <div class="col-md-8 form-group">
                                <label for="webhook-url">Webhook URL <span class="text-danger">*</span></label>
                                <input type="url" class="form-control input-sm" id="webhook-url" name="config[url]"
                                       placeholder="https://hooks.slack.com/services/... or https://discord.com/api/webhooks/...">
                                <span class="help-block" style="font-size: 11px;">Strict SSRF validation enforces public HTTP/HTTPS endpoints.</span>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="webhook-format">Payload Format <span class="text-danger">*</span></label>
                                <select class="form-control input-sm" id="webhook-format" name="config[format]">
                                    <option value="slack">Slack Incoming Webhook</option>
                                    <option value="discord">Discord Webhook (Embeds)</option>
                                    <option value="generic">Generic JSON (with HMAC Signature)</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8 form-group">
                                <label for="webhook-secret">HMAC-SHA256 Secret (Optional)</label>
                                <div class="input-group input-group-sm">
                                    <input type="password" class="form-control" id="webhook-secret" name="config[secret]"
                                           placeholder="Shared secret for X-RcloneCWP-Signature verification">
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-default btn-toggle-pw" tabindex="-1">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="webhook-timeout">Timeout (seconds)</label>
                                <input type="number" class="form-control input-sm" id="webhook-timeout" name="config[timeout]"
                                       value="15" min="5" max="60">
                            </div>
                        </div>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" id="notif-active" name="active" value="1" checked>
                            <strong>Enable this notification channel</strong>
                        </label>
                    </div>
                </div>

                <div class="modal-footer" style="background: #f8f9fa;">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-notif">
                        <i class="fa fa-save"></i> Save Channel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: DELETE CONFIRMATION                                                -->
<!-- ========================================================================= -->
<div class="modal fade" id="modal-delete-notif" tabindex="-1" role="dialog" aria-labelledby="modal-delete-notif-title">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: #d9534f; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
                <h4 class="modal-title" id="modal-delete-notif-title"><i class="fa fa-trash"></i> Delete Channel</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete notification channel <strong id="delete-notif-name-display"></strong>?</p>
                <input type="hidden" id="delete-notif-id" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete-notif">Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    var NotificationsController = {
        apiUrl: 'index.php?module=rcloneCWP&ajax=1',
        csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
        channels: [],

        init: function() {
            var self = this;
            self.bindEvents();
            self.loadNotifications();
        },

        bindEvents: function() {
            var self = this;

            $('#btn-refresh-notifs').on('click', function() {
                self.loadNotifications();
            });

            $('#btn-create-notif').on('click', function() {
                self.openCreateModal();
            });

            $('#notif-type').on('change', function() {
                self.switchTypeSection($(this).val());
            });

            $('#email-mailer').on('change', function() {
                if ($(this).val() === 'smtp') {
                    $('#fields-smtp-options').slideDown(200);
                } else {
                    $('#fields-smtp-options').slideUp(200);
                }
            });

            // Password toggles
            $(document).on('click', '.btn-toggle-pw', function() {
                var $input = $(this).closest('.input-group').find('input');
                var isPw = $input.attr('type') === 'password';
                $input.attr('type', isPw ? 'text' : 'password');
                $(this).find('i').toggleClass('fa-eye fa-eye-slash');
            });

            $('#form-notif').on('submit', function(e) {
                e.preventDefault();
                self.saveNotification();
            });

            $(document).on('click', '.btn-edit-notif', function() {
                var id = $(this).data('id');
                self.openEditModal(id);
            });

            $(document).on('click', '.btn-delete-notif', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#delete-notif-id').val(id);
                $('#delete-notif-name-display').text(name);
                $('#modal-delete-notif').modal('show');
            });

            $('#btn-confirm-delete-notif').on('click', function() {
                self.deleteNotification($('#delete-notif-id').val());
            });

            $(document).on('click', '.btn-toggle-notif', function() {
                var id = $(this).data('id');
                var current = $(this).data('active');
                self.toggleNotification(id, current ? 0 : 1);
            });

            $(document).on('click', '.btn-test-notif', function() {
                var id = $(this).data('id');
                self.testNotification(id, $(this));
            });
        },

        switchTypeSection: function(type) {
            $('.channel-config-section').hide();
            if (type === 'telegram') {
                $('#fields-telegram').show();
            } else if (type === 'webhook') {
                $('#fields-webhook').show();
            } else {
                $('#fields-email').show();
            }
        },

        loadNotifications: function() {
            var self = this;
            $('#notifs-table-body').html(
                '<tr><td colspan="6" class="text-center" style="padding: 30px; color: #7f8c8d;">' +
                '<i class="fa fa-spinner fa-spin fa-2x"></i>' +
                '<div style="margin-top: 10px;">Loading notification channels...</div></td></tr>'
            );

            $.getJSON(self.apiUrl + '&action=list_notifications', function(res) {
                if (res && res.ok) {
                    self.channels = res.notifications || [];
                    self.renderTable(self.channels);
                } else {
                    $('#notifs-table-body').html(
                        '<tr><td colspan="6" class="text-center text-danger" style="padding: 20px;">' +
                        'Error loading channels: ' + self.escapeHtml(res.error || 'Unknown error') + '</td></tr>'
                    );
                }
            }).fail(function() {
                $('#notifs-table-body').html(
                    '<tr><td colspan="6" class="text-center text-danger" style="padding: 20px;">' +
                    'Network error loading channels. Please check your session.</td></tr>'
                );
            });
        },

        renderTable: function(items) {
            var self = this;
            if (!items || items.length === 0) {
                $('#notifs-table-body').html(
                    '<tr><td colspan="6" class="text-center" style="padding: 30px; color: #7f8c8d;">' +
                    '<i class="fa fa-bell-slash-o fa-2x" style="margin-bottom: 10px; color: #bdc3c7;"></i>' +
                    '<div>No notification channels configured. Click <strong>Add Channel</strong> to create one.</div></td></tr>'
                );
                return;
            }

            var html = '';
            for (var i = 0; i < items.length; i++) {
                var c = items[i];
                var typeBadge = self.getTypeBadge(c.type);
                var endpointSummary = self.getEndpointSummary(c);

                var statusHtml = c.active
                    ? '<span class="label label-success" style="cursor:pointer;" title="Click to disable"><i class="fa fa-check"></i> Active</span>'
                    : '<span class="label label-default" style="cursor:pointer;" title="Click to enable"><i class="fa fa-pause"></i> Disabled</span>';

                html += '<tr>';
                html += '<td style="text-align: center; color: #888;">' + c.id + '</td>';
                html += '<td><strong>' + self.escapeHtml(c.name) + '</strong></td>';
                html += '<td>' + typeBadge + '</td>';
                html += '<td><code style="font-size: 11px; background: #f8f9fa; padding: 2px 6px; border-radius: 3px;">' + self.escapeHtml(endpointSummary) + '</code></td>';
                html += '<td style="text-align: center;"><button type="button" class="btn btn-link btn-xs btn-toggle-notif" data-id="' + c.id + '" data-active="' + (c.active ? 1 : 0) + '" style="padding:0; text-decoration:none;">' + statusHtml + '</button></td>';
                html += '<td style="text-align: right;">';
                html += '<button type="button" class="btn btn-info btn-xs btn-test-notif" data-id="' + c.id + '" title="Send Test Alert" aria-label="Send Test Alert" style="margin-right: 3px;"><i class="fa fa-paper-plane"></i> Test</button>';
                html += '<button type="button" class="btn btn-default btn-xs btn-edit-notif" data-id="' + c.id + '" title="Edit Channel" aria-label="Edit Channel" style="margin-right: 3px;"><i class="fa fa-pencil"></i></button>';
                html += '<button type="button" class="btn btn-danger btn-xs btn-delete-notif" data-id="' + c.id + '" data-name="' + self.escapeHtml(c.name) + '" title="Delete Channel" aria-label="Delete Channel"><i class="fa fa-trash"></i></button>';
                html += '</td>';
                html += '</tr>';
            }

            $('#notifs-table-body').html(html);
        },

        getTypeBadge: function(type) {
            var bg = '#3498db';
            var icon = 'fa-bell';
            if (type === 'email') {
                bg = '#2980b9';
                icon = 'fa-envelope-o';
            } else if (type === 'telegram') {
                bg = '#0088cc';
                icon = 'fa-paper-plane-o';
            } else if (type === 'webhook') {
                bg = '#8e44ad';
                icon = 'fa-globe';
            }
            return '<span class="label" style="background-color: ' + bg + '; font-size: 11px;"><i class="fa ' + icon + '"></i> ' + this.escapeHtml(type.toUpperCase()) + '</span>';
        },

        getEndpointSummary: function(item) {
            var cfg = item.config || {};
            if (item.type === 'email') {
                return (cfg.to || 'No recipient configured');
            } else if (item.type === 'telegram') {
                return 'Chat ID: ' + (cfg.chat_id || '--');
            } else if (item.type === 'webhook') {
                return (cfg.format || 'slack') + ': ' + (cfg.url || '--');
            }
            return '--';
        },

        openCreateModal: function() {
            var self = this;
            $('#modal-notif-title').html('<i class="fa fa-plus-circle"></i> Create Notification Channel');
            $('#notif-id').val('');
            $('#notif-name').val('');
            $('#notif-type').val('email');
            $('#notif-active').prop('checked', true);

            // Reset email fields
            $('#email-mailer').val('smtp');
            $('#email-from-email').val('');
            $('#email-from-name').val('rcloneCWP');
            $('#email-to').val('');
            $('#smtp-host').val('127.0.0.1');
            $('#smtp-port').val('587');
            $('#smtp-secure').val('tls');
            $('#smtp-user').val('');
            $('#smtp-pass').val('');
            $('#fields-smtp-options').show();

            // Reset Telegram & Webhook
            $('#tg-token').val('');
            $('#tg-chat-id').val('');
            $('#webhook-url').val('');
            $('#webhook-format').val('slack');
            $('#webhook-secret').val('');
            $('#webhook-timeout').val('15');

            self.switchTypeSection('email');
            $('#modal-notif-alert').hide();
            $('#modal-notif').modal('show');
        },

        openEditModal: function(id) {
            var self = this;
            $('#modal-notif-alert').hide();
            $.getJSON(self.apiUrl + '&action=get_notification&id=' + id, function(res) {
                if (res && res.ok && res.notification) {
                    var n = res.notification;
                    var cfg = n.config || {};

                    $('#modal-notif-title').html('<i class="fa fa-pencil"></i> Edit Channel #' + n.id);
                    $('#notif-id').val(n.id);
                    $('#notif-name').val(n.name);
                    $('#notif-type').val(n.type);
                    $('#notif-active').prop('checked', !!n.active);

                    if (n.type === 'email') {
                        $('#email-mailer').val(cfg.mailer || 'smtp');
                        $('#email-from-email').val(cfg.from_email || '');
                        $('#email-from-name').val(cfg.from_name || 'rcloneCWP');
                        $('#email-to').val(cfg.to || '');
                        $('#smtp-host').val(cfg.smtp_host || '127.0.0.1');
                        $('#smtp-port').val(cfg.smtp_port || 587);
                        $('#smtp-secure').val(cfg.smtp_secure || 'tls');
                        $('#smtp-user').val(cfg.smtp_user || '');
                        $('#smtp-pass').val(cfg.smtp_pass || '');
                        if ((cfg.mailer || 'smtp') === 'smtp') {
                            $('#fields-smtp-options').show();
                        } else {
                            $('#fields-smtp-options').hide();
                        }
                    } else if (n.type === 'telegram') {
                        $('#tg-token').val(cfg.bot_token || '');
                        $('#tg-chat-id').val(cfg.chat_id || '');
                    } else if (n.type === 'webhook') {
                        $('#webhook-url').val(cfg.url || '');
                        $('#webhook-format').val(cfg.format || 'slack');
                        $('#webhook-secret').val(cfg.secret || '');
                        $('#webhook-timeout').val(cfg.timeout || 15);
                    }

                    self.switchTypeSection(n.type);
                    $('#modal-notif').modal('show');
                } else {
                    self.showAlert('danger', 'Failed to fetch channel details: ' + (res.error || 'Unknown error'));
                }
            }).fail(function() {
                self.showAlert('danger', 'Network error fetching channel details.');
            });
        },

        saveNotification: function() {
            var self = this;
            var type = $('#notif-type').val();
            var config = {};

            if (type === 'email') {
                config.mailer = $('#email-mailer').val();
                config.from_email = $('#email-from-email').val();
                config.from_name = $('#email-from-name').val();
                config.to = $('#email-to').val();
                if (config.mailer === 'smtp') {
                    config.smtp_host = $('#smtp-host').val();
                    config.smtp_port = $('#smtp-port').val();
                    config.smtp_secure = $('#smtp-secure').val();
                    config.smtp_user = $('#smtp-user').val();
                    config.smtp_pass = $('#smtp-pass').val();
                }
            } else if (type === 'telegram') {
                config.bot_token = $('#tg-token').val();
                config.chat_id = $('#tg-chat-id').val();
            } else if (type === 'webhook') {
                config.url = $('#webhook-url').val();
                config.format = $('#webhook-format').val();
                config.secret = $('#webhook-secret').val();
                config.timeout = $('#webhook-timeout').val();
            }

            var data = {
                id: $('#notif-id').val(),
                name: $('#notif-name').val(),
                type: type,
                active: $('#notif-active').is(':checked') ? 1 : 0,
                config: config,
                csrf_token: self.csrfToken
            };

            var $btn = $('#btn-save-notif');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

            $.post(self.apiUrl + '&action=save_notification', data, function(res) {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Channel');
                if (res && res.ok) {
                    $('#modal-notif').modal('hide');
                    self.showAlert('success', 'Notification channel saved successfully.');
                    self.loadNotifications();
                } else {
                    $('#modal-notif-alert').text(res.error || 'Failed to save channel.').show();
                }
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Channel');
                $('#modal-notif-alert').text('Network error while saving channel.').show();
            });
        },

        deleteNotification: function(id) {
            var self = this;
            $.post(self.apiUrl + '&action=delete_notification&id=' + id, { csrf_token: self.csrfToken }, function(res) {
                $('#modal-delete-notif').modal('hide');
                if (res && res.ok) {
                    self.showAlert('success', 'Channel #' + id + ' deleted successfully.');
                    self.loadNotifications();
                } else {
                    self.showAlert('danger', 'Failed to delete channel: ' + (res.error || 'Unknown error'));
                }
            }, 'json').fail(function() {
                $('#modal-delete-notif').modal('hide');
                self.showAlert('danger', 'Network error deleting channel.');
            });
        },

        toggleNotification: function(id, status) {
            var self = this;
            $.post(self.apiUrl + '&action=toggle_notification&id=' + id + '&active=' + status, { csrf_token: self.csrfToken }, function(res) {
                if (res && res.ok) {
                    self.loadNotifications();
                } else {
                    self.showAlert('danger', 'Failed to update channel status: ' + (res.error || 'Unknown error'));
                }
            }, 'json').fail(function() {
                self.showAlert('danger', 'Network error toggling channel.');
            });
        },

        testNotification: function(id, $btn) {
            var self = this;
            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');

            $.post(self.apiUrl + '&action=test_notification&id=' + id, { csrf_token: self.csrfToken }, function(res) {
                $btn.prop('disabled', false).html(origHtml);
                if (res && res.ok) {
                    self.showAlert('success', '<strong>Success!</strong> Test notification sent successfully via channel #' + id);
                } else {
                    self.showAlert('danger', '<strong>Test Alert Failed:</strong> ' + self.escapeHtml(res.error || 'Failed to dispatch notification'));
                }
            }, 'json').fail(function() {
                $btn.prop('disabled', false).html(origHtml);
                self.showAlert('danger', 'Network error dispatching test notification.');
            });
        },

        showAlert: function(type, message) {
            var $alert = $('#notifs-alert');
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
        NotificationsController.init();
    });

})(jQuery);
</script>
