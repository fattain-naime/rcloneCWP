<?php
/**
 * rcloneCWP Module Entry Point
 *
 * The CWP dispatcher includes this file after setting $include_path.
 * We guard against direct HTTP access and validate the admin session.
 * @package CWP\RcloneCWP
 */

// Primary guard: refuse if not loaded via CWP dispatcher
if (!isset($include_path)) {
    echo "invalid access";
    exit();
}

// Defense-in-depth: verify an admin session exists when one is trackable.
// The dispatcher is ionCube-encoded so exact session key names are
// unverifiable from source; lkey/cp are the documented CWP keys. If session
// tracking is inactive (session.auto_cookies off in some SAPIs), fall back
// to the $include_path guard above rather than blocking the module.
if (session_status() === PHP_SESSION_ACTIVE
    && !isset($_SESSION['lkey'])
    && !isset($_SESSION['cp'])
    && !isset($_SESSION['username'])
) {
    echo "authentication required";
    exit();
}

// Load the off-htdocs runtime
$homeDir = '/usr/local/cwp/rcloneCWP';
if (!is_file($homeDir . '/bootstrap.php')) {
    echo "<h3>rcloneCWP not installed</h3>";
    echo "<p>Run the installer first:</p>";
    echo "<pre>curl -sSL https://github.com/fattain_naive/rcloneCWP/main/install.sh | bash</pre>";
    exit();
}

require_once $homeDir . '/bootstrap.php';

use CWP\RcloneCWP\Database;
use CWP\RcloneCWP\Logger;
use CWP\RcloneCWP\Rclone;

try {
    // Self-heal: re-add menu entry if missing (CWP updates may clobber 3rdparty.php).
    // Delegates to the Installer so the entry format is identical to install-time.
    $installer = new \CWP\RcloneCWP\Installer(null, null);
    $installer->selfHealMenuEntry();

    // --- Render Dashboard ---
    $db = Database::getInstance();
    $logger = new Logger(RCLONE_LOG_DIR, $db);
    $rcloneVer = Rclone::version() ?: 'rclone not found';

    ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">rcloneCWP <small><?php echo htmlspecialchars(RCLONE_VERSION); ?></small></h3>
                    </div>
                    <div class="panel-body">
                        <div class="alert alert-info">
                            <strong>Phase 1 Foundation</strong> — core library installed, ready for Phase 2 development.
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead>
                                    <tr>
                                        <th>Component</th>
                                        <th>Status</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Database</td>
                                        <td><span class="label label-success">Connected</span></td>
                                        <td><?php echo htmlspecialchars($db->getConfig()['name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td>rclone</td>
                                        <td><span class="label label-success">Detected</span></td>
                                        <td><?php echo htmlspecialchars($rcloneVer); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Logger</td>
                                        <td><span class="label label-success">Active</span></td>
                                        <td><?php echo htmlspecialchars($logger->getLogDir()); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Encryption</td>
                                        <td><?php echo \CWP\RcloneCWP\Encryption::hasKeyFile() ? '<span class="label label-success">Key present</span>' : '<span class="label label-warning">No key</span>'; ?></td>
                                        <td><?php echo htmlspecialchars(RCLONE_KEYFILE); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="well well-sm">
                            <h4>Next Steps</h4>
                            <ul class="list-unstyled">
                                <li>✓ Phase 1: Foundation (core library) — <strong>DONE</strong></li>
                                <li>○ Phase 2: Destinations (12 backend types) — pending</li>
                                <li>○ Phase 3: Backup Engine — pending</li>
                                <li>○ Phase 4: Restore Engine — pending</li>
                            </ul>
                        </div>

                        <div class="alert alert-warning">
                            <strong>Note:</strong> This is a development build. Full UI and features coming in Phase 2+.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
} catch (\Exception $e) {
    echo "<div class=\"alert alert-danger\"><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
}
