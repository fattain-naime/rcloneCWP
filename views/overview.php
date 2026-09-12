<?php
/**
 * rcloneCWP Overview & System Status View
 *
 * System metrics, engine status, and architecture overview.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Views
 */

if (!defined('RCLONE_VERSION')) {
    exit('Direct access not allowed');
}

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Encryption;
use CWP\RcloneCWP\Rclone;

$db = Database::getInstance();
$dbConfig = $db->getConfig();
$rcloneVersion = Rclone::version() ?: 'rclone not found';
$hasKey = Encryption::hasKeyFile();
?>

<div class="overview-view">
    <!-- Stat Cards -->
    <div class="row">
        <div class="col-md-3 col-sm-6">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <div class="row">
                        <div class="col-xs-3">
                            <i class="fa fa-cloud fa-3x"></i>
                        </div>
                        <div class="col-xs-9 text-right">
                            <div class="huge" id="stat-dest-count" style="font-size: 28px; font-weight: bold;">--</div>
                            <div>Storage Destinations</div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; font-size: 12px; background: #fdfdfd;">
                    <span class="pull-left">Active: <strong id="stat-dest-active">--</strong></span>
                    <span class="pull-right"><a href="#tab-destinations" class="switch-to-destinations">Manage &rarr;</a></span>
                    <div class="clearfix"></div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6">
            <div class="panel panel-success">
                <div class="panel-heading">
                    <div class="row">
                        <div class="col-xs-3">
                            <i class="fa fa-database fa-3x"></i>
                        </div>
                        <div class="col-xs-9 text-right">
                            <div class="huge" style="font-size: 28px; font-weight: bold;">MariaDB</div>
                            <div>Database Layer</div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; font-size: 12px; background: #fdfdfd;">
                    <span class="pull-left"><?php echo htmlspecialchars($dbConfig['name']); ?></span>
                    <span class="pull-right text-success"><i class="fa fa-check-circle"></i> Connected</span>
                    <div class="clearfix"></div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6">
            <div class="panel panel-info">
                <div class="panel-heading">
                    <div class="row">
                        <div class="col-xs-3">
                            <i class="fa fa-terminal fa-3x"></i>
                        </div>
                        <div class="col-xs-9 text-right">
                            <div class="huge" style="font-size: 20px; font-weight: bold; margin-top: 5px;">
                                <?php echo htmlspecialchars(explode(' ', $rcloneVersion)[0] ?? 'v1.75+'); ?>
                            </div>
                            <div>Transport Engine</div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; font-size: 12px; background: #fdfdfd;">
                    <span class="pull-left"><?php echo htmlspecialchars(Rclone::findBinary() ?: 'rclone'); ?></span>
                    <span class="pull-right text-info"><i class="fa fa-check"></i> Ready</span>
                    <div class="clearfix"></div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6">
            <div class="panel panel-warning">
                <div class="panel-heading">
                    <div class="row">
                        <div class="col-xs-3">
                            <i class="fa fa-shield fa-3x"></i>
                        </div>
                        <div class="col-xs-9 text-right">
                            <div class="huge" style="font-size: 24px; font-weight: bold; margin-top: 4px;">AES-256</div>
                            <div>GCM Encryption</div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer" style="padding: 8px 15px; font-size: 12px; background: #fdfdfd;">
                    <span class="pull-left">Keyfile: mode 0600</span>
                    <span class="pull-right text-success">
                        <?php echo $hasKey ? '<i class="fa fa-lock"></i> Protected' : '<i class="fa fa-exclamation-triangle"></i> Missing'; ?>
                    </span>
                    <div class="clearfix"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- System Architecture & Security Information -->
    <div class="row">
        <div class="col-md-7">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="fa fa-info-circle"></i> Engine Environment & Components</h3>
                </div>
                <div class="panel-body" style="padding: 0;">
                    <table class="table table-striped table-bordered" style="margin-bottom: 0;">
                        <tbody>
                            <tr>
                                <th style="width: 200px;">Module Version</th>
                                <td><code>v<?php echo htmlspecialchars(RCLONE_VERSION); ?></code> (Native CWP Admin Module)</td>
                            </tr>
                            <tr>
                                <th>PHP Runtime</th>
                                <td><?php echo PHP_VERSION; ?> (<?php echo PHP_SAPI; ?>)</td>
                            </tr>
                            <tr>
                                <th>rclone Binary</th>
                                <td>
                                    <code><?php echo htmlspecialchars(Rclone::getBinaryPath() ?: 'Not installed'); ?></code>
                                    <span class="text-muted" style="margin-left: 10px;">(<?php echo htmlspecialchars($rcloneVersion); ?>)</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Master Encryption Key</th>
                                <td>
                                    <code><?php echo htmlspecialchars(RCLONE_KEYFILE); ?></code>
                                    <?php if ($hasKey): ?>
                                        <span class="label label-success pull-right">Active (0600)</span>
                                    <?php else: ?>
                                        <span class="label label-danger pull-right">Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Runtime Home</th>
                                <td><code><?php echo htmlspecialchars(RCLONE_HOME); ?></code> (Off-htdocs, mode 0700)</td>
                            </tr>
                            <tr>
                                <th>Web Module Entry</th>
                                <td><code><?php echo htmlspecialchars(RCLONE_MODULE_FILE); ?></code> (Mode 0644)</td>
                            </tr>
                            <tr>
                                <th>Log Directory</th>
                                <td><code><?php echo htmlspecialchars(RCLONE_LOG_DIR); ?></code></td>
                            </tr>
                            <tr>
                                <th>Supported Providers</th>
                                <td>
                                    <span class="label label-primary">Local</span>
                                    <span class="label label-primary">Amazon S3</span>
                                    <span class="label label-primary">Google Cloud</span>
                                    <span class="label label-primary">Azure Blob</span>
                                    <span class="label label-primary">Backblaze B2</span>
                                    <span class="label label-primary">Dropbox</span>
                                    <span class="label label-primary">OneDrive</span>
                                    <span class="label label-primary">SFTP</span>
                                    <span class="label label-primary">FTP/FTPS</span>
                                    <span class="label label-primary">WebDAV</span>
                                    <span class="label label-primary">Swift</span>
                                    <span class="label label-primary">Tencent COS</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="fa fa-lock"></i> Security & Architecture Highlights</h3>
                </div>
                <div class="panel-body">
                    <div class="media">
                        <div class="media-left">
                            <i class="fa fa-memory fa-2x text-primary" style="font-size: 24px;"></i>
                        </div>
                        <div class="media-body">
                            <h5 class="media-heading" style="font-weight: bold;">In-Memory Credential Passing</h5>
                            <p class="text-muted" style="font-size: 12px;">
                                Secrets are decrypted only in RAM and passed to rclone via dynamic environment variables (<code>RCLONE_CONFIG_*</code>). Zero plaintext credentials are ever written to <code>/etc/rclone/rclone.conf</code> or temporary disk files.
                            </p>
                        </div>
                    </div>
                    <hr style="margin: 10px 0;">
                    <div class="media">
                        <div class="media-left">
                            <i class="fa fa-shield fa-2x text-success" style="font-size: 24px;"></i>
                        </div>
                        <div class="media-body">
                            <h5 class="media-heading" style="font-weight: bold;">At-Rest AES-256-GCM Encryption</h5>
                            <p class="text-muted" style="font-size: 12px;">
                                Storage credentials in MariaDB <code>rclone_destinations.config</code> are authenticated and encrypted with 256-bit AES-GCM and a unique 12-byte IV per field.
                            </p>
                        </div>
                    </div>
                    <hr style="margin: 10px 0;">
                    <div class="media">
                        <div class="media-left">
                            <i class="fa fa-folder-open fa-2x text-warning" style="font-size: 24px;"></i>
                        </div>
                        <div class="media-body">
                            <h5 class="media-heading" style="font-weight: bold;">Placement-Based Isolation</h5>
                            <p class="text-muted" style="font-size: 12px;">
                                Only the flat module dispatcher file resides in the web document root. All application logic, database schemas, and cryptographic materials remain isolated off-htdocs under strict root-only permissions.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
