<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getPackagesWithProvidesDifferences();
} catch (Exception $e) {
    error_log("Error in report-provides-differences.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

Layout::header('Provides Differences');
?>

<div class="container">
    <div class="card">
        <h2>📦 Provides/Virtual Package Differences</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">
            Packages that provide different virtual packages between aarch64 and x86_64
            (e.g., gcc provides gcc-ada on one arch but not the other)
        </p>

        <?php if (empty($packages)): ?>
            <div class="alert alert-success">✓ All packages provide the same virtual packages across architectures!</div>
        <?php else: ?>
            <p style="margin-bottom: 15px; opacity: 0.7;">Found <?php echo count($packages); ?> package(s) with provides differences</p>
            
            <div class="packages-table">
                <?php foreach ($packages as $pkg): ?>
                    <?php
                    $aarch64 = array_filter(array_map('trim', explode(',', $pkg['aarch64_provides'] ?? '')));
                    $x86_64 = array_filter(array_map('trim', explode(',', $pkg['x86_64_provides'] ?? '')));
                    $only_aarch64 = array_diff($aarch64, $x86_64);
                    $only_x86_64 = array_diff($x86_64, $aarch64);
                    ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #1a1a1a;">
                        <h3 style="color: #64b5f6; margin-bottom: 12px;">
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                <?php echo Formatter::escape($pkg['name']); ?>
                            </a>
                        </h3>
                        
                        <?php if (!empty($only_aarch64)): ?>
                        <div style="margin-bottom: 12px;">
                            <strong style="color: #66bb6a;">✓ Only in aarch64:</strong>
                            <div style="color: #66bb6a; font-size: 0.95em; margin-top: 4px; padding: 8px; background: rgba(102, 187, 106, 0.1); border-radius: 4px;">
                                <?php foreach ($only_aarch64 as $item): ?>
                                    <div style="margin: 3px 0;">• <?php echo Formatter::escape($item); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($only_x86_64)): ?>
                        <div>
                            <strong style="color: #ef5350;">✓ Only in x86_64:</strong>
                            <div style="color: #ef5350; font-size: 0.95em; margin-top: 4px; padding: 8px; background: rgba(239, 83, 80, 0.1); border-radius: 4px;">
                                <?php foreach ($only_x86_64 as $item): ?>
                                    <div style="margin: 3px 0;">• <?php echo Formatter::escape($item); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #333;">
            <a href="<?php echo Formatter::url('analysis.php'); ?>" style="color: #64b5f6;">← Back to Analysis</a>
        </div>
    </div>
</div>

<?php Layout::footer();
?>
