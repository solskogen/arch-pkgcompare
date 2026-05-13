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
                <input type="text" id="search" class="form-input" placeholder="Filter packages...">
                <label style="display:inline-flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer;font-size:14px;">
                    <input type="checkbox" id="show-any" checked>
                    Show <code>any</code> packages (architecture-independent)
                </label>
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
                    <tr<?php echo $pkg['arch'] === 'any' ? ' data-any="1"' : ''; ?>>
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

            // Highlight sorted column cells
            tbody.querySelectorAll('td.sort-col').forEach(td => td.classList.remove('sort-col'));
            tbody.querySelectorAll(`tr td:nth-child(${col + 1})`).forEach(td => td.classList.add('sort-col'));

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
    function applyFilter() {
        const q = document.getElementById('search').value.toLowerCase();
        const showAny = document.getElementById('show-any').checked;
        tbody.querySelectorAll('tr').forEach(r => {
            const textMatch = r.textContent.toLowerCase().includes(q);
            const anyHidden = !showAny && r.dataset.any === '1';
            r.style.display = (textMatch && !anyHidden) ? '' : 'none';
        });
    }
    document.getElementById('search').addEventListener('input', applyFilter);
    document.getElementById('show-any').addEventListener('change', applyFilter);
})();
</script>

<?php Layout::footer();
