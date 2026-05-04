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
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h2>📦 x86_64 Only Packages</h2>
                <p style="margin: 0; opacity: 0.8;">Packages available in x86_64 but not in aarch64</p>
            </div>
            <div>
                <button 
                    class="btn <?php echo $view_mode === 'packages' ? 'btn-active' : ''; ?>"
                    onclick="setView('packages')" 
                    style="margin-right: 5px;">
                    📦 Packages
                </button>
                <button 
                    class="btn <?php echo $view_mode === 'pkgbase' ? 'btn-active' : ''; ?>"
                    onclick="setView('pkgbase')">
                    📚 Package Bases
                </button>
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <input 
                type="text" 
                id="search-input" 
                placeholder="🔍 Search <?php echo $view_mode === 'packages' ? 'packages' : 'package bases'; ?> by name..." 
                style="width: 100%; padding: 10px; font-size: 16px; border: 1px solid #333; border-radius: 4px; background: #1a1a1a; color: #fff;"
            >
        </div>

        <?php if ($view_mode === 'packages'): ?>
            <!-- PACKAGES VIEW -->
            <?php if (empty($packages)): ?>
                <div class="alert alert-info">No packages found.</div>
            <?php else: ?>
                <table id="pkg-table">
                    <thead>
                        <tr>
                            <th>Package Name</th>
                            <th>Version</th>
                            <th>Repository</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $pkg): ?>
                        <tr class="pkg-row" data-search="<?php echo Formatter::escape($pkg['name']); ?>">
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
        
        <?php else: ?>
            <!-- PKGBASE VIEW -->
            <?php if (empty($pkgbase_grouped)): ?>
                <div class="alert alert-info">No package bases found.</div>
            <?php else: ?>
                <div id="pkgbase-list" style="display: flex; flex-direction: column; gap: 15px;">
                    <?php foreach ($pkgbase_grouped as $base => $info): ?>
                    <div class="pkgbase-card pkg-row" data-search="<?php echo Formatter::escape($base); ?>" style="border: 1px solid #333; border-radius: 4px; padding: 15px; background: #111;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <div>
                                <strong style="font-size: 16px;"><?php echo Formatter::escape($base); ?></strong>
                                <?php if ($info['has_aarch64']): ?>
                                    <span class="badge" style="background: #4CAF50; margin-left: 10px; font-size: 12px;">Has aarch64 versions</span>
                                <?php endif; ?>
                            </div>
                            <span style="color: #888; font-size: 14px;"><?php echo count($info['packages']); ?> package<?php echo count($info['packages']) === 1 ? '' : 's'; ?></span>
                        </div>
                        <table style="width: 100%; font-size: 14px;">
                            <thead style="display: none;">
                                <tr>
                                    <th>Name</th>
                                    <th>Version</th>
                                    <th>Repo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($info['packages'] as $pkg): ?>
                                <tr style="border-top: 1px solid #222;">
                                    <td style="padding: 8px 0; padding-right: 10px;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #4FC3F7;">
                                            <?php echo Formatter::escape($pkg['name']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 8px 0; padding-right: 10px; width: 25%; color: #888;"><?php echo Formatter::escape($pkg['version']); ?></td>
                                    <td style="padding: 8px 0; width: 20%; color: #888;"><?php echo Formatter::escape($pkg['repo']); ?></td>
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

document.getElementById('search-input').addEventListener('keyup', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.pkg-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const searchText = row.getAttribute('data-search').toLowerCase();
        const matches = searchText.includes(searchTerm);
        row.style.display = matches ? '' : 'none';
        if (matches) visibleCount++;
    });

    // Update result count (for packages view)
    const table = document.getElementById('pkg-table');
    if (table) {
        const headerText = document.querySelector('.card h2');
        const total = <?php echo count($packages); ?>;
        if (searchTerm) {
            headerText.textContent = `📦 x86_64 Only Packages - ${visibleCount} of ${total} packages`;
        } else {
            headerText.textContent = `📦 x86_64 Only Packages`;
        }
    }
});
</script>

<?php Layout::footer();
