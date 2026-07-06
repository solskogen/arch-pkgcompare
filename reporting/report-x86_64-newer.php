<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getX86_64Newer();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('x86_64 Newer Versions');
?>

<div class="container">
    <div class="card">
        <h2>⬆️ x86_64 Newer Versions (<?php echo count($packages); ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Packages with newer versions in x86_64 than aarch64</p>
                <a href="<?php echo Formatter::url('analysis.php'); ?>" class="back-link">← Back to Analysis</a>

        <?php if (empty($packages)): ?>
            <div class="alert alert-info">No packages found.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>aarch64 Version</th>
                        <th>x86_64 Version</th>
                        <th>Difference</th>
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
                        <td><?php echo Formatter::escape($pkg['aarch64_version']); ?></td>
                        <td><span class="badge badge-warning"><?php echo Formatter::escape($pkg['x86_64_version']); ?></span></td>
                        <td><span class="badge badge-warning">newer</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
