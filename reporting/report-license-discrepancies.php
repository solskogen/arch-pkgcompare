<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $discrepancies = $repo->getAllDiscrepancies();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('Discrepancies');
?>

<div class="container">
    <h1 style="margin-bottom: 20px;">📋 License Discrepancies (<?php echo count($discrepancies); ?>)</h1>
    <p style="margin-bottom: 30px; opacity: 0.8;">Packages with different licenses between aarch64 and x86_64</p>

    <?php if (empty($discrepancies)): ?>
        <div class="card">
            <div class="alert alert-info">No discrepancies found.</div>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(450px, 1fr)); gap: 20px;">
            <?php foreach ($discrepancies as $d): 
                $details = $repo->getPackageDiscrepancies($d['aarch64_id'], $d['x86_64_id']);
                
                // Check for any differences
                $hasLicenseDiff = $d['aarch64_licenses'] != $d['x86_64_licenses'];
                $hasVersionDiff = $d['aarch64_version'] != $d['x86_64_version'];
                $hasRepoDiff = $d['aarch64_repo'] != $d['x86_64_repo'];
                $hasProvidesDiff = count($details['provides_aarch64']) != count($details['provides_x86_64']);
                $hasConflictsDiff = count($details['conflicts_aarch64']) != count($details['conflicts_x86_64']);
                $hasReplacesDiff = count($details['replaces_aarch64']) != count($details['replaces_x86_64']);
                $hasGroupsDiff = count($details['groups_aarch64']) != count($details['groups_x86_64']);
                $hasOptdepsDiff = count($details['optdeps_aarch64']) != count($details['optdeps_x86_64']);
            ?>
            <div class="card" style="background: #1a1a1a; border: 1px solid #ff9800; border-radius: 8px; padding: 20px;">
                <h3 style="color: #64b5f6; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #333;">
                    <a href="<?php echo Formatter::url('package-detail.php', ['name' => $d['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                        <?php echo Formatter::escape($d['name']); ?>
                    </a>
                </h3>
                
                <?php if ($hasVersionDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">📦 Version</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div><span style="opacity: 0.7;">aarch64:</span> <strong><?php echo Formatter::escape($d['aarch64_version']); ?></strong></div>
                        <div><span style="opacity: 0.7;">x86_64:</span> <strong><?php echo Formatter::escape($d['x86_64_version']); ?></strong></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($hasRepoDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">📚 Repository</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div><span style="opacity: 0.7;">aarch64:</span> <strong><?php echo Formatter::escape($d['aarch64_repo']); ?></strong></div>
                        <div><span style="opacity: 0.7;">x86_64:</span> <strong><?php echo Formatter::escape($d['x86_64_repo']); ?></strong></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($hasLicenseDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">📋 Licenses</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div>
                            <span style="opacity: 0.7;">aarch64:</span>
                            <div style="margin-top: 2px;"><?php 
                                if ($d['aarch64_licenses'] === '(none)') {
                                    echo '<span style="opacity: 0.5;">(none)</span>';
                                } else {
                                    echo Formatter::escape($d['aarch64_licenses']);
                                } 
                            ?></div>
                        </div>
                        <div>
                            <span style="opacity: 0.7;">x86_64:</span>
                            <div style="margin-top: 2px;"><?php 
                                if ($d['x86_64_licenses'] === '(none)') {
                                    echo '<span style="opacity: 0.5;">(none)</span>';
                                } else {
                                    echo Formatter::escape($d['x86_64_licenses']);
                                } 
                            ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($hasProvidesDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">➡️ Provides (<?php echo count($details['provides_aarch64']); ?> vs <?php echo count($details['provides_x86_64']); ?>)</div>
                </div>
                <?php endif; ?>
                
                <?php if ($hasConflictsDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">⚔️ Conflicts (<?php echo count($details['conflicts_aarch64']); ?> vs <?php echo count($details['conflicts_x86_64']); ?>)</div>
                </div>
                <?php endif; ?>
                
                <?php if ($hasReplacesDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">🔄 Replaces (<?php echo count($details['replaces_aarch64']); ?> vs <?php echo count($details['replaces_x86_64']); ?>)</div>
                </div>
                <?php endif; ?>
                
                <?php if ($hasGroupsDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">👥 Groups (<?php echo count($details['groups_aarch64']); ?> vs <?php echo count($details['groups_x86_64']); ?>)</div>
                </div>
                <?php endif; ?>
                
                <?php if ($hasOptdepsDiff): ?>
                <div style="margin-bottom: 12px; padding: 8px; background: #0a0a0a; border-left: 3px solid #f57c00; border-radius: 4px; font-size: 12px;">
                    <div style="font-weight: bold; color: #f57c00; margin-bottom: 4px;">📦 Optional Deps (<?php echo count($details['optdeps_aarch64']); ?> vs <?php echo count($details['optdeps_x86_64']); ?>)</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php Layout::footer();
