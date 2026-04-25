<?php
/**
 * Comprehensive PHP test suite for the application
 * Tests configuration, helpers, security, and file structure
 * No database access required
 */

class TestResults {
    private $passed = 0;
    private $failed = 0;
    private $tests = [];
    
    public function assert($condition, $message) {
        if ($condition) {
            echo "  ✓ $message\n";
            $this->passed++;
        } else {
            echo "  ✗ $message\n";
            $this->failed++;
        }
        $this->tests[] = ['message' => $message, 'passed' => $condition];
    }
    
    public function summary() {
        echo "\n========================================\n";
        echo "Test Results: {$this->passed} passed, {$this->failed} failed\n";
        echo "========================================\n";
        return $this->failed === 0;
    }
}

$results = new TestResults();

// ============================================================================
// PART 1: CONFIGURATION TESTS
// ============================================================================
echo "\n1. Configuration Tests\n";
echo "----------------------\n";

$root_config = __DIR__ . '/../config.ini';
$php_config = __DIR__ . '/../reporting/config.ini';

$results->assert(file_exists($root_config), "Root config.ini exists");
$results->assert(file_exists($php_config), "PHP config.ini exists");

// PHP's parse_ini_file() doesn't support '#' comments, so check manually instead
$root_content = file_get_contents($root_config);
$php_content = file_get_contents($php_config);

// Check that configs have required sections
$root_has_sections = preg_match('/\[database\]|\[arch-.*?\]|\[loader\]/i', $root_content);
$php_has_sections = preg_match('/\[database\]|\[cache\]/i', $php_content);

$results->assert($root_has_sections, "Root config.ini is valid");
$results->assert($php_has_sections, "PHP config.ini is valid");

// Check database configuration
$results->assert(preg_match('/host\s*=/', $root_content), "Database host configured");
$results->assert(preg_match('/user\s*=/', $root_content), "Database user configured");
$results->assert(preg_match('/password\s*=/', $root_content), "Database password configured");
$results->assert(preg_match('/database\s*=/', $root_content), "Database name configured");

// Check that at least 2 architectures are configured (exactly 2 for binary comparison)
// Match only actual section headers: [arch-xxx] followed by newline or EOF
$arch_count = preg_match_all('/^\[arch-([a-z0-9_]+)\]/m', $root_content, $matches);
$results->assert($arch_count == 2, "Exactly 2 architectures required for binary comparison");

// Check for architecture repositories (template or direct format)
$has_aarch64 = preg_match('/\[arch-aarch64\]/', $root_content);
$has_x86_64 = preg_match('/\[arch-x86_64\]/', $root_content);
$results->assert($has_aarch64, "aarch64 repositories configured");
$results->assert($has_x86_64, "x86_64 repositories configured");

// Check for either template format (url_template + repos) or direct URLs
$has_templates = preg_match_all('/url_template\s*=|repos\s*=/', $root_content) >= 2;
$has_direct_urls = preg_match_all('/core\s*=|extra\s*=|forge\s*=|testing\s*=/', $root_content) >= 2;
$results->assert($has_templates || $has_direct_urls, "Repositories configured (template or direct)");

// ============================================================================
// PART 2: FILE STRUCTURE TESTS
// ============================================================================
echo "\n2. File Structure Tests\n";
echo "----------------------\n";

$required_files = [
    'reporting/index.php',
    'reporting/boot.php',
    'reporting/package-detail.php',
    'reporting/analysis.php',
    'reporting/app/Database.php',
    'reporting/app/PackageRepository.php',
    'reporting/app/Helpers.php',
    'reporting/app/Cache.php',
];

foreach ($required_files as $file) {
    $path = __DIR__ . '/../' . $file;
    $results->assert(file_exists($path), "File exists: $file");
}

$required_dirs = [
    'reporting/cache',
    'reporting/app',
    'reporting/css',
];

foreach ($required_dirs as $dir) {
    $path = __DIR__ . '/../' . $dir;
    $results->assert(is_dir($path), "Directory exists: $dir");
}

// Test cache directory is writable
$cache_dir = __DIR__ . '/../reporting/cache';
$test_file = $cache_dir . '/.test_write_' . uniqid();
$can_write = false;
if (@file_put_contents($test_file, 'test')) {
    @unlink($test_file);
    $can_write = true;
}
$results->assert($can_write, "Cache directory is writable");

// ============================================================================
// PART 3: HELPER FUNCTIONS TESTS
// ============================================================================
echo "\n3. Helper Functions Tests\n";
echo "------------------------\n";

require_once __DIR__ . '/../reporting/app/Helpers.php';

// Test Formatter::escape() with various inputs
$escape_tests = [
    ['<script>alert("xss")</script>', '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'],
    ['"quotes"', '&quot;quotes&quot;'],
    ["'single'", '&#039;single&#039;'],
    ['&ampersand', '&amp;ampersand'],
    ['normal text', 'normal text'],
    ['', ''],
    ['<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;'],
];

foreach ($escape_tests as [$input, $expected]) {
    $output = Formatter::escape($input);
    $desc = strlen($input) > 30 ? substr($input, 0, 27) . '...' : $input;
    $results->assert($output === $expected, "Formatter::escape() handles: '$desc'");
}

