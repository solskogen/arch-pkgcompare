<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getX86_64Only();
    $pkgbase_grouped = $repo->getX86_64OnlyGroupedByPkgbase();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$view_mode = $_GET['view'] ?? 'packages';

Layout::header('x86_64 Only Packages');
?>

<div class="container">
    <div class="card">

        <div class="report-header">
            <div>
                <h2>📦 x86_64 Only Packages (<?php echo $view_mode === 'packages' ? count($packages) : count($pkgbase_grouped); ?>)</h2>
                <p class="card-subtitle">Packages available in x86_64 but not in aarch64</p>
                <a href="<?php echo Formatter::url('analysis.php'); ?>" class="back-link">← Back to Analysis</a>
            </div>
            <div class="report-view-toggle">
                <button class="view-btn <?php echo $view_mode === 'packages' ? 'view-btn--active' : ''; ?>" onclick="setView('packages')">📦 Packages</button>
                <button class="view-btn <?php echo $view_mode === 'pkgbase' ? 'view-btn--active' : ''; ?>" onclick="setView('pkgbase')">📚 Package bases</button>
            </div>
        </div>

        <div class="filters">
            <input type="text" id="search-input" class="form-input"
                placeholder="🔍 Filter <?php echo $view_mode === 'packages' ? 'packages' : 'package bases'; ?>…">
        </div>

        <?php if ($view_mode === 'packages'): ?>
            <?php if (empty($packages)): ?>
                <div class="alert alert-info">No packages found.</div>
            <?php else: ?>
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
            <?php endif; ?>

        <?php else: ?>
            <?php if (empty($pkgbase_grouped)): ?>
                <div class="alert alert-info">No package bases found.</div>
            <?php else: ?>
                <div id="pkgbase-list" class="pkgbase-list">
                    <?php foreach ($pkgbase_grouped as $base => $info): ?>
                    <div class="pkgbase-item pkg-row" data-search="<?php echo Formatter::escape($base); ?>">
                        <div class="pkgbase-item__header">
                            <div class="pkgbase-item__name">
                                <?php echo Formatter::escape($base); ?>
                                <?php if ($info['has_aarch64']): ?>
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

document.getElementById('search-input').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.pkg-row').forEach(row => {
        row.style.display = row.dataset.search.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php Layout::footer();
