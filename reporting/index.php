<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $stats = $repo->getStats();
} catch (Exception $e) {
    error_log("Error in index.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

Layout::header('Arch Linux Package Reporting');
?>

<div class="container">
    <div class="card">
        <h2>🏠 Welcome to Arch Linux Package Reporting</h2>
        <p style="margin-bottom: 20px; opacity: 0.9;">
            This tool analyzes and compares Arch Linux packages across aarch64 and x86_64 architectures.
            Browse the analysis reports to find inconsistencies, version differences, and architectural gaps.
        </p>
    </div>

    <div class="card">
        <h2>📊 Quick Statistics</h2>
        <div class="stats-grid">
            <a href="<?php echo Formatter::url('report-packages-aarch64.php'); ?>" style="text-decoration: none;">
                <div class="stat-box">
                    <div class="value"><?php echo Formatter::number($stats['aarch64_packages']); ?></div>
                    <div class="label">aarch64 Packages</div>
                </div>
            </a>
            <a href="<?php echo Formatter::url('report-packages-x86_64.php'); ?>" style="text-decoration: none;">
                <div class="stat-box">
                    <div class="value"><?php echo Formatter::number($stats['x86_64_packages']); ?></div>
                    <div class="label">x86_64 Packages</div>
                </div>
            </a>
            <a href="<?php echo Formatter::url('report-x86_64-only.php'); ?>" style="text-decoration: none;">
                <div class="stat-box">
                    <div class="value"><?php echo Formatter::number($stats['x86_64_only_count']); ?></div>
                    <div class="label">x86_64 Only</div>
                </div>
            </a>
            <a href="<?php echo Formatter::url('report-aarch64-only.php'); ?>" style="text-decoration: none;">
                <div class="stat-box">
                    <div class="value"><?php echo Formatter::number($stats['aarch64_only_count']); ?></div>
                    <div class="label">aarch64 Only</div>
                </div>
            </a>
            <a href="<?php echo Formatter::url('analysis.php'); ?>" style="text-decoration: none;">
                <div class="stat-box">
                    <div class="value"><?php echo Formatter::number($stats['aarch64_size_mb']); ?> MB</div>
                    <div class="label">aarch64 Compressed Size</div>
                </div>
            </a>
            <a href="<?php echo Formatter::url('analysis.php'); ?>" style="text-decoration: none;">
                <div class="stat-box">
                    <div class="value"><?php echo Formatter::number($stats['x86_64_size_mb']); ?> MB</div>
                    <div class="label">x86_64 Compressed Size</div>
                </div>
            </a>
            <a href="<?php echo Formatter::url('report-x86_64-newer.php'); ?>" style="text-decoration: none;">
                <div class="stat-box">
                    <div class="value"><?php echo Formatter::number($stats['x86_64_newer_count']); ?></div>
                    <div class="label">x86_64 Newer</div>
                </div>
            </a>
        </div>
    </div>

    <div class="card">
        <h2>📋 Available Tools</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Choose an option below to get started:</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
            <a href="<?php echo Formatter::url('analysis.php'); ?>" style="text-decoration: none;">
                <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a; transition: all 0.3s; cursor: pointer; hover-transition: 0.3s;">
                    <div style="font-size: 24px; margin-bottom: 10px;">📈</div>
                    <h3 style="color: #90caf9; margin-bottom: 5px;">Package Analysis</h3>
                    <p style="font-size: 13px; opacity: 0.7;">
                        Browse detailed analysis reports including mismatches, outdated packages, and version differences
                    </p>
                </div>
            </a>
            
            <a href="<?php echo Formatter::url('comparison.php'); ?>" style="text-decoration: none;">
                <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a; transition: all 0.3s; cursor: pointer;">
                    <div style="font-size: 24px; margin-bottom: 10px;">⚖️</div>
                    <h3 style="color: #90caf9; margin-bottom: 5px;">Detailed Comparison</h3>
                    <p style="font-size: 13px; opacity: 0.7;">
                        Compare specific packages across architectures with detailed version and size information
                    </p>
                </div>
            </a>
        </div>
    </div>

    <div class="card">
        <h2>ℹ️ About This Tool</h2>
        <p>
            This reporting system provides comprehensive analysis of the Arch Linux package ecosystem,
            comparing build artifacts, versions, and availability across aarch64 and x86_64 platforms.
            Use this to identify maintenance issues, missing packages, and inconsistencies.
        </p>
    </div>
</div>

<?php Layout::footer();
