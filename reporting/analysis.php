<?php
/**
 * Analysis Dashboard - Report Menu
 * Lists available analysis reports with counts
 */
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Cache.php';
require_once __DIR__ . '/app/Helpers.php';

function getAnalysisCacheVersion($db) {
    $result = $db->query("SELECT id, import_timestamp FROM import_metadata ORDER BY id DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return 'analysis-v2:' . $row['id'] . ':' . $row['import_timestamp'];
    }
    return 'analysis-v2:bootstrap';
}

$cache = new Cache(3600); // 1 hour cache

// Allow bypassing cache with ?nocache=1 parameter
$bypassCache = isset($_GET['nocache']) && $_GET['nocache'] == '1';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $cacheVersion = getAnalysisCacheVersion($db);
    $statsCacheKey = 'analysis_stats:' . $cacheVersion;
    $countsCacheKeyA = 'analysis_counts_a:' . $cacheVersion;
    $countsCacheKeyB = 'analysis_counts_b:' . $cacheVersion;
    $countsCacheKeyC = 'analysis_counts_c:' . $cacheVersion;

    // Try to get stats from cache (unless bypassed)
    $stats = !$bypassCache ? $cache->get($statsCacheKey) : null;
    $cached = ($stats !== null);

    if ($stats === null) {
        $stats = $repo->getStats();
        $cache->set($statsCacheKey, $stats);
    }

    // Each counts segment is cached and recomputed independently.
    $countsA = !$bypassCache ? $cache->get($countsCacheKeyA) : null;
    if ($countsA === null) {
        $countsA = [
            'repo_diff' => $repo->countRepoDifferences(),
            'dep_diff'  => $repo->countDependencyDifferences(),
        ];
        $cache->set($countsCacheKeyA, $countsA);
    }

    $countsB = !$bypassCache ? $cache->get($countsCacheKeyB) : null;
    if ($countsB === null) {
        $countsB = [
            'provides_diff' => $repo->countProvidesDifferences(),
            'optdep_diff'   => $repo->countOptionalDepDifferences(),
            'makedep_diff'  => $repo->countMakedepDifferences(),
        ];
        $cache->set($countsCacheKeyB, $countsB);
    }

    $countsC = !$bypassCache ? $cache->get($countsCacheKeyC) : null;
    if ($countsC === null) {
        $countsC = [
            'group_diff'    => $repo->countGroupDifferences(),
            'conflict_diff' => $repo->countConflictDifferences(),
            'replace_diff'  => $repo->countReplaceDifferences(),
            'cycle_counts'  => $repo->countCyclesByLength(),
        ];
        $cache->set($countsCacheKeyC, $countsC);
    }

    $counts = array_merge($countsA, $countsB, $countsC);

    $repo_diff_count    = $counts['repo_diff'];
    $dep_diff_count     = $counts['dep_diff'];
    $provides_diff_count = $counts['provides_diff'];
    $optdep_diff_count  = $counts['optdep_diff'];
    $makedep_diff_count = $counts['makedep_diff'];
    $group_diff_count   = $counts['group_diff'];
    $conflict_diff_count = $counts['conflict_diff'];
    $replace_diff_count = $counts['replace_diff'];
    $cycle_counts       = $counts['cycle_counts'];
    $circular_count     = array_sum($cycle_counts);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$reports = [
    [
        'title' => 'Package Base Mismatches',
        'description' => 'Packages with different parent packages (base) between architectures',
        'url' => Formatter::url('report-mismatches.php'),
        'count' => $stats['mismatches_count'],
        'icon' => '📦',
        'color' => 'warning'
    ],
    [
        'title' => 'x86_64 Only Packages',
        'description' => 'Packages available in x86_64 but not in aarch64',
        'url' => Formatter::url('report-x86_64-only.php'),
        'count' => $stats['x86_64_only_count'],
        'icon' => '⚠️',
        'color' => 'warning'
    ],
    [
        'title' => 'x86_64 Only (Excluding Provides)',
        'description' => 'x86_64 packages not in aarch64 (excluding those provided by other packages)',
        'url' => Formatter::url('report-x86_64-only-provides.php'),
        'count' => $stats['x86_64_only_not_provided_count'] ?? 0,
        'icon' => '⚠️',
        'color' => 'error'
    ],
    [
        'title' => 'aarch64 Only Packages',
        'description' => 'Packages available in aarch64 but not in x86_64',
        'url' => Formatter::url('report-aarch64-only.php'),
        'count' => $stats['aarch64_only_count'],
        'icon' => '✨',
        'color' => 'info'
    ],
    [
        'title' => 'aarch64 Newer Versions',
        'description' => 'Packages with newer versions in aarch64 than x86_64',
        'url' => Formatter::url('report-newer-versions.php'),
        'count' => $stats['aarch64_newer_count'],
        'icon' => '⬆️',
        'color' => 'success'
    ],
    [
        'title' => 'x86_64 Newer Versions',
        'description' => 'Packages with newer versions in x86_64 than aarch64',
        'url' => Formatter::url('report-x86_64-newer.php'),
        'count' => $stats['x86_64_newer_count'],
        'icon' => '⬆️',
        'color' => 'warning'
    ],
    [
        'title' => 'Outdated -any Packages',
        'description' => 'Architecture-independent packages outdated in aarch64',
        'url' => Formatter::url('report-outdated-any.php'),
        'count' => $stats['outdated_any_count'],
        'icon' => '🔄',
        'color' => 'warning'
    ],
    [
        'title' => 'Missing -any Packages',
        'description' => '-any packages missing in aarch64',
        'url' => Formatter::url('report-missing-any.php'),
        'count' => $stats['missing_any_count'],
        'icon' => '❌',
        'color' => 'error'
    ],
    [
        'title' => '-any Package Differences',
        'description' => 'Architecture-independent packages that differ between aarch64 and x86_64',
        'url' => Formatter::url('report-any-differences.php'),
        'count' => $stats['any_diff_count'],
        'icon' => '📦',
        'color' => 'info'
    ],
    [
        'title' => 'Repository Differences',
        'description' => 'Packages in different repos between architectures',
        'url' => Formatter::url('report-repo-diffs.php'),
        'count' => $stats['repo_diff_list_count'],
        'icon' => '📚',
        'color' => 'info'
    ],
    [
        'title' => 'Per-Repository Comparison',
        'description' => 'Package availability by repository (core, extra, forge)',
        'url' => Formatter::url('report-repo-comparison.php'),
        'count' => 0,
        'icon' => '🔍',
        'color' => 'info'
    ],
    [
        'title' => 'License Discrepancies',
        'description' => 'Packages with different licenses between architectures',
        'url' => Formatter::url('report-license-discrepancies.php'),
        'count' => $stats['license_discrepancies_count'],
        'icon' => '⚖️',
        'color' => 'warning'
    ],
    [
        'title' => 'Orphaned Split Packages',
        'description' => 'Split packages without parent in aarch64',
        'url' => Formatter::url('report-orphaned.php'),
        'count' => $stats['orphaned_count'],
        'icon' => '👻',
        'color' => 'error'
    ],
    [
        'title' => 'Package Size Differences',
        'description' => 'Compare compressed package sizes between aarch64 and x86_64',
        'url' => Formatter::url('report-size-differences.php'),
        'count' => $stats['size_diff_count'],
        'icon' => '📊',
        'color' => 'info'
    ],
    [
        'title' => 'Repository Mismatches',
        'description' => 'Packages in different repositories (core vs extra) between architectures',
        'url' => Formatter::url('report-repo-mismatches.php'),
        'count' => $repo_diff_count,
        'icon' => '🏠',
        'color' => 'warning'
    ],
    [
        'title' => 'Dependency Differences',
        'description' => 'Packages with different dependencies between architectures',
        'url' => Formatter::url('report-dependency-differences.php'),
        'count' => $dep_diff_count,
        'icon' => '🔗',
        'color' => 'warning'
    ],
    [
        'title' => 'Provides/Virtual Package Differences',
        'description' => 'Packages that provide different virtual packages (e.g., gcc-ada)',
        'url' => Formatter::url('report-provides-differences.php'),
        'count' => $provides_diff_count,
        'icon' => '📦',
        'color' => 'info'
    ],
    [
        'title' => 'Optional Dependency Differences',
        'description' => 'Packages with different optional dependencies between architectures',
        'url' => Formatter::url('report-optdep-differences.php'),
        'count' => $optdep_diff_count,
        'icon' => '🎁',
        'color' => 'info'
    ],
    [
        'title' => 'Makedepend Differences',
        'description' => 'Packages with different build-time dependencies',
        'url' => Formatter::url('report-makedep-differences.php'),
        'count' => $makedep_diff_count,
        'icon' => '🔨',
        'color' => 'info'
    ],
    [
        'title' => 'Group Membership Differences',
        'description' => 'Packages assigned to different groups (base, kde, gnome, etc.)',
        'url' => Formatter::url('report-group-differences.php'),
        'count' => $group_diff_count,
        'icon' => '👥',
        'color' => 'info'
    ],
    [
        'title' => 'Package Conflict Differences',
        'description' => 'Packages with different conflict definitions',
        'url' => Formatter::url('report-conflict-differences.php'),
        'count' => $conflict_diff_count,
        'icon' => '⚔️',
        'color' => 'warning'
    ],
    [
        'title' => 'Package Replace Differences',
        'description' => 'Packages with different replace definitions (upgrade paths)',
        'url' => Formatter::url('report-replace-differences.php'),
        'count' => $replace_diff_count,
        'icon' => '🔄',
        'color' => 'info'
    ],
    [
        'title' => 'Circular Dependencies',
        'description' => 'Packages with circular/mutual dependencies (A depends on B, B depends on A)',
        'url' => Formatter::url('report-circular-dependencies.php'),
        'count' => $circular_count,
        'icon' => '🔁',
        'color' => 'error'
    ]
];

