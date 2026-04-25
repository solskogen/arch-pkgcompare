<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getSizeDifferences();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Filter out packages where both are under 10MB
$MIN_SIZE = 10 * 1024 * 1024; // 10MB
$packages = array_filter($packages, function($pkg) use ($MIN_SIZE) {
    return $pkg['aarch64_isize'] >= $MIN_SIZE || $pkg['x86_64_isize'] >= $MIN_SIZE;
});

// Handle sorting
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'asc';

// Validate sort parameter
$valid_sorts = ['name', 'size', 'difference', 'direction'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'name';
}

if (!in_array($order, ['asc', 'desc'])) {
    $order = 'asc';
}

// Sort packages
usort($packages, function($a, $b) use ($sort, $order) {
    $cmp = 0;
    
    switch ($sort) {
        case 'name':
            $cmp = strcmp($a['name'], $b['name']);
            break;
        case 'size':
            // Sort by aarch64 installed size
            $cmp = $a['aarch64_isize'] <=> $b['aarch64_isize'];
            break;
        case 'difference':
            // Sort by percentage difference
            $pct_a = $a['aarch64_isize'] > 0 ? abs($a['isize_diff']) / $a['aarch64_isize'] * 100 : 0;
            $pct_b = $b['aarch64_isize'] > 0 ? abs($b['isize_diff']) / $b['aarch64_isize'] * 100 : 0;
            $cmp = $pct_a <=> $pct_b;
            break;
        case 'direction':
            // Sort by difference (positive = x86_64 larger, negative = aarch64 larger)
            $cmp = $a['isize_diff'] <=> $b['isize_diff'];
            break;
    }
    
    return $order === 'desc' ? -$cmp : $cmp;
});

// Helper function to build sort URL
function getSortUrl($column, $current_sort, $current_order) {
    $new_order = ($current_sort === $column && $current_order === 'asc') ? 'desc' : 'asc';
    return '?sort=' . $column . '&order=' . $new_order;
}

// Helper function to get sort indicator
function getSortIndicator($column, $current_sort, $current_order) {
    if ($current_sort !== $column) return '';
    return $current_order === 'asc' ? ' ↑' : ' ↓';
}

// Helper function to determine color status
function getColorStatus($pkg) {
    $diff = $pkg['isize_diff'];
    $diff_abs = abs($diff);
    $diff_pct = $pkg['aarch64_isize'] > 0 ? abs($diff) / $pkg['aarch64_isize'] * 100 : 0;
    
    if ($diff_pct < 5 && $diff_abs < 5 * 1024 * 1024) {
        return 'green';
    } elseif ($diff < 0 && ($diff_pct > 10 || $diff_abs > 10 * 1024 * 1024)) {
        return 'red';
    } elseif ($diff > 0 && ($diff_pct > 10 || $diff_abs > 10 * 1024 * 1024)) {
        return 'yellow';
    } else {
        return 'grey';
    }
}

Layout::header('Size Differences');
?>

