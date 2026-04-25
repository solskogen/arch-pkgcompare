<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getRepoDifferencesDetailed();
} catch (Exception $e) {
    error_log("Error in report-repo-mismatches.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

Layout::header('Repository Mismatches');
?>

<div class="container">
    <div class="card">
        <h2>🏠 Repository Mismatches</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Packages in the wrong repository on aarch64</p>

        <?php if (empty($packages)): ?>
            <div class="alert alert-success">✓ All packages are in the same repository across architectures!</div>
        <?php else: ?>
            <p style="margin-bottom: 15px; opacity: 0.7;">Found <?php echo count($packages); ?> package(s) with repository mismatches</p>
            
            <div class="packages-table">
                <table>
                    <thead>
                        <tr>
                            <th>Package</th>
                            <th>aarch64 Repo</th>
                            <th>x86_64 Repo</th>
                            <th>aarch64 Version</th>
                            <th>x86_64 Version</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $pkg): ?>
                        <tr>
                            <td>
                                <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                    <?php echo Formatter::escape($pkg['name']); ?>
                                </a>
                            </td>
                            <td>
                                <span style="background: rgba(76, 175, 80, 0.2); color: #66bb6a; padding: 3px 8px; border-radius: 3px; font-size: 0.9em;">
                                    <?php echo Formatter::escape($pkg['aarch64_repo']); ?>
                                </span>
                            </td>
                            <td>
                                <span style="background: rgba(244, 67, 54, 0.2); color: #ef5350; padding: 3px 8px; border-radius: 3px; font-size: 0.9em;">
                                    <?php echo Formatter::escape($pkg['x86_64_repo']); ?>
                                </span>
                            </td>
                            <td><?php echo Formatter::escape($pkg['aarch64_version']); ?></td>
                            <td><?php echo Formatter::escape($pkg['x86_64_version']); ?></td>
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
