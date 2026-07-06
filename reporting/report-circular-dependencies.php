<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $cyclesByLength = $repo->getCyclesConsolidated();
    $countsByLength = $repo->countCyclesByLength();
} catch (Exception $e) {
    error_log("Error in report-circular-dependencies.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

// Calculate totals (use consolidated counts)
$totalCycles = 0;
foreach ($cyclesByLength as $length => $cycles) {
    $totalCycles += count($cycles);
}

Layout::header('Circular Dependencies');
?>

<div class="container">
    <div class="card">
        <h2>🔄 All Circular Dependencies</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">
            All circular dependencies sorted by cycle length (largest cycles first)
        </p>

        <?php if ($totalCycles === 0): ?>
            <div class="alert alert-success">✓ No circular dependencies detected!</div>
        <?php else: ?>
            <p style="margin-bottom: 20px; opacity: 0.7;">Found <strong><?php echo $totalCycles; ?> total cycles</strong></p>

            <!-- Cycle length summary -->
            <div style="margin-bottom: 25px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <?php 
                    // Create summary from consolidated cycles
                    $summary = [];
                    foreach ($cyclesByLength as $length => $cycles) {
                        $summary[$length] = count($cycles);
                    }
                    // Sort by cycle length descending
                    krsort($summary);
                    foreach ($summary as $length => $count): 
                        if ($count > 0):
                    ?>
                    <div style="background: #1a1a1a; border: 1px solid #333; padding: 15px; border-radius: 8px;">
                        <div style="font-size: 28px; font-weight: bold; color: #64b5f6;">
                            <?php echo $count; ?>
                        </div>
                        <div style="opacity: 0.8; font-size: 14px;">
                            <?php echo $length; ?>-Package Cycles
                        </div>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>

            <!-- Display cycles by length (largest first) -->
            <?php
            // Sort by cycle length descending
            krsort($cyclesByLength);
            foreach ($cyclesByLength as $length => $cycles):
                if (empty($cycles)) continue;
                $count = count($cycles);
            ?>

            <div style="margin-bottom: 30px; padding: 20px; border: 1px solid #444; border-radius: 8px; background: rgba(100, 181, 246, 0.05);">
                <h3 style="color: #64b5f6; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">
                        <?php
                        $icons = [2 => '🔗', 3 => '🔀', 4 => '🔁', 5 => '🌀'];
                        echo $icons[$length] ?? '🔄';
                        ?>
                    </span>
                    <?php echo $length; ?>-Package Cycles (<?php echo $count; ?> found)
                </h3>

                <div class="packages-table">
                    <?php if ($length == 2): ?>
                        <!-- 2-way cycles: simple table -->
                        <table>
                            <thead>
                                <tr>
                                    <th>Package A</th>
                                    <th>Package B</th>
                                    <th>Cycle</th>
                                    <th>Architecture(s)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cyclesByLength[$length] as $cycle): 
                                    // Determine architecture display
                                    $archDisplay = '';
                                    if (count($cycle['architectures']) === 2) {
                                        $archDisplay = 'Both';
                                    } elseif (in_array('aarch64', $cycle['architectures'])) {
                                        $archDisplay = 'aarch64 only';
                                    } else {
                                        $archDisplay = 'x86_64 only';
                                    }
                                    $badgeColor = count($cycle['architectures']) === 2 ? '#4caf50' : '#ff9800';
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $cycle['package_a']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($cycle['package_a']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $cycle['package_b']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($cycle['package_b']); ?>
                                        </a>
                                    </td>
                                    <td style="color: #90caf9;">
                                        <?php echo Formatter::escape($cycle['package_a']); ?> ↔ <?php echo Formatter::escape($cycle['package_b']); ?>
                                    </td>
                                    <td>
                                        <span style="background: <?php echo $badgeColor; ?>20; color: <?php echo $badgeColor; ?>; padding: 3px 8px; border-radius: 3px; font-size: 0.9em;">
                                            <?php echo $archDisplay; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <!-- 3+ way cycles: card layout -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 15px;">
                            <?php foreach ($cyclesByLength[$length] as $cycle):
                                // Determine architecture display
                                $archDisplay = '';
                                if (count($cycle['architectures']) === 2) {
                                    $archDisplay = 'Both architectures';
                                } elseif (in_array('aarch64', $cycle['architectures'])) {
                                    $archDisplay = 'aarch64 only';
                                } else {
                                    $archDisplay = 'x86_64 only';
                                }
                                $badgeColor = count($cycle['architectures']) === 2 ? '#4caf50' : '#ff9800';
                            ?>
                            <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                                <div style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                                    <span style="background: <?php echo $badgeColor; ?>20; color: <?php echo $badgeColor; ?>; padding: 3px 8px; border-radius: 3px; font-size: 0.85em;">
                                        <?php echo $archDisplay; ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.95em; color: #90caf9; font-family: monospace; line-height: 1.6; word-break: break-all;">
                                    <?php
                                    // Create clickable cycle path
                                    $packages = [$cycle['pkg1'], $cycle['pkg2']];
                                    if ($length >= 3) $packages[] = $cycle['pkg3'];
                                    if ($length >= 4) $packages[] = $cycle['pkg4'] ?? null;
                                    if ($length >= 5) $packages[] = $cycle['pkg5'] ?? null;
                                    
                                    for ($i = 0; $i < count($packages); $i++):
                                        $pkg = $packages[$i];
                                        if ($pkg):
                                    ?>
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg]); ?>" 
                                           style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($pkg); ?>
                                        </a>
                                        <?php if ($i < count($packages) - 1): ?>
                                            <span style="color: #888;"> ↓<br></span>
                                        <?php else: ?>
                                            <span style="color: #888;"> ↓</span><br>
                                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $packages[0]]); ?>" 
                                               style="color: #64b5f6; text-decoration: none;">
                                                <?php echo Formatter::escape($packages[0]); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; endfor; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php endforeach; ?>

        <?php endif; ?>

        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #333;">
            <a href="<?php echo Formatter::url('analysis.php'); ?>" class="back-link">← Back to Analysis</a>
        </div>
    </div>
</div>

<?php Layout::footer();
?>
