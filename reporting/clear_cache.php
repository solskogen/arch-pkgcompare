<?php
/**
 * Cache management utility
 * Usage: php clear_cache.php
 */
require_once __DIR__ . '/app/Cache.php';

$cache = new Cache();

// Determine action from command line or GET parameter
$action = isset($argv[1]) ? $argv[1] : (isset($_GET['action']) ? $_GET['action'] : 'info');

switch ($action) {
    case 'clear':
        $cache->clear();
        echo "Cache cleared successfully.\n";
        break;
    
    case 'info':
    default:
        $cacheDir = __DIR__ . '/cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*.cache');
            echo "Cache directory: $cacheDir\n";
            echo "Cache entries: " . count($files) . "\n";
            if (!empty($files)) {
                echo "Files:\n";
                foreach ($files as $file) {
                    $size = filesize($file);
                    $mtime = filemtime($file);
                    $age = time() - $mtime;
                    echo "  - " . basename($file) . " (" . $size . " bytes, " . $age . "s old)\n";
                }
            }
        } else {
            echo "Cache directory does not exist.\n";
        }
        break;
}
