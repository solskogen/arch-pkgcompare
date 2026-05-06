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
    // Show only where aarch64 is NEWER - x86_64 is behind
    $ahead = array_filter($all_packages, function($pkg) {
        return version_compare($pkg['x86_64_version'], $pkg['aarch64_version'], '<');
    });
    
    // Also get the count for the "outdated" link
    $outdated = array_filter($all_packages, function($pkg) {
        return version_compare($pkg['aarch64_version'], $pkg['x86_64_version'], '<');
    });
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('aarch64 Ahead Packages');
?>

<div class="container">
    <div class="card">
        <h2>⚡ aarch64 Ahead Packages (<?php echo count($ahead); ?>)</h2>
        <p style="margin-bottom: 15px; opacity: 0.8;">Packages where aarch64 version is ahead of x86_64</p>

        <div style="margin-bottom: 20px;">
            <a href="<?php echo Formatter::url('report-outdated.php'); ?>" style="display: inline-block; padding: 10px 16px; background-color: var(--accent-color); color: white; border-radius: 4px; text-decoration: none; font-weight: 600;">
                📦 View aarch64 outdated (<?php echo count($outdated); ?>)
            </a>
        </div>

        <?php if (empty($ahead)): ?>
            <div class="alert alert-success">No packages where aarch64 is ahead! ✓</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>aarch64 Version</th>
                        <th>x86_64 Version</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ahead as $pkg): ?>
                    <tr>
                        <td>
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>">
                                <?php echo Formatter::escape($pkg['name']); ?>
                            </a>
                        </td>
                        <td><?php echo Formatter::escape($pkg['aarch64_version']); ?></td>
                        <td><span class="badge badge-error"><?php echo Formatter::escape($pkg['x86_64_version']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
