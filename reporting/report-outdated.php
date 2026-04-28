<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    
    // Get all packages that appear in both architectures
    $all_packages = $db->fetchAll("
        SELECT DISTINCT 
            a.name, a.version as aarch64_version, x.version as x86_64_version
        FROM packages a
        INNER JOIN packages x ON a.name = x.name
        WHERE a.system_arch = 'aarch64' AND x.system_arch = 'x86_64'
        ORDER BY a.name
    ");
    
    // Filter using PHP version_compare for accurate Semantic Versioning
    $aarch64_older = array_filter($all_packages, function($pkg) {
        return version_compare($pkg['aarch64_version'], $pkg['x86_64_version'], '<');
    });
    
    $x86_64_older = array_filter($all_packages, function($pkg) {
        return version_compare($pkg['x86_64_version'], $pkg['aarch64_version'], '<');
    });
    
    $total_outdated = count($aarch64_older) + count($x86_64_older);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('All Outdated Packages');
?>

<div class="container">
    <div class="card">
        <h2>📦 All Outdated Packages (<?php echo $total_outdated; ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Packages with version mismatches between architectures</p>

        <?php if ($total_outdated === 0): ?>
            <div class="alert alert-success">All packages are synchronized! ✓</div>
        <?php else: ?>
            <?php if (!empty($aarch64_older)): ?>
            <h3 style="margin-top: 20px; margin-bottom: 10px; color: #ffb74d;">aarch64 is older (<?php echo count($aarch64_older); ?>)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>aarch64 Version</th>
                        <th>x86_64 Version</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aarch64_older as $pkg): ?>
                    <tr>
                        <td>
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>">
                                <?php echo Formatter::escape($pkg['name']); ?>
                            </a>
                        </td>
                        <td><span class="badge badge-error"><?php echo Formatter::escape($pkg['aarch64_version']); ?></span></td>
                        <td><?php echo Formatter::escape($pkg['x86_64_version']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (!empty($x86_64_older)): ?>
            <h3 style="margin-top: 20px; margin-bottom: 10px; color: #ffb74d;">x86_64 is older (<?php echo count($x86_64_older); ?>)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>x86_64 Version</th>
                        <th>aarch64 Version</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($x86_64_older as $pkg): ?>
                    <tr>
                        <td>
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>">
                                <?php echo Formatter::escape($pkg['name']); ?>
                            </a>
                        </td>
                        <td><span class="badge badge-error"><?php echo Formatter::escape($pkg['x86_64_version']); ?></span></td>
                        <td><?php echo Formatter::escape($pkg['aarch64_version']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
