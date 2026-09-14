<?php
/**
 * rcloneCWP Destinations View
 *
 * Destination management table and dynamic modals.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}
?>

<div class="destinations-view">
    <!-- Header Section -->
    <div class="row" style="margin-bottom: 15px;">
        <div class="col-md-8">
            <h4 style="margin-top: 5px; margin-bottom: 5px;">
                <i class="fa fa-cloud" style="color: #337ab7;"></i> Backup Destinations
            </h4>
            <p class="text-muted" style="margin-bottom: 0;">
                Configure local mounts and encrypted remote cloud storage providers for automated server backups.
            </p>
        </div>
        <div class="col-md-4 text-right">
            <button type="button" class="btn btn-default btn-sm" id="btn-refresh-destinations" title="Refresh list">
                <i class="fa fa-refresh"></i> Refresh
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="btn-add-destination">
                <i class="fa fa-plus-circle"></i> Add New Destination
            </button>
        </div>
    </div>

    <!-- Live Status Alert -->
    <div id="destinations-alert" class="alert" style="display: none;">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <span id="destinations-alert-msg"></span>
    </div>

    <!-- Destinations Table Card -->
    <div class="panel panel-default">
        <div class="panel-heading" style="background-color: #f5f5f5; border-color: #ddd;">
            <div class="row">
                <div class="col-xs-6">
                    <strong class="text-uppercase" style="font-size: 12px; letter-spacing: 0.5px;">
                        Configured Storage Destinations (<span id="dest-count">0</span>)
                    </strong>
                </div>
                <div class="col-xs-6 text-right">
                    <span class="label label-info">Zero Plaintext Secrets on Disk (AES-256-GCM)</span>
                </div>
            </div>
        </div>
        <div class="panel-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="table-destinations" style="margin-bottom: 0;">
                    <thead>
                        <tr style="background: #fafafa; font-size: 12px;">
                            <th style="width: 50px; text-align: center;">#</th>
                            <th style="min-width: 150px;">Name</th>
                            <th style="min-width: 160px;">Provider Type</th>
                            <th style="min-width: 200px;">Target / Path</th>
                            <th style="width: 100px; text-align: center;">Status</th>
                            <th style="min-width: 180px;">Last Connection Test</th>
                            <th style="width: 220px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="destinations-tbody">
                        <tr>
                            <td colspan="7" class="text-center" style="padding: 40px;">
                                <i class="fa fa-spinner fa-spin fa-2x fa-fw text-muted"></i>
                                <div style="margin-top: 10px; color: #777;">Loading destinations...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div id="destinations-empty" style="display: none; padding: 50px 20px; text-align: center;">
                <div style="font-size: 48px; color: #ccc; margin-bottom: 15px;">
                    <i class="fa fa-cloud-upload"></i>
                </div>
                <h4 style="color: #555;">No Backup Destinations Configured</h4>
                <p class="text-muted" style="max-width: 480px; margin: 0 auto 20px auto;">
                    You have not configured any storage targets yet. Add local storage, Amazon S3, Google Cloud, SFTP, Backblaze B2, or any of the 12 supported cloud backends to start backing up your accounts.
                </p>
                <button type="button" class="btn btn-primary" id="btn-empty-add-destination">
                    <i class="fa fa-plus-circle"></i> Add Your First Destination
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================== -->
<!-- MODAL: ADD / EDIT DESTINATION                                            -->
<!-- ======================================================================== -->
<div class="modal fade" id="modal-destination" tabindex="-1" role="dialog" aria-labelledby="modal-dest-title" data-backdrop="static">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: #2c3e50; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">&times;</button>
                <h4 class="modal-title" id="modal-dest-title">
                    <i class="fa fa-cloud"></i> Add Backup Destination
                </h4>
            </div>
            <form id="form-destination" autocomplete="off">
                <input type="hidden" name="id" id="dest-id" value="0">
                <div class="modal-body" style="padding: 20px 25px;">
                    <!-- Inner Modal Alert -->
                    <div id="modal-dest-alert" class="alert" style="display: none;">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>
                        <span id="modal-dest-alert-msg"></span>
                    </div>

                    <div class="row">
                        <!-- Destination Name -->
                        <div class="col-md-6 form-group">
                            <label for="dest-name">Destination Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="dest-name" name="name"
                                   placeholder="e.g. Primary AWS S3 or Local Backup Drive" required maxlength="100">
                            <span class="help-block" style="font-size: 11px;">Unique identifier used in backup jobs and notifications.</span>
                        </div>

                        <!-- Storage Provider Type -->
                        <div class="col-md-6 form-group">
                            <label for="dest-type">Storage Provider <span class="text-danger">*</span></label>
                            <select class="form-control" id="dest-type" name="type" required>
                                <optgroup label="Cloud Object Storage">
                                    <option value="s3">Amazon S3 & Compatible (Wasabi, R2, MinIO, Spaces)</option>
                                    <option value="gs">Google Cloud Storage (GCS)</option>
                                    <option value="azure">Microsoft Azure Blob Storage</option>
                                    <option value="b2">Backblaze B2 Cloud Storage</option>
                                    <option value="swift">OpenStack Swift Object Storage</option>
                                    <option value="tencent">Tencent Cloud Object Storage (COS)</option>
                                </optgroup>
                                <optgroup label="Cloud Drives & Personal">
                                    <option value="dropbox">Dropbox Cloud Storage</option>
                                    <option value="onedrive">Microsoft OneDrive / SharePoint</option>
                                </optgroup>
                                <optgroup label="Network & Transfer Protocols">
                                    <option value="sftp">SFTP (SSH File Transfer Protocol)</option>
                                    <option value="ftp">FTP / FTPS (TLS Secure Transfer)</option>
                                    <option value="webdav">WebDAV (Nextcloud, ownCloud, Generic)</option>
                                </optgroup>
                                <optgroup label="Local Server">
                                    <option value="local">Local Storage / Mounted Disk / NAS</option>
                                </optgroup>
                            </select>
                            <span class="help-block" style="font-size: 11px;">Select the backend storage service.</span>
                        </div>
                    </div>

                    <!-- Dynamic Provider Configuration Fields -->
                    <div class="panel panel-default" style="margin-top: 10px; margin-bottom: 15px;">
                        <div class="panel-heading" style="padding: 8px 15px; background: #f8f9fa;">
                            <strong><i class="fa fa-sliders"></i> Provider Configuration Settings</strong>
                            <span id="dest-provider-badge" class="label label-default pull-right" style="margin-top: 2px;"></span>
                        </div>
                        <div class="panel-body" id="dest-config-fields" style="padding: 15px;">
                            <div class="text-center text-muted" style="padding: 20px;">
                                <i class="fa fa-spinner fa-spin"></i> Loading provider fields...
                            </div>
                        </div>
                    </div>

                    <!-- Enabled Switch -->
                    <div class="checkbox" style="margin-top: 5px; margin-bottom: 0;">
                        <label>
                            <input type="checkbox" id="dest-enabled" name="enabled" value="1" checked>
                            <strong>Enable this destination</strong> (active for backup and restore tasks)
                        </label>
                    </div>
                </div>
                <div class="modal-footer" style="background: #fcfcfc;">
                    <button type="button" class="btn btn-info pull-left" id="btn-test-modal-dest">
                        <i class="fa fa-bolt"></i> Test Connection Now
                    </button>
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="btn-save-dest">
                        <i class="fa fa-check"></i> Save Destination
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================================== -->
<!-- MODAL: DELETE CONFIRMATION                                               -->
<!-- ======================================================================== -->
<div class="modal fade" id="modal-delete-dest" tabindex="-1" role="dialog" aria-labelledby="modal-delete-dest-title">
    <div class="modal-dialog modal-sm" role="document" style="max-width: 440px;">
        <div class="modal-content">
            <div class="modal-header" style="background: #d9534f; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">&times;</button>
                <h4 class="modal-title" id="modal-delete-dest-title"><i class="fa fa-trash"></i> Delete Destination</h4>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <p>Are you sure you want to delete the backup destination:</p>
                <h5 id="delete-dest-name-display" style="font-weight: bold; color: #c9302c; word-break: break-all;"></h5>
                <div class="alert alert-warning" style="font-size: 12px; margin-bottom: 0; margin-top: 15px;">
                    <i class="fa fa-info-circle"></i> <strong>Safe Deletion:</strong> This removes the configuration from rcloneCWP. Any backup archives already stored on the remote server will <strong>not</strong> be deleted.
                </div>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="delete-dest-id" value="0">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete-dest">
                    <i class="fa fa-trash"></i> Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================== -->
<!-- MODAL: CONNECTION TEST DIAGNOSTIC RESULT                                 -->
<!-- ======================================================================== -->
<div class="modal fade" id="modal-test-details" tabindex="-1" role="dialog" aria-labelledby="test-details-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header" id="test-details-header" style="background: #337ab7; color: #fff;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #fff; opacity: 0.8;">&times;</button>
                <h4 class="modal-title" id="test-details-title">
                    <i class="fa fa-stethoscope"></i> Connection Test Results
                </h4>
            </div>
            <div class="modal-body">
                <div id="test-details-status" class="alert alert-info">
                    <i class="fa fa-spinner fa-spin"></i> Running connection test...
                </div>
                <div class="form-group">
                    <label style="font-size: 12px;">Target Remote Path:</label>
                    <input type="text" class="form-control input-sm" id="test-details-target" readonly>
                </div>
                <div class="row">
                    <div class="col-xs-6 form-group">
                        <label style="font-size: 12px;">Latency:</label>
                        <div id="test-details-latency" style="font-weight: bold;">--</div>
                    </div>
                    <div class="col-xs-6 form-group">
                        <label style="font-size: 12px;">Timestamp:</label>
                        <div id="test-details-time" style="color: #777;">--</div>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="font-size: 12px;">Diagnostic Details:</label>
                    <pre id="test-details-raw" style="max-height: 180px; overflow-y: auto; background: #222; color: #eee; font-size: 11px; padding: 10px; border-radius: 3px;"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
