<?php
/**
 * Synchronize workspace files to live CWP locations
 */
$source = '/root/rcloneCWP';
$destRuntime = '/usr/local/cwp/rcloneCWP';
$destModule = '/usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php';

// 1. Copy web module entry point
copy($source . '/rcloneCWP.php', $destModule);
chmod($destModule, 0644);
echo "Deployed module entry to $destModule\n";

// 2. Recursive copy directories: lib, views, sql
$dirs = ['lib', 'views', 'sql'];
foreach ($dirs as $dir) {
    $srcDir = $source . '/' . $dir;
    $dstDir = $destRuntime . '/' . $dir;
    if (!is_dir($dstDir)) {
        mkdir($dstDir, 0755, true);
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $subPath = substr($item->getPathname(), strlen($srcDir) + 1);
        $target = $dstDir . '/' . $subPath;
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            copy($item->getPathname(), $target);
            chmod($target, 0644);
        }
    }
    echo "Synchronized $dir to $dstDir\n";
}

// 3. Root runtime files
$files = ['config.php', 'bootstrap.php', 'install.php', 'uninstall.php'];
foreach ($files as $f) {
    if (file_exists($source . '/' . $f)) {
        copy($source . '/' . $f, $destRuntime . '/' . $f);
        chmod($destRuntime . '/' . $f, ($f === 'install.php' || $f === 'uninstall.php') ? 0700 : 0644);
    }
}
echo "Synchronized root runtime files to $destRuntime\n";
echo "SUCCESS\n";
