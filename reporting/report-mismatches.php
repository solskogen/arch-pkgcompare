<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $mismatches = $repo->getMismatches();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('Package Name Mismatches');
?>

<div class="container">
    <div class="card">
        <h2>📦 Package Base Mismatches (<?php echo count($mismatches); ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Packages from the wrong base (pkgbase) on aarch64</p>
                <a href="<?php echo Formatter::url('analysis.php'); ?>" class="back-link">← Back to Analysis</a>

        <?php if (empty($mismatches)): ?>
            <div class="alert alert-info">No mismatches found.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>aarch64 Parent</th>
                        <th>x86_64 Parent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mismatches as $m): ?>
                    <tr>
                        <td>
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $m['name']]); ?>">
                                <?php echo Formatter::escape($m['name']); ?>
                            </a>
                        </td>
                        <td><?php echo Formatter::escape($m['aarch64_base']); ?></td>
                        <td><?php echo Formatter::escape($m['x86_64_base']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
