<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $diffs = $repo->getRepoDifferences();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('Repository Differences');
?>

<div class="container">
    <div class="card">
        <h2>📚 Repository Differences (<?php echo count($diffs); ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Packages in the wrong repository on aarch64</p>
                <a href="<?php echo Formatter::url('analysis.php'); ?>" class="back-link">← Back to Analysis</a>

        <?php if (empty($diffs)): ?>
            <div class="alert alert-info">No repository differences found.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>aarch64 Repository</th>
                        <th>x86_64 Repository</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($diffs as $d): ?>
                    <tr>
                        <td>
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $d['pkg_name']]); ?>">
                                <?php echo Formatter::escape($d['pkg_name']); ?>
                            </a>
                        </td>
                        <td><?php echo $d['aarch64_repo'] ? Formatter::escape($d['aarch64_repo']) : '—'; ?></td>
                        <td><?php echo $d['x86_64_repo'] ? Formatter::escape($d['x86_64_repo']) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