<div class="container">
    <div class="card">
        <h2>📊 Package Size Differences (aarch64 vs x86_64)</h2>
        <p style="margin-bottom: 20px; opacity: 0.8;">Showing <?php echo count($packages); ?> packages that exist on both architectures</p>

        <div style="margin-bottom: 20px;">
            <input 
                type="text" 
                id="search-input" 
                placeholder="🔍 Search packages by name..." 
                style="width: 100%; padding: 10px; font-size: 16px; border: 1px solid #333; border-radius: 4px; background: #1a1a1a; color: #fff; margin-bottom: 15px;"
            >
        </div>

        <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="filter-btn active" data-filter="all" style="padding: 8px 16px; border: 1px solid #666; border-radius: 4px; background: #333; color: #fff; cursor: pointer;">All</button>
            <button class="filter-btn" data-filter="green" style="padding: 8px 16px; border: 1px solid #51cf66; border-radius: 4px; background: transparent; color: #51cf66; cursor: pointer;">🟢 Green</button>
            <button class="filter-btn" data-filter="red" style="padding: 8px 16px; border: 1px solid #ff6b6b; border-radius: 4px; background: transparent; color: #ff6b6b; cursor: pointer;">🔴 Red</button>
            <button class="filter-btn" data-filter="yellow" style="padding: 8px 16px; border: 1px solid #ffd93d; border-radius: 4px; background: transparent; color: #ffd93d; cursor: pointer;">🟡 Yellow</button>
            <button class="filter-btn" data-filter="grey" style="padding: 8px 16px; border: 1px solid #999; border-radius: 4px; background: transparent; color: #999; cursor: pointer;">⚪ Grey</button>
        </div>

        <?php if (empty($packages)): ?>
            <div class="alert alert-info">No packages found.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table id="size-table">
                    <thead>
                        <tr>
                            <th style="cursor: pointer;">
                                <a href="<?php echo getSortUrl('name', $sort, $order); ?>" style="color: inherit; text-decoration: none;">
                                    Package Name<?php echo getSortIndicator('name', $sort, $order); ?>
                                </a>
                            </th>
                            <th style="cursor: pointer;">
                                <a href="<?php echo getSortUrl('size', $sort, $order); ?>" style="color: inherit; text-decoration: none;">
                                    aarch64 Installed<?php echo getSortIndicator('size', $sort, $order); ?>
                                </a>
                            </th>
                            <th>x86_64 Installed</th>
                            <th style="cursor: pointer;">
                                <a href="<?php echo getSortUrl('difference', $sort, $order); ?>" style="color: inherit; text-decoration: none;">
                                    Difference<?php echo getSortIndicator('difference', $sort, $order); ?>
                                </a>
                            </th>
                            <th style="cursor: pointer; text-align: center;">
                                <a href="<?php echo getSortUrl('direction', $sort, $order); ?>" style="color: inherit; text-decoration: none;">
                                    Direction<?php echo getSortIndicator('direction', $sort, $order); ?>
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $pkg): 
                            $diff = $pkg['isize_diff'];
                            $diff_abs = abs($diff);
                            $diff_pct = $pkg['aarch64_isize'] > 0 ? abs($diff) / $pkg['aarch64_isize'] * 100 : 0;
                            $direction = $diff > 0 ? '↑ x86_64 larger' : ($diff < 0 ? '↑ aarch64 larger' : '=');
                            
                            // Determine color status and styling
                            $color_status = getColorStatus($pkg);
                            if ($color_status === 'green') {
                                $color = '#51cf66';
                            } elseif ($color_status === 'red') {
                                $color = '#ff6b6b';
                            } elseif ($color_status === 'yellow') {
                                $color = '#ffd93d';
                            } else {
                                $color = '#999';
                            }
                        ?>
                        <tr class="size-row" data-color="<?php echo $color_status; ?>">
                            <td>
                                <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                    <?php echo Formatter::escape($pkg['name']); ?>
                                </a>
                            </td>
                            <td><?php echo Formatter::size($pkg['aarch64_isize']); ?></td>
                            <td><?php echo Formatter::size($pkg['x86_64_isize']); ?></td>
                            <td><?php echo Formatter::size($diff_abs); ?> (<?php echo number_format($diff_pct, 1); ?>%)</td>
                            <td style="text-align: center; color: <?php echo $color; ?>;"><?php echo $direction; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
let currentFilter = 'all';

// Handle filter buttons
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        currentFilter = this.getAttribute('data-filter');
        
        // Update active button
        document.querySelectorAll('.filter-btn').forEach(b => {
            b.style.background = b === this ? '#333' : 'transparent';
            b.style.borderColor = b === this ? '#666' : b.getAttribute('data-filter') === 'all' ? '#666' : 
                                 (b.getAttribute('data-filter') === 'green' ? '#51cf66' : 
                                  b.getAttribute('data-filter') === 'red' ? '#ff6b6b' : 
                                  b.getAttribute('data-filter') === 'yellow' ? '#ffd93d' : '#999');
        });
        
        applyFilters();
    });
});

// Handle search
document.getElementById('search-input').addEventListener('keyup', applyFilters);

// Apply both search and color filters
function applyFilters() {
    const searchTerm = document.getElementById('search-input').value.toLowerCase();
    const rows = document.querySelectorAll('#size-table tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        const packageName = row.querySelector('a').textContent.toLowerCase();
        const colorStatus = row.getAttribute('data-color');
        
        const matchesSearch = packageName.includes(searchTerm);
        const matchesFilter = currentFilter === 'all' || colorStatus === currentFilter;
        const isVisible = matchesSearch && matchesFilter;
        
        row.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
    });

    // Update result count
    const headerText = document.querySelector('.card h2');
    const total = <?php echo count($packages); ?>;
    const filterLabel = currentFilter === 'all' ? '' : ` (${currentFilter})`;
    if (document.getElementById('search-input').value) {
        headerText.textContent = `📊 Package Size Differences - ${visibleCount} of ${total} packages${filterLabel}`;
    } else {
        headerText.textContent = `📊 Package Size Differences${filterLabel}`;
    }
}
</script>

<?php Layout::footer();
