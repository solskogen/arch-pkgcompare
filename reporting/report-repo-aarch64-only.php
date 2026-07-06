<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

$repo = isset($_GET['repo']) ? $_GET['repo'] : 'core';
if (!in_array($repo, ['core', 'extra', 'forge'])) {
    $repo = 'core';
}

try {
    $db = Database::getInstance();
    $repo_obj = new PackageRepository($db);
    $packages = $repo_obj->getRepoAarch64Only($repo);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header($repo . ' Repository - aarch64 Only Packages');
?>

<div class="container">
    <div class="card">
        <h2>✨ <?php echo ucfirst(Formatter::escape($repo)); ?> Repository - aarch64 Only (<?php echo count($packages); ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Packages in this repository on aarch64 but not on x86_64</p>
                <a href="<?php echo Formatter::url('analysis.php'); ?>" class="back-link">← Back to Analysis</a>

        <?php if (empty($packages)): ?>
            <div class="alert alert-success">✓ All packages in <?php echo Formatter::escape($repo); ?> repository are available on both architectures!</div>
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
        
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #333;">
            <a href="<?php echo Formatter::url('report-repo-comparison.php'); ?>" style="color: #64b5f6;">← Back to Repository Comparison</a>
        </div>
    </div>
</div>

<?php Layout::footer();
