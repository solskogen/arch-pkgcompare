<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getX86_64Only();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('x86_64 Only Packages');
?>

<div class="container">
    <div class="card">
        <h2>📦 x86_64 Only Packages (<?php echo count($packages); ?>)</h2>
        <p style="margin-bottom: 20px; opacity: 0.8;">Packages available in x86_64 but not in aarch64</p>

        <div style="margin-bottom: 20px;">
            <input 
                type="text" 
                id="search-input" 
                placeholder="🔍 Search packages by name..." 
                style="width: 100%; padding: 10px; font-size: 16px; border: 1px solid #333; border-radius: 4px; background: #1a1a1a; color: #fff;"
            >
        </div>

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
                    <tr class="pkg-row">
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

<script>
document.getElementById('search-input').addEventListener('keyup', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#pkg-table tbody tr.pkg-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const packageName = row.querySelector('a').textContent.toLowerCase();
        const matches = packageName.includes(searchTerm);
        row.style.display = matches ? '' : 'none';
        if (matches) visibleCount++;
    });

    // Update result count
    const headerText = document.querySelector('.card h2');
    const total = <?php echo count($packages); ?>;
    if (searchTerm) {
        headerText.textContent = `📦 x86_64 Only Packages - ${visibleCount} of ${total} packages`;
    } else {
        headerText.textContent = `📦 x86_64 Only Packages (${total})`;
    }
});
</script>

<?php Layout::footer();
