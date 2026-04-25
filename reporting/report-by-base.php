<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

$baseName = isset($_GET['base']) ? trim($_GET['base']) : '';

// Validate base package name format
if (!$baseName || !preg_match('/^[a-z0-9._\-]+$/i', $baseName) || strlen($baseName) > 256) {
    die("Invalid base package name");
}

// Normalize to lowercase
$baseName = strtolower($baseName);

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $packages = $repo->getPackagesByBase($baseName);
    
    // Separate by architecture
    $aarch64Packages = [];
    $x86_64Packages = [];
    
    foreach ($packages as $pkg) {
        if ($pkg['system_arch'] === 'aarch64') {
            $aarch64Packages[] = $pkg;
        } else {
            $x86_64Packages[] = $pkg;
        }
    }
    
    // Sort by name
    usort($aarch64Packages, function($a, $b) { return strcmp($a['name'], $b['name']); });
    usort($x86_64Packages, function($a, $b) { return strcmp($a['name'], $b['name']); });
    
    // Get version for each arch (should be the same for all packages in that arch)
    $aarch64Version = !empty($aarch64Packages) ? $aarch64Packages[0]['version'] : null;
    $x86_64Version = !empty($x86_64Packages) ? $x86_64Packages[0]['version'] : null;
    
    // Get repo for each arch (should be the same for all packages in that arch)
    $aarch64Repo = !empty($aarch64Packages) ? $aarch64Packages[0]['repo'] : null;
    $x86_64Repo = !empty($x86_64Packages) ? $x86_64Packages[0]['repo'] : null;
    
} catch (Exception $e) {
    error_log("Error in report-by-base.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

Layout::header('Packages with Base: ' . $baseName);
?>

<div class="container">
    <h1 style="margin-bottom: 20px;">📦 Packages with Base: <strong><?php echo Formatter::escape($baseName); ?></strong></h1>
    <p style="margin-bottom: 30px; opacity: 0.8;">Split packages created from the same source (base)</p>

    <?php if (empty($aarch64Packages) && empty($x86_64Packages)): ?>
        <div class="card">
            <div class="alert alert-info">No packages found with base package "<?php echo Formatter::escape($baseName); ?>".</div>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- aarch64 Card -->
            <div style="background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 20px;">
                <h3 style="color: #64b5f6; margin-bottom: 8px; padding-bottom: 10px; border-bottom: 1px solid #333;">
                    aarch64
                </h3>
                <div style="font-size: 12px; opacity: 0.7; margin-bottom: 20px;">
                    <?php echo $aarch64Repo ? Formatter::escape($aarch64Repo) : '—'; ?> • Version <?php echo $aarch64Version ? Formatter::escape($aarch64Version) : '—'; ?>
                </div>
                
                <?php if (empty($aarch64Packages)): ?>
                    <p style="opacity: 0.5;">No packages found</p>
                <?php else: ?>
                    <div style="font-size: 13px;">
                        <?php foreach ($aarch64Packages as $pkg): ?>
                            <div style="margin: 2px 0;">• <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #64b5f6; text-decoration: none;"><?php echo Formatter::escape($pkg['name']); ?></a></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- x86_64 Card -->
            <div style="background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 20px;">
                <h3 style="color: #64b5f6; margin-bottom: 8px; padding-bottom: 10px; border-bottom: 1px solid #333;">
                    x86_64
                </h3>
                <div style="font-size: 12px; opacity: 0.7; margin-bottom: 20px;">
                    <?php echo $x86_64Repo ? Formatter::escape($x86_64Repo) : '—'; ?> • Version <?php echo $x86_64Version ? Formatter::escape($x86_64Version) : '—'; ?>
                </div>
                
                <?php if (empty($x86_64Packages)): ?>
                    <p style="opacity: 0.5;">No packages found</p>
                <?php else: ?>
                    <div style="font-size: 13px;">
                        <?php foreach ($x86_64Packages as $pkg): ?>
                            <div style="margin: 2px 0;">• <a href="<?php echo Formatter::url('package-detail.php', ['name' => $pkg['name']]); ?>" style="color: #64b5f6; text-decoration: none;"><?php echo Formatter::escape($pkg['name']); ?></a></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php Layout::footer(); ?>
