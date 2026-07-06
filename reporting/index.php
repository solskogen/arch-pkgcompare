<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $stats = $repo->getStats();

    $row = $db->fetchOne("SELECT import_timestamp FROM import_metadata ORDER BY id DESC LIMIT 1");
    $last_updated = $row['import_timestamp'] ?? null;
} catch (Exception $e) {
    error_log("Error in index.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

$arch1 = $repo->primaryArch;
$arch2 = $repo->referenceArch;

Layout::header('Arch Linux Package Comparison');
?>

<div class="home-hero">
    <div class="container">
        <h1 class="home-hero__title">📦 Arch Linux Package Comparison</h1>
        <p class="home-hero__subtitle">
            <?php echo Formatter::escape(strtoupper($arch1)); ?> vs <?php echo Formatter::escape(strtoupper($arch2)); ?> — package ecosystem analysis
        </p>
        <?php if ($last_updated): ?>
        <p class="home-hero__updated">Last import: <?php echo Formatter::escape($last_updated); ?></p>
        <?php endif; ?>
        <div class="home-hero__actions">
            <a href="<?php echo Formatter::url('analysis.php'); ?>" class="btn btn-primary">View Analysis Dashboard</a>
            <a href="<?php echo Formatter::url('comparison.php'); ?>" class="btn btn-secondary">Detailed Comparison</a>
        </div>
    </div>
</div>

<div class="container">

    <!-- Key numbers -->
    <div class="home-stats">
        <a href="<?php echo Formatter::url('report-packages-aarch64.php'); ?>" class="home-stat">
            <span class="home-stat__value"><?php echo Formatter::number($stats['aarch64_packages']); ?></span>
            <span class="home-stat__label"><?php echo Formatter::escape($arch1); ?> packages</span>
        </a>
        <a href="<?php echo Formatter::url('report-packages-x86_64.php'); ?>" class="home-stat">
            <span class="home-stat__value"><?php echo Formatter::number($stats['x86_64_packages']); ?></span>
            <span class="home-stat__label"><?php echo Formatter::escape($arch2); ?> packages</span>
        </a>
        <a href="<?php echo Formatter::url('report-aarch64-only.php'); ?>" class="home-stat home-stat--warn">
            <span class="home-stat__value"><?php echo Formatter::number($stats['aarch64_only_count']); ?></span>
            <span class="home-stat__label"><?php echo Formatter::escape($arch1); ?> only</span>
        </a>
        <a href="<?php echo Formatter::url('report-x86_64-only.php'); ?>" class="home-stat home-stat--warn">
            <span class="home-stat__value"><?php echo Formatter::number($stats['x86_64_only_count']); ?></span>
            <span class="home-stat__label"><?php echo Formatter::escape($arch2); ?> only</span>
        </a>
        <a href="<?php echo Formatter::url('report-newer-versions.php'); ?>" class="home-stat home-stat--info">
            <span class="home-stat__value"><?php echo Formatter::number($stats['aarch64_newer_count']); ?></span>
            <span class="home-stat__label"><?php echo Formatter::escape($arch1); ?> ahead</span>
        </a>
        <a href="<?php echo Formatter::url('report-x86_64-newer.php'); ?>" class="home-stat home-stat--info">
            <span class="home-stat__value"><?php echo Formatter::number($stats['x86_64_newer_count']); ?></span>
            <span class="home-stat__label"><?php echo Formatter::escape($arch2); ?> ahead</span>
        </a>
    </div>

    <!-- Report groups -->
    <div class="home-groups">

        <div class="home-group">
            <h2 class="home-group__title">📋 Package Availability</h2>
            <div class="home-group__links">
                <a href="<?php echo Formatter::url('report-aarch64-only.php'); ?>" class="home-link">
                    <span class="home-link__icon">✨</span>
                    <span class="home-link__text">
                        <strong><?php echo Formatter::escape($arch1); ?>-only packages</strong>
                        <em><?php echo Formatter::number($stats['aarch64_only_count']); ?> packages not in <?php echo Formatter::escape($arch2); ?></em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-x86_64-only.php'); ?>" class="home-link">
                    <span class="home-link__icon">⚠️</span>
                    <span class="home-link__text">
                        <strong><?php echo Formatter::escape($arch2); ?>-only packages</strong>
                        <em><?php echo Formatter::number($stats['x86_64_only_count']); ?> packages not in <?php echo Formatter::escape($arch1); ?></em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-x86_64-only-provides.php'); ?>" class="home-link">
                    <span class="home-link__icon">🔍</span>
                    <span class="home-link__text">
                        <strong><?php echo Formatter::escape($arch2); ?>-only (excl. provides)</strong>
                        <em><?php echo Formatter::number($stats['x86_64_only_not_provided_count'] ?? 0); ?> truly missing packages</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-mismatches.php'); ?>" class="home-link">
                    <span class="home-link__icon">📦</span>
                    <span class="home-link__text">
                        <strong>Package base mismatches</strong>
                        <em><?php echo Formatter::number($stats['mismatches_count']); ?> split package differences</em>
                    </span>
                </a>
            </div>
        </div>

        <div class="home-group">
            <h2 class="home-group__title">⬆️ Version Differences</h2>
            <div class="home-group__links">
                <a href="<?php echo Formatter::url('report-newer-versions.php'); ?>" class="home-link">
                    <span class="home-link__icon">🚀</span>
                    <span class="home-link__text">
                        <strong><?php echo Formatter::escape($arch1); ?> newer versions</strong>
                        <em><?php echo Formatter::number($stats['aarch64_newer_count']); ?> packages ahead of <?php echo Formatter::escape($arch2); ?></em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-x86_64-newer.php'); ?>" class="home-link">
                    <span class="home-link__icon">🐢</span>
                    <span class="home-link__text">
                        <strong><?php echo Formatter::escape($arch2); ?> newer versions</strong>
                        <em><?php echo Formatter::number($stats['x86_64_newer_count']); ?> packages behind <?php echo Formatter::escape($arch2); ?></em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-outdated-any.php'); ?>" class="home-link">
                    <span class="home-link__icon">🔄</span>
                    <span class="home-link__text">
                        <strong>Outdated -any packages</strong>
                        <em><?php echo Formatter::number($stats['outdated_any_count']); ?> arch-independent packages outdated</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-size-differences.php'); ?>" class="home-link">
                    <span class="home-link__icon">📊</span>
                    <span class="home-link__text">
                        <strong>Package size differences</strong>
                        <em><?php echo Formatter::number($stats['size_diff_count']); ?> packages with notable size gaps</em>
                    </span>
                </a>
            </div>
        </div>

        <div class="home-group">
            <h2 class="home-group__title">🔗 Metadata Differences</h2>
            <div class="home-group__links">
                <a href="<?php echo Formatter::url('report-dependency-differences.php'); ?>" class="home-link">
                    <span class="home-link__icon">🔗</span>
                    <span class="home-link__text">
                        <strong>Dependency differences</strong>
                        <em>Packages with different deps per arch</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-provides-differences.php'); ?>" class="home-link">
                    <span class="home-link__icon">📦</span>
                    <span class="home-link__text">
                        <strong>Provides differences</strong>
                        <em>Different virtual packages provided</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-license-discrepancies.php'); ?>" class="home-link">
                    <span class="home-link__icon">⚖️</span>
                    <span class="home-link__text">
                        <strong>License discrepancies</strong>
                        <em><?php echo Formatter::number($stats['license_discrepancies_count']); ?> packages with license differences</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-circular-dependencies.php'); ?>" class="home-link">
                    <span class="home-link__icon">🔁</span>
                    <span class="home-link__text">
                        <strong>Circular dependencies</strong>
                        <em>Packages with circular dep chains</em>
                    </span>
                </a>
            </div>
        </div>

        <div class="home-group">
            <h2 class="home-group__title">📁 Package Listings</h2>
            <div class="home-group__links">
                <a href="<?php echo Formatter::url('report-packages-aarch64.php'); ?>" class="home-link">
                    <span class="home-link__icon">🗂️</span>
                    <span class="home-link__text">
                        <strong>All <?php echo Formatter::escape($arch1); ?> packages</strong>
                        <em><?php echo Formatter::number($stats['aarch64_packages']); ?> packages — sortable by size</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-packages-x86_64.php'); ?>" class="home-link">
                    <span class="home-link__icon">🗂️</span>
                    <span class="home-link__text">
                        <strong>All <?php echo Formatter::escape($arch2); ?> packages</strong>
                        <em><?php echo Formatter::number($stats['x86_64_packages']); ?> packages — sortable by size</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('report-repo-comparison.php'); ?>" class="home-link">
                    <span class="home-link__icon">🏛️</span>
                    <span class="home-link__text">
                        <strong>Per-repository comparison</strong>
                        <em>core / extra / forge breakdown</em>
                    </span>
                </a>
                <a href="<?php echo Formatter::url('analysis.php'); ?>" class="home-link">
                    <span class="home-link__icon">📈</span>
                    <span class="home-link__text">
                        <strong>Full analysis dashboard</strong>
                        <em>All 23 reports with counts</em>
                    </span>
                </a>
            </div>
        </div>

    </div>
</div>

<?php Layout::footer();

