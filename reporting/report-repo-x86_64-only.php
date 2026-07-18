<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

$repoName = $_GET['repo'] ?? 'core';
if (!in_array($repoName, ['core', 'extra', 'forge'])) {
    $repoName = 'core';
}
$view_mode = $_GET['view'] ?? 'packages';

try {
    $db = Database::getInstance();
    $repo_obj = new PackageRepository($db);
    $packages       = $repo_obj->getRepoX86_64Only($repoName);
    $pkgbase_grouped = $repo_obj->getRepoX86_64OnlyGroupedByPkgbase($repoName);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header(ucfirst($repoName) . ' repository — x86_64 only');
?>

<div class="container">
    <div class="card">

        <div class="report-header">
            <div>
                <h2>⚠️ <?php echo Formatter::escape(ucfirst($repoName)); ?> repository — x86_64 only (<?php echo $view_mode === 'packages' ? count($packages) : count($pkgbase_grouped); ?>)</h2>
                <p class="card-subtitle">Packages in the <?php echo Formatter::escape($repoName); ?> repository on x86_64 but not on aarch64</p>
                <a href="<?php echo Formatter::url('report-repo-comparison.php'); ?>" class="back-link">← Back to Repository Comparison</a>
            </div>
            <div class="report-view-toggle">
                <button class="view-btn <?php echo $view_mode === 'packages' ? 'view-btn--active' : ''; ?>" onclick="setView('packages')">📦 Packages</button>
                <button class="view-btn <?php echo $view_mode === 'pkgbase'  ? 'view-btn--active' : ''; ?>" onclick="setView('pkgbase')">📚 Package bases</button>
            </div>
        </div>

        <?php if (empty($packages)): ?>
            <div class="alert alert-success">✓ All packages in the <?php echo Formatter::escape($repoName); ?> repository are available on both architectures!</div>
        <?php else: ?>

            <div class="filters">
                <input type="text" id="search-input" class="form-input"
                    placeholder="🔍 Filter <?php echo $view_mode === 'packages' ? 'packages' : 'package bases'; ?>…">
            </div>

            <?php if ($view_mode === 'packages'): ?>
                <table id="pkg-table">
                    <thead>
                        <tr>
                            <th>Package name</th>
                            <th>Version</th>
                            <th>Repository</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $pkg): ?>
                        <tr class="pkg-row" data-search="<?php echo Formatter::escape($pkg['name']); ?>">
                            <td><a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>"><?php echo Formatter::escape($pkg['name']); ?></a></td>
                            <td><?php echo Formatter::escape($pkg['version']); ?></td>
                            <td><?php echo Formatter::escape($pkg['repo']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="pkgbase-list">
                    <?php foreach ($pkgbase_grouped as $base => $info): ?>
                    <div class="pkgbase-item pkg-row" data-search="<?php echo Formatter::escape($base); ?>">
                        <div class="pkgbase-item__header">
                            <div class="pkgbase-item__name">
                                <?php echo Formatter::escape($base); ?>
                                <?php if ($info['has_primary']): ?>
                                    <span class="badge badge-success">Has aarch64 versions</span>
                                <?php endif; ?>
                            </div>
                            <span class="pkgbase-item__count"><?php echo count($info['packages']); ?> package<?php echo count($info['packages']) === 1 ? '' : 's'; ?></span>
                        </div>
                        <table class="pkgbase-item__table">
                            <tbody>
                                <?php foreach ($info['packages'] as $pkg): ?>
                                <tr>
                                    <td><a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>"><?php echo Formatter::escape($pkg['name']); ?></a></td>
                                    <td class="text-muted text-small"><?php echo Formatter::escape($pkg['version']); ?></td>
                                    <td class="text-muted text-small"><?php echo Formatter::escape($pkg['repo']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<script>
function setView(mode) {
    const url = new URL(window.location);
    url.searchParams.set('view', mode);
    window.location = url.toString();
}
document.getElementById('search-input')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.pkg-row').forEach(row => {
        row.style.display = row.dataset.search.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php Layout::footer();