Layout::header('Package Analysis Reports');
?>

<div class="container">
    <div class="card">
        <h2>📊 Statistics Overview</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="value"><?php echo Formatter::number($stats['aarch64_packages']); ?></div>
                <div class="label">aarch64 Packages</div>
            </div>
            <div class="stat-box">
                <div class="value"><?php echo Formatter::number($stats['x86_64_packages']); ?></div>
                <div class="label">x86_64 Packages</div>
            </div>
            <div class="stat-box">
                <div class="value"><?php echo Formatter::number($stats['aarch64_size_mb']); ?></div>
                <div class="label">aarch64 Compressed Size (MB)</div>
            </div>
            <div class="stat-box">
                <div class="value"><?php echo Formatter::number($stats['x86_64_size_mb']); ?></div>
                <div class="label">x86_64 Compressed Size (MB)</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>📋 Available Reports</h2>
        <p style="margin-bottom: 20px; opacity: 0.8;">Click on any report to view detailed analysis</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 15px;">
            <?php foreach ($reports as $report): ?>
            <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a; transition: all 0.3s;">
                <div style="font-size: 20px; margin-bottom: 5px;"><?php echo $report['icon']; ?></div>
                <h3 style="margin-bottom: 5px; color: #90caf9;">
                    <a href="<?php echo $report['url']; ?>" style="color: inherit; text-decoration: none;">
                        <?php echo Formatter::escape($report['title']); ?>
                    </a>
                </h3>
                <p style="font-size: 13px; opacity: 0.7; margin-bottom: 10px;">
                    <?php echo Formatter::escape($report['description']); ?>
                </p>
                <a href="<?php echo $report['url']; ?>" style="color: #64b5f6; font-weight: 600;">
                    View <?php if ($report['count'] > 0): ?>
                        <span class="badge badge-<?php echo $report['color']; ?>">
                            <?php echo Formatter::number($report['count']); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge badge-success">—</span>
                    <?php endif; ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php Layout::footer();
?>
