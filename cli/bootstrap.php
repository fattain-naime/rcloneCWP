<?php
/**
 * rcloneCWP CLI Shared Bootstrap Loader
 *
 * Common bootstrap loading logic for all CLI wrapper scripts.
 * Resolves bootstrap.php from development or production locations.
 */

// Strict CLI only
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: This script must be run from the command line.\n");
    exit(1);
}

// Locate bootstrap.php
$bootstrapPaths = [
    __DIR__ . '/../bootstrap.php',
    '/usr/local/cwp/rcloneCWP/bootstrap.php',
];

$loaded = false;
foreach ($bootstrapPaths as $path) {
    if (is_file($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Error: Cannot locate rcloneCWP bootstrap.php\n");
    exit(1);
}