<?php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Helpers.php';

$conn = getDbConnection();

// Architecture-level stats
$result = $conn->query('
    SELECT p.system_arch, COUNT(*) as total,
           SUM(CASE WHEN a.name = "any" THEN 1 ELSE 0 END) as arch_any,
           SUM(CASE WHEN a.name = "x86_64" THEN 1 ELSE 0 END) as arch_x86_64,
           SUM(CASE WHEN a.name = "aarch64" THEN 1 ELSE 0 END) as arch_aarch64,
           ROUND(AVG(p.isize) / 1024 / 1024, 2) as avg_size_mb,
           ROUND(SUM(p.isize) / 1024 / 1024 / 1024, 2) as total_size_gb
    FROM packages p
    JOIN architectures a ON p.arch_id = a.id
    GROUP BY p.system_arch
');
$arch_stats = [];
while ($row = $result->fetch_assoc()) {
    $arch_stats[$row['system_arch']] = $row;
}

// Repository comparison
$result = $conn->query('
    SELECT p.system_arch, r.name as repo, COUNT(*) as count
    FROM packages p
    JOIN repositories r ON p.repo_id = r.id
    GROUP BY p.system_arch, r.id, r.name
    ORDER BY p.system_arch, r.name
');
$repo_comparison = [];
while ($row = $result->fetch_assoc()) {
    $key = $row['system_arch'] . '/' . $row['repo'];
    $repo_comparison[$key] = $row['count'];
}

// Shared packages (any arch only)
$result = $conn->query('
    SELECT COUNT(*) as shared FROM (
        SELECT DISTINCT p1.name FROM packages p1
        JOIN architectures a1 ON p1.arch_id = a1.id
        WHERE a1.name = "any" AND p1.system_arch = "aarch64"
        AND p1.name IN (
            SELECT DISTINCT p2.name FROM packages p2
            JOIN architectures a2 ON p2.arch_id = a2.id
            WHERE a2.name = "any" AND p2.system_arch = "x86_64"
        )
    ) t
');
$shared_any = $result->fetch_assoc()['shared'];

// Unique to each arch
$result = $conn->query('
    SELECT COUNT(*) as unique_aarch64 FROM (
        SELECT DISTINCT p1.name FROM packages p1
        WHERE p1.system_arch = "aarch64"
        AND p1.name NOT IN (
            SELECT DISTINCT name FROM packages WHERE system_arch = "x86_64"
        )
    ) t
');
$unique_aarch64 = $result->fetch_assoc()['unique_aarch64'];

$result = $conn->query('
    SELECT COUNT(*) as unique_x86_64 FROM (
        SELECT DISTINCT p1.name FROM packages p1
        WHERE p1.system_arch = "x86_64"
        AND p1.name NOT IN (
            SELECT DISTINCT name FROM packages WHERE system_arch = "aarch64"
        )
    ) t
');
$unique_x86_64 = $result->fetch_assoc()['unique_x86_64'];

// Top differences by size
$result = $conn->query('
    SELECT 
        a.name as pkg_name,
        ROUND(a.asize / 1024 / 1024, 2) as aarch64_size_mb,
        ROUND(x.xsize / 1024 / 1024, 2) as x86_64_size_mb,
        ROUND(x.xsize - a.asize, 0) as diff_bytes,
        ROUND((x.xsize - a.asize) / a.asize * 100, 1) as pct_diff
    FROM (
        SELECT name, SUM(isize) as asize
        FROM packages WHERE system_arch = "aarch64"
        GROUP BY name
    ) a
    INNER JOIN (
        SELECT name, SUM(isize) as xsize
        FROM packages WHERE system_arch = "x86_64"
        GROUP BY name
    ) x ON a.name = x.name
    WHERE x.xsize != a.asize
    ORDER BY ABS(x.xsize - a.asize) DESC
    LIMIT 20
');
$size_diffs = $result->fetch_all(MYSQLI_ASSOC);

$conn->close();

Layout::header('Architecture Comparison');
?>

<style>
    .comparison-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
    .comparison-card { background: rgba(255,255,255,0.05); border: 1px solid rgba(100,181,246,0.2); border-radius: 4px; padding: 20px; }
    .stat-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid rgba(100,181,246,0.1); }
    .stat-label { color: #999; }
    .stat-value { color: #64b5f6; font-weight: bold; }
    .diff-positive { color: #4caf50; }
    .diff-negative { color: #ff6b6b; }
</style>

<div class="container">

        <!-- OVERVIEW STATS -->
        <section>
            <h2>📊 Overview</h2>
            <div class="comparison-grid">
                <div class="comparison-card">
                    <h3>aarch64</h3>
                    <div class="stat-row">
                        <span class="stat-label">Total Packages:</span>
                        <span class="stat-value"><?php echo fmt($arch_stats['aarch64']['total'] ?? 0); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Any Architecture:</span>
                        <span class="stat-value"><?php echo fmt($arch_stats['aarch64']['arch_any'] ?? 0); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">aarch64 Specific:</span>
                        <span class="stat-value"><?php echo fmt($arch_stats['aarch64']['arch_aarch64'] ?? 0); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Total Size:</span>
                        <span class="stat-value"><?php echo $arch_stats['aarch64']['total_size_gb'] ?? '0'; ?> GB</span>
                    </div>
                </div>

                <div class="comparison-card">
                    <h3>x86_64</h3>
                    <div class="stat-row">
                        <span class="stat-label">Total Packages:</span>
                        <span class="stat-value"><?php echo fmt($arch_stats['x86_64']['total'] ?? 0); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Any Architecture:</span>
                        <span class="stat-value"><?php echo fmt($arch_stats['x86_64']['arch_any'] ?? 0); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">x86_64 Specific:</span>
                        <span class="stat-value"><?php echo fmt($arch_stats['x86_64']['arch_x86_64'] ?? 0); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Total Size:</span>
                        <span class="stat-value"><?php echo $arch_stats['x86_64']['total_size_gb'] ?? '0'; ?> GB</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- VENN DIAGRAM DATA -->
        <section>
            <h2>📐 Package Overlap</h2>
            <div class="comparison-grid">
                <div class="comparison-card">
                    <h3>Shared 'any' Packages</h3>
                    <div style="font-size: 2em; color: #64b5f6; text-align: center; margin: 20px 0;">
                        <?php echo fmt($shared_any); ?>
                    </div>
                    <p style="text-align: center; color: #999;">Packages built once, available on both architectures</p>
                </div>

                <div class="comparison-card">
                    <h3>Architecture-Specific</h3>
                    <div style="padding: 20px 0;">
                        <div style="margin: 15px 0;">
                            <div style="color: #999; margin-bottom: 5px;">Only on aarch64:</div>
                            <div style="font-size: 1.5em; color: #4caf50;"><?php echo fmt($unique_aarch64); ?></div>
                        </div>
                        <div style="margin: 15px 0;">
                            <div style="color: #999; margin-bottom: 5px;">Only on x86_64:</div>
                            <div style="font-size: 1.5em; color: #ff6b6b;"><?php echo fmt($unique_x86_64); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- REPOSITORY BREAKDOWN -->
        <section>
            <h2>📦 Repository Distribution</h2>
            <div class="comparison-grid">
                <div class="comparison-card">
                    <h3>aarch64 Repositories</h3>
                    <div class="stat-row">
                        <span class="stat-label">core:</span>
                        <span class="stat-value"><?php echo $repo_comparison['aarch64/core'] ?? 0; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">extra:</span>
                        <span class="stat-value"><?php echo $repo_comparison['aarch64/extra'] ?? 0; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">forge:</span>
                        <span class="stat-value"><?php echo $repo_comparison['aarch64/forge'] ?? 0; ?></span>
                    </div>
                </div>

                <div class="comparison-card">
                    <h3>x86_64 Repositories</h3>
                    <div class="stat-row">
                        <span class="stat-label">core:</span>
                        <span class="stat-value"><?php echo $repo_comparison['x86_64/core'] ?? 0; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">extra:</span>
                        <span class="stat-value"><?php echo $repo_comparison['x86_64/extra'] ?? 0; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">forge:</span>
                        <span class="stat-value"><?php echo $repo_comparison['x86_64/forge'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- SIZE DIFFERENCES -->
        <section>
            <h2>📏 Top Size Differences</h2>
            <p style="color: var(--text-secondary); margin-bottom: 15px;">Packages with significant size variations between architectures</p>
            <div class="packages-table">
                <table>
                    <thead>
                        <tr>
                            <th>Package</th>
                            <th data-numeric>aarch64 Size</th>
                            <th data-numeric>x86_64 Size</th>
                            <th data-numeric>Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($size_diffs as $diff): ?>
                        <tr>
                            <td>
                                <a href="<?php echo baseUrl(); ?>package-detail.php?name=<?php echo urlencode($diff['pkg_name']); ?>" style="color: #64b5f6; text-decoration: none;">
                                    <?php echo esc($diff['pkg_name']); ?>
                                </a>
                            </td>
                            <td class="pkg-deps" data-numeric>
                                <?php echo $diff['aarch64_size_mb'] ?? '-'; ?> MB
                            </td>
                            <td class="pkg-deps" data-numeric>
                                <?php echo $diff['x86_64_size_mb'] ?? '-'; ?> MB
                            </td>
                            <td class="pkg-deps" data-numeric>
                                <span class="<?php echo (($diff['diff_bytes'] ?? 0) > 0) ? 'diff-positive' : 'diff-negative'; ?>">
                                    <?php 
                                    if ($diff['diff_bytes'] && $diff['diff_bytes'] != 0) {
                                        echo ($diff['diff_bytes'] > 0 ? '+' : '') . fmtSize($diff['diff_bytes']) . 
                                             ' (' . ($diff['pct_diff'] ?? 0) . '%)';
                                    } else {
                                        echo 'same';
                                    }
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
</div>

<?php Layout::footer();
?>
