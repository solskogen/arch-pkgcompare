<?php
/**
 * Arch Linux Package Database - Configuration
 */

// Security headers - must be called before any output
if (!function_exists('setSecurityHeaders')) {
    function setSecurityHeaders() {
        // Prevent clickjacking attacks
        header('X-Frame-Options: DENY', true);
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff', true);
        // Enable XSS protection in older browsers
        header('X-XSS-Protection: 1; mode=block', true);
        // Content Security Policy - prevent inline scripts and external script execution
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; frame-ancestors 'none';", true);
        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        // Permissions Policy
        header('Permissions-Policy: microphone=(), camera=(), geolocation=(), payment=()', true);
    }
}
setSecurityHeaders();

// Load configuration from config.ini
$configFile = __DIR__ . '/config.ini';
$config = parse_ini_file($configFile, true);

if ($config === false) {
    error_log("Failed to load config.ini from " . $configFile, 3, "/var/log/reporting.log");
    die('Configuration error. Please ensure config.ini exists in the reporting directory.');
}

// Database configuration - read from environment with fallback to config.ini then defaults
define('DB_HOST', getenv('DB_HOST') ?: ($config['database']['host'] ?? 'localhost'));
define('DB_USER', getenv('DB_USER') ?: ($config['database']['user'] ?? 'aarch64linux'));
define('DB_PASS', getenv('DB_PASS') ?: ($config['database']['password'] ?? ''));
define('DB_NAME', getenv('DB_NAME') ?: ($config['database']['database'] ?? 'aarch64linux'));

// Cache configuration
define('CACHE_ENABLED', getenv('CACHE_ENABLED') ?: ($config['cache']['enabled'] ?? 'true'));
define('CACHE_TTL', getenv('CACHE_TTL') ?: ($config['cache']['ttl'] ?? 3600));
define('CACHE_DIR', getenv('CACHE_DIR') ?: ($config['cache']['directory'] ?? 'cache'));

// Logging configuration
define('ERROR_LOG', getenv('ERROR_LOG') ?: ($config['logging']['error_log'] ?? '/var/log/reporting.log'));

// Connection function
function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error, 3, ERROR_LOG);
        die('An internal error occurred. Please contact support.');
    }
    $conn->set_charset("utf8");
    return $conn;
}

// Helper function to safely escape strings
function esc($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Helper function to format numbers with commas
function fmt($num) {
    return number_format(intval($num));
}

// Helper function to format bytes to MB
function fmtSize($bytes) {
    if (!$bytes) return '-';
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
}

// Helper function to format build date (Unix timestamp)
function fmtDate($timestamp) {
    if (!$timestamp) return '-';
    return date('Y-m-d H:i:s', intval($timestamp));
}

// Get base URL for the application
function baseUrl() {
    $script_name = $_SERVER['SCRIPT_NAME'];
    
    // For user home directories like /~username/reporting/...
    if (preg_match('|~[^/]+/reporting|', $script_name)) {
        // Extract the full path up to /reporting/
        if (preg_match('|(~[^/]+/reporting)/|', $script_name, $matches)) {
            return '/' . $matches[1] . '/';
        }
    }
    
    // For regular /reporting/ deployment
    return '/reporting/';
}

// Initialize architecture helper
require_once __DIR__ . '/app/ArchitectureHelper.php';
$db = Database::getInstance();
$repo = new PackageRepository($db);
ArchitectureHelper::init($repo);

// Set default header subtitle for architecture comparison
if (!isset($header_subtitle)) {
    $header_subtitle = ArchitectureHelper::getComparisonText();
}
?>
