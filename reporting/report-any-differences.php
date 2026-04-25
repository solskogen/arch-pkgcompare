<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $differences = $repo->getAnyPackageDifferences();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$aarch64_only = array_filter($differences, fn($p) => $p['type'] === 'aarch64_only');
$x86_64_only = array_filter($differences, fn($p) => $p['type'] === 'x86_64_only');

Layout::header('-any Package Differences');
?>

<div class="container">
    <div class="card">
        <h2>📦 -any Package Differences</h2>
        <p style="margin-bottom: 20px; opacity: 0.8;">Architecture-independent packages (-any) that are missing on one architecture</p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                <h3 style="color: #51cf66; margin-bottom: 10px;">✨ aarch64 Only (<?php echo count($aarch64_only); ?>)</h3>
                <p style="font-size: 13px; opacity: 0.7; margin-bottom: 10px;">-any packages present in aarch64 but missing in x86_64</p>
            </div>
            <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                <h3 style="color: #ff6b6b; margin-bottom: 10px;">⚠️ x86_64 Only (<?php echo count($x86_64_only); ?>)</h3>
                <p style="font-size: 13px; opacity: 0.7; margin-bottom: 10px;">-any packages present in x86_64 but missing in aarch64</p>
            </div>
        </div>

        <?php if (!empty($aarch64_only)): ?>
        <div style="margin-bottom: 40px;">
            <h3 style="color: #51cf66; margin-bottom: 15px;">✨ aarch64 Only -any Packages</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Package Name</th>
                            <th>Version</th>
                            <th>Repository</th>
                            <th>Compressed Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aarch64_only as $pkg): ?>
                        <tr>
                            <td>
                                <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>">
                                    <?php echo Formatter::escape($pkg['name']); ?>
                                </a>
                            </td>
                            <td><?php echo Formatter::escape($pkg['version']); ?></td>
                            <td><?php echo Formatter::escape($pkg['repo']); ?></td>
                            <td><?php echo Formatter::size($pkg['csize']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div style="background: rgba(81, 207, 102, 0.1); border: 1px solid #51cf66; border-radius: 4px; padding: 15px; margin-bottom: 40px;">
            <p style="color: #51cf66;">✓ No aarch64-only -any packages found</p>
        </div>
        <?php endif; ?>

        <?php if (!empty($x86_64_only)): ?>
        <div style="margin-bottom: 40px;">
            <h3 style="color: #ff6b6b; margin-bottom: 15px;">⚠️ x86_64 Only -any Packages</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Package Name</th>
                            <th>Version</th>
                            <th>Repository</th>
                            <th>Compressed Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($x86_64_only as $pkg): ?>
                        <tr>
                            <td>
                                <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>">
                                    <?php echo Formatter::escape($pkg['name']); ?>
                                </a>
                            </td>
                            <td><?php echo Formatter::escape($pkg['version']); ?></td>
                            <td><?php echo Formatter::escape($pkg['repo']); ?></td>
                            <td><?php echo Formatter::size($pkg['csize']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div style="background: rgba(255, 107, 107, 0.1); border: 1px solid #ff6b6b; border-radius: 4px; padding: 15px;">
            <p style="color: #ff6b6b;">✓ No x86_64-only -any packages found</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
