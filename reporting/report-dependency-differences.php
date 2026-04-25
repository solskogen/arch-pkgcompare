<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getPackagesWithDependencyDifferences();
} catch (Exception $e) {
    error_log("Error in report-dependency-differences.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

Layout::header('Dependency Differences');
?>

<div class="container">
    <div class="card">
        <h2>🔗 Dependency Differences</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">
            Packages that have different dependencies between aarch64 and x86_64 architectures
        </p>

        <?php if (empty($packages)): ?>
            <div class="alert alert-success">✓ All packages have consistent dependencies across architectures!</div>
        <?php else: ?>
            <p style="margin-bottom: 15px; opacity: 0.7;">Found <?php echo count($packages); ?> package(s) with different dependencies</p>
            
            <div class="packages-table">
                <table>
                    <thead>
                        <tr>
                            <th>Package</th>
                            <th>aarch64 Dependencies</th>
                            <th>x86_64 Dependencies</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $pkg): ?>
                        <tr style="vertical-align: top;">
                            <td>
                                <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #64b5f6; text-decoration: none; font-weight: 500;">
                                    <?php echo Formatter::escape($pkg['name']); ?>
                                </a>
                                <br>
                                <span style="font-size: 0.85em; opacity: 0.7;">aarch64: v<?php echo Formatter::escape($pkg['aarch64_version']); ?> | x86_64: v<?php echo Formatter::escape($pkg['x86_64_version']); ?></span>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid rgba(100, 181, 246, 0.1);">
                                <?php 
                                $aarch64_deps = $pkg['aarch64_deps'] ? array_filter(array_map('trim', explode(',', $pkg['aarch64_deps']))) : [];
                                if (empty($aarch64_deps)): 
                                ?>
                                    <span style="color: #999;">—</span>
                                <?php else: ?>
                                    <ul style="margin: 0; padding-left: 20px; list-style: none;">
                                    <?php foreach ($aarch64_deps as $dep): ?>
                                        <li style="font-size: 0.9em; color: #90caf9;">• <?php echo Formatter::escape($dep); ?></li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid rgba(100, 181, 246, 0.1);">
                                <?php 
                                $x86_64_deps = $pkg['x86_64_deps'] ? array_filter(array_map('trim', explode(',', $pkg['x86_64_deps']))) : [];
                                if (empty($x86_64_deps)): 
                                ?>
                                    <span style="color: #999;">—</span>
                                <?php else: ?>
                                    <ul style="margin: 0; padding-left: 20px; list-style: none;">
                                    <?php foreach ($x86_64_deps as $dep): ?>
                                        <li style="font-size: 0.9em; color: #90caf9;">• <?php echo Formatter::escape($dep); ?></li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #333;">
            <a href="<?php echo Formatter::url('analysis.php'); ?>" style="color: #64b5f6;">← Back to Analysis</a>
        </div>
    </div>
</div>

<?php Layout::footer();
?>
