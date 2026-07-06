<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getOutdatedAnyPackages();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('Outdated -any Packages');
?>

<div class="container">
    <div class="card">
        <h2>🔄 Outdated -any Packages (<?php echo count($packages); ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">-any packages that are outdated in aarch64</p>
                <a href="<?php echo Formatter::url('analysis.php'); ?>" class="back-link">← Back to Analysis</a>

        <?php if (empty($packages)): ?>
            <div class="alert alert-success">All -any packages are up-to-date! ✓</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>aarch64 Version</th>
                        <th>x86_64 Version</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $pkg): ?>
                    <tr>
                        <td>
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>">
                                <?php echo Formatter::escape($pkg['name']); ?>
                            </a>
                        </td>
                        <td><span class="badge badge-error"><?php echo Formatter::escape($pkg['aarch64_version']); ?></span></td>
                        <td><?php echo Formatter::escape($pkg['x86_64_version']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
