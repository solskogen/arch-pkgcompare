<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getPackagesByArch('x86_64');
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('x86_64 Packages');
?>

<div class="container">
    <div class="card">
        <h2>📦 x86_64 Packages (<?php echo count($packages); ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">All packages available for x86_64 architecture</p>

        <?php if (empty($packages)): ?>
            <div class="alert alert-info">No packages found.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>Version</th>
                        <th>Repository</th>
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
                        <td><?php echo Formatter::escape($pkg['version']); ?></td>
                        <td><?php echo Formatter::escape($pkg['repo']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
