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
    die("An internal error occurred.");
}

$arch1 = $repo->primaryArch;
$arch2 = $repo->referenceArch;

Layout::header('Arch Linux Package Comparison', ['show_page_header' => false]);
?>

<div class="lp-hero">
    <div class="lp-hero__inner">
        <div class="lp-hero__eyebrow">
            <?php echo htmlspecialchars(strtoupper($arch1), ENT_QUOTES, 'UTF-8'); ?>
            <span class="lp-hero__sep">↔</span>
            <?php echo htmlspecialchars(strtoupper($arch2), ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <h1 class="lp-hero__title">Arch Linux Package Comparison</h1>
        <p class="lp-hero__sub">Track differences, version gaps, and missing packages across both architectures</p>
        <?php if ($last_updated): ?>
        <p class="lp-hero__ts">Last import: <?php echo htmlspecialchars($last_updated, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <div class="lp-hero__cta">
            <a href="<?php echo Formatter::url('analysis.php'); ?>" class="lp-btn lp-btn--primary">Analysis dashboard</a>
            <a href="<?php echo Formatter::url('comparison.php'); ?>" class="lp-btn lp-btn--ghost">Detailed comparison</a>
        </div>
    </div>
</div>

<div class="lp-body container">

    <div class="lp-kpi">
        <a href="<?php echo Formatter::url('report-packages-aarch64.php'); ?>" class="lp-kpi__cell">
            <span class="lp-kpi__num"><?php echo number_format($stats['aarch64_packages']); ?></span>
            <span class="lp-kpi__lbl"><?php echo htmlspecialchars($arch1, ENT_QUOTES, 'UTF-8'); ?> packages</span>
        </a>
        <a href="<?php echo Formatter::url('report-packages-x86_64.php'); ?>" class="lp-kpi__cell">
            <span class="lp-kpi__num"><?php echo number_format($stats['x86_64_packages']); ?></span>
            <span class="lp-kpi__lbl"><?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?> packages</span>
        </a>
        <div class="lp-kpi__divider"></div>
        <a href="<?php echo Formatter::url('report-aarch64-only.php'); ?>" class="lp-kpi__cell lp-kpi__cell--warn">
            <span class="lp-kpi__num"><?php echo number_format($stats['aarch64_only_count']); ?></span>
            <span class="lp-kpi__lbl"><?php echo htmlspecialchars($arch1, ENT_QUOTES, 'UTF-8'); ?>-only</span>
        </a>
        <a href="<?php echo Formatter::url('report-x86_64-only.php'); ?>" class="lp-kpi__cell lp-kpi__cell--warn">
            <span class="lp-kpi__num"><?php echo number_format($stats['x86_64_only_count']); ?></span>
            <span class="lp-kpi__lbl"><?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?>-only</span>
        </a>
        <div class="lp-kpi__divider"></div>
        <a href="<?php echo Formatter::url('report-newer-versions.php'); ?>" class="lp-kpi__cell lp-kpi__cell--good">
            <span class="lp-kpi__num"><?php echo number_format($stats['aarch64_newer_count']); ?></span>
            <span class="lp-kpi__lbl"><?php echo htmlspecialchars($arch1, ENT_QUOTES, 'UTF-8'); ?> ahead</span>
        </a>
        <a href="<?php echo Formatter::url('report-x86_64-newer.php'); ?>" class="lp-kpi__cell lp-kpi__cell--good">
            <span class="lp-kpi__num"><?php echo number_format($stats['x86_64_newer_count']); ?></span>
            <span class="lp-kpi__lbl"><?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?> ahead</span>
        </a>
    </div>

    <div class="lp-sections">

        <section class="lp-section">
            <h2 class="lp-section__title">📦 Package availability</h2>
            <ul class="lp-list">
                <li><a href="<?php echo Formatter::url('report-aarch64-only.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name"><?php echo htmlspecialchars($arch1, ENT_QUOTES, 'UTF-8'); ?>-only packages</span>
                    <span class="lp-list__count lp-list__count--warn"><?php echo number_format($stats['aarch64_only_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-x86_64-only.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name"><?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?>-only packages</span>
                    <span class="lp-list__count lp-list__count--warn"><?php echo number_format($stats['x86_64_only_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-x86_64-only-provides.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name"><?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?>-only (excl. provides)</span>
                    <span class="lp-list__count lp-list__count--warn"><?php echo number_format($stats['x86_64_only_not_provided_count'] ?? 0); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-mismatches.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Package base mismatches</span>
                    <span class="lp-list__count"><?php echo number_format($stats['mismatches_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-orphaned.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Orphaned split packages</span>
                    <span class="lp-list__count"><?php echo number_format($stats['orphaned_count']); ?></span>
                </a></li>
            </ul>
        </section>

        <section class="lp-section">
            <h2 class="lp-section__title">⬆️ Version differences</h2>
            <ul class="lp-list">
                <li><a href="<?php echo Formatter::url('report-newer-versions.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name"><?php echo htmlspecialchars($arch1, ENT_QUOTES, 'UTF-8'); ?> ahead of <?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="lp-list__count lp-list__count--good"><?php echo number_format($stats['aarch64_newer_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-x86_64-newer.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name"><?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?> ahead of <?php echo htmlspecialchars($arch1, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="lp-list__count lp-list__count--good"><?php echo number_format($stats['x86_64_newer_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-outdated-any.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Outdated -any packages</span>
                    <span class="lp-list__count"><?php echo number_format($stats['outdated_any_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-missing-any.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Missing -any packages</span>
                    <span class="lp-list__count lp-list__count--warn"><?php echo number_format($stats['missing_any_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-size-differences.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Notable size differences (&ge;10 MB)</span>
                    <span class="lp-list__count"><?php echo number_format($stats['size_diff_count']); ?></span>
                </a></li>
            </ul>
        </section>

        <section class="lp-section">
            <h2 class="lp-section__title">🔗 Metadata analysis</h2>
            <ul class="lp-list">
                <li><a href="<?php echo Formatter::url('report-dependency-differences.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Dependency differences</span><span class="lp-list__count"></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-provides-differences.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Provides / virtual package differences</span><span class="lp-list__count"></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-license-discrepancies.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">License discrepancies</span>
                    <span class="lp-list__count lp-list__count--warn"><?php echo number_format($stats['license_discrepancies_count']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-circular-dependencies.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Circular dependencies</span><span class="lp-list__count"></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('analysis.php'); ?>" class="lp-list__row lp-list__row--cta">
                    <span class="lp-list__name">Full analysis dashboard — all 23 reports</span>
                    <span class="lp-list__arrow">→</span>
                </a></li>
            </ul>
        </section>

        <section class="lp-section">
            <h2 class="lp-section__title">📁 Browse packages</h2>
            <ul class="lp-list">
                <li><a href="<?php echo Formatter::url('report-packages-aarch64.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">All <?php echo htmlspecialchars($arch1, ENT_QUOTES, 'UTF-8'); ?> packages</span>
                    <span class="lp-list__count"><?php echo number_format($stats['aarch64_packages']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-packages-x86_64.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">All <?php echo htmlspecialchars($arch2, ENT_QUOTES, 'UTF-8'); ?> packages</span>
                    <span class="lp-list__count"><?php echo number_format($stats['x86_64_packages']); ?></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('report-repo-comparison.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Per-repository comparison</span><span class="lp-list__count"></span>
                </a></li>
                <li><a href="<?php echo Formatter::url('comparison.php'); ?>" class="lp-list__row">
                    <span class="lp-list__name">Side-by-side arch stats</span><span class="lp-list__count"></span>
                </a></li>
            </ul>
        </section>

    </div>

</div>

<?php Layout::footer();
