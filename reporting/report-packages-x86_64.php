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
        <p style="margin-bottom: 15px; opacity: 0.8;">All packages available for x86_64 architecture. Click column headers to sort.</p>

        <?php if (empty($packages)): ?>
            <div class="alert alert-info">No packages found.</div>
        <?php else: ?>
            <div class="filters">
                <input type="text" id="search" placeholder="Filter packages..." style="width:100%;box-sizing:border-box;">
            </div>
            <table id="pkg-table">
                <thead>
                    <tr>
                        <th class="sortable" data-col="0" data-type="str">Package Name</th>
                        <th class="sortable" data-col="1" data-type="str">Version</th>
                        <th class="sortable" data-col="2" data-type="str">Repository</th>
                        <th class="sortable" data-col="3" data-type="num">Download Size</th>
                        <th class="sortable" data-col="4" data-type="num">Installed Size</th>
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
                        <td data-val="<?php echo (int)$pkg['csize']; ?>"><?php echo Formatter::size($pkg['csize']); ?></td>
                        <td data-val="<?php echo (int)$pkg['isize']; ?>"><?php echo Formatter::size($pkg['isize']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const table = document.getElementById('pkg-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    let lastCol = -1, asc = true;

    table.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const col = +th.dataset.col;
            const isNum = th.dataset.type === 'num';
            asc = (lastCol === col) ? !asc : true;
            lastCol = col;

            table.querySelectorAll('th.sortable').forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
            th.classList.add(asc ? 'sort-asc' : 'sort-desc');

            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const av = isNum
                    ? +(a.cells[col].dataset.val || 0)
                    : a.cells[col].textContent.trim().toLowerCase();
                const bv = isNum
                    ? +(b.cells[col].dataset.val || 0)
                    : b.cells[col].textContent.trim().toLowerCase();
                return (av < bv ? -1 : av > bv ? 1 : 0) * (asc ? 1 : -1);
            });
            rows.forEach(r => tbody.appendChild(r));
        });
    });

    // Filter
    document.getElementById('search').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        tbody.querySelectorAll('tr').forEach(r => {
            r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
})();
</script>

<?php Layout::footer();
