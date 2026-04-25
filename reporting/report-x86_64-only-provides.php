<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $grouped = $repo->getX86_64OnlyGroupedByPkgbase();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('x86_64 Only (Excluding Provides)');
?>

<div class="container">
    <div class="card">
        <?php
        $totalPackages = 0;
        foreach ($grouped as $base => $info) {
            $totalPackages += count($info['packages']);
        }
        ?>
        <h2>⚠️ x86_64 Only - Not Provided by aarch64 (<?php echo $totalPackages; ?>)</h2>
        <p style="margin-bottom: 20px; opacity: 0.8;">x86_64 packages not in aarch64, grouped by pkgbase (PKGBUILD source)</p>

        <div style="margin-bottom: 20px;">
            <input 
                type="text" 
                id="search-input" 
                placeholder="🔍 Search by pkgbase or package name..." 
                style="width: 100%; padding: 10px; font-size: 16px; border: 1px solid #333; border-radius: 4px; background: #1a1a1a; color: #fff;"
            >
        </div>

        <?php if (empty($grouped)): ?>
            <div class="alert alert-info">No packages found.</div>
        <?php else: ?>
            <?php foreach ($grouped as $base => $info): ?>
                <div class="pkgbase-group" data-base="<?php echo Formatter::escape($base); ?>" style="margin-bottom: 20px; border: 1px solid #333; border-radius: 8px; padding: 15px; background: #0a0a0a;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div>
                            <h3 style="color: #90caf9; margin: 0; display: inline-block; margin-right: 10px;"><?php echo Formatter::escape($base); ?></h3>
                            <?php if ($info['has_aarch64']): ?>
                                <span style="background: #c8a548; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 0.85em;">
                                    Missing split package
                                </span>
                            <?php else: ?>
                                <span style="background: #c44545; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 0.85em;">
                                    missing
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <table style="width: 100%; font-size: 13px;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #444;">Package Name</th>
                                <th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #444;">Version</th>
                                <th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #444;">Repository</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($info['packages'] as $pkg): ?>
                            <tr class="pkg-row" data-base="<?php echo Formatter::escape($base); ?>" data-name="<?php echo Formatter::escape($pkg['name']); ?>">
                                <td style="padding: 8px 0;">
                                    <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                        <?php echo Formatter::escape($pkg['name']); ?>
                                    </a>
                                </td>
                                <td style="padding: 8px 0;"><?php echo Formatter::escape($pkg['version']); ?></td>
                                <td style="padding: 8px 0;"><?php echo Formatter::escape($pkg['repo']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('search-input').addEventListener('keyup', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const groups = document.querySelectorAll('.pkgbase-group');
    let visibleCount = 0;
    let visiblePackages = 0;

    groups.forEach(group => {
        const baseName = group.getAttribute('data-base').toLowerCase();
        const rows = group.querySelectorAll('tbody tr.pkg-row');
        let anyRowVisible = false;
        
        rows.forEach(row => {
            const pkgName = row.getAttribute('data-name').toLowerCase();
            const matches = baseName.includes(searchTerm) || pkgName.includes(searchTerm);
            row.style.display = matches ? '' : 'none';
            if (matches) {
                anyRowVisible = true;
                visiblePackages++;
            }
        });
        
        group.style.display = anyRowVisible || (searchTerm === '') ? '' : 'none';
        if (anyRowVisible || (searchTerm === '')) visibleCount++;
    });

    // Update result count
    const headerText = document.querySelector('.card h2');
    if (searchTerm) {
        headerText.textContent = `⚠️ x86_64 Only - Not Provided by aarch64 (${visiblePackages} packages in ${visibleCount} pkgbase(s))`;
    } else {
        const total = <?php echo $totalPackages; ?>;
        headerText.textContent = `⚠️ x86_64 Only - Not Provided by aarch64 (${total})`;
    }
});
</script>

<?php Layout::footer();