// Test Formatter::url() with various parameters
$url_tests = [
    ['test.php', ['name' => 'gcc'], ['test.php', 'name=gcc']],
    ['detail.php', ['id' => '123', 'type' => 'pkg'], ['detail.php', 'id=123', 'type=pkg']],
    ['page.php', [], ['page.php']],
];

foreach ($url_tests as [$page, $params, $expected_parts]) {
    $url = Formatter::url($page, $params);
    $all_present = true;
    foreach ($expected_parts as $part) {
        if (strpos($url, $part) === false) {
            $all_present = false;
        }
    }
    $desc = $page . (count($params) > 0 ? ' with params' : '');
    $results->assert($all_present, "Formatter::url() generates correct URL: $desc");
}

// ============================================================================
// PART 4: CACHE CLASS TESTS
// ============================================================================
echo "\n4. Cache Class Tests\n";
echo "-------------------\n";

require_once __DIR__ . '/../reporting/app/Cache.php';

// Test Cache class can be instantiated
try {
    $cache = new Cache(3600);
    $results->assert(true, "Cache class instantiates");
} catch (Exception $e) {
    $results->assert(false, "Cache class instantiates: " . $e->getMessage());
}

// Test cache operations
if (isset($cache)) {
    $test_key = 'test_key_' . uniqid();
    $test_data = ['test' => 'data', 'value' => 123];
    
    $cache->set($test_key, $test_data);
    $retrieved = $cache->get($test_key);
    
    $results->assert(
        $retrieved === $test_data,
        "Cache set/get operations work"
    );
    
    $cache->delete($test_key);
    $retrieved_after = $cache->get($test_key);
    
    $results->assert(
        $retrieved_after === null,
        "Cache delete removes data"
    );
}

// ============================================================================
// PART 5: SECURITY TESTS
// ============================================================================
echo "\n5. Security Tests\n";
echo "-----------------\n";

// Test no hardcoded paths
$reporting_dir = __DIR__ . '/../reporting';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($reporting_dir),
    RecursiveIteratorIterator::SELF_FIRST
);

$bad_patterns = [
    'solskogen' => 'solskogen',
    'antarctica.no' => 'antarctica.no',
    '/home/solskogen' => '/home/solskogen',
    '/data/home' => '/data/home',
    '/public_html' => 'public_html',
];

$found_bad_paths = [];
foreach ($iterator as $file) {
    if ($file->isFile() && ($file->getExtension() === 'php' || $file->getExtension() === 'js')) {
        $content = file_get_contents($file);
        foreach ($bad_patterns as $name => $pattern) {
            if (stripos($content, $pattern) !== false) {
                $found_bad_paths[$file->getRelativePathname()] = $name;
            }
        }
    }
}

$results->assert(count($found_bad_paths) === 0, "No hardcoded deployment paths");
if (count($found_bad_paths) > 0) {
    foreach ($found_bad_paths as $file => $pattern) {
        echo "    Found '$pattern' in $file\n";
    }
}

// Test SQL injection prevention patterns
$sql_files = glob($reporting_dir . '/app/*.php');
$sql_safe = true;
foreach ($sql_files as $file) {
    $content = file_get_contents($file);
    // Only check for actual SQL queries (strings containing SELECT, not method names like "delete()")
    // Real SQL queries appear in strings like: "SELECT * FROM" or ->query("SELECT")
    if (preg_match('/["\'].*\s(SELECT|INSERT|UPDATE|DELETE|WHERE)\s/i', $content)) {
        // If it has actual SQL queries, it should use prepared statements
        if (!preg_match('/prepare\(|bind_param/i', $content)) {
            $sql_safe = false;
            echo "    Warning: SQL found but no prepare() in " . basename($file) . "\n";
        }
    }
}
$results->assert($sql_safe, "SQL injection prevention patterns present");

// ============================================================================
// PART 6: PHP SYNTAX VALIDATION
// ============================================================================
echo "\n6. PHP Syntax Validation\n";
echo "----------------------\n";

$php_files = [
    'reporting/index.php',
    'reporting/boot.php',
    'reporting/package-detail.php',
    'reporting/analysis.php',
    'reporting/app/Database.php',
    'reporting/app/PackageRepository.php',
    'reporting/app/Helpers.php',
    'reporting/app/Cache.php',
];

foreach ($php_files as $file) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        $output = [];
        $return_code = 0;
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return_code);
        $is_valid = $return_code === 0;
        $results->assert($is_valid, "PHP syntax valid: $file");
    }
}

// ============================================================================
// PART 7: REPORT FILES TESTS
// ============================================================================
echo "\n7. Report Pages Tests\n";
echo "-------------------\n";

$report_files = glob(__DIR__ . '/../reporting/report-*.php');
$results->assert(count($report_files) >= 28, "28+ report pages present");

// Check each report file has valid syntax
$invalid_reports = [];
foreach ($report_files as $file) {
    $output = [];
    $return_code = 0;
    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return_code);
    if ($return_code !== 0) {
        $invalid_reports[] = basename($file);
    }
}

$results->assert(count($invalid_reports) === 0, "All report pages have valid PHP syntax");
if (count($invalid_reports) > 0) {
    echo "    Invalid reports: " . implode(', ', $invalid_reports) . "\n";
}

// ============================================================================
// SUMMARY
// ============================================================================
$all_pass = $results->summary();
exit($all_pass ? 0 : 1);
