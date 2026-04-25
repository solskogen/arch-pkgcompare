<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

$pkg_name = isset($_GET['name']) ? trim($_GET['name']) : null;

// Validate package name format (Arch Linux naming conventions)
if (!$pkg_name || !preg_match('/^[a-z0-9._\-]+$/i', $pkg_name) || strlen($pkg_name) > 256) {
    die("Invalid package name");
}

// Normalize to lowercase
$pkg_name = strtolower($pkg_name);

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $variants = $repo->getVariants($pkg_name);
    $reverse_deps = $repo->getReverseDependencies($pkg_name);
    
    // Get dependencies and makedependencies for each variant
    $dependencies_by_arch = [];
    $makedependencies_by_arch = [];
    
    // Get discrepancies if both architectures exist
    $discrepancies = null;
    if (count($variants) > 1) {
        $aarch64_id = null;
        $x86_64_id = null;
        foreach ($variants as $v) {
            if ($v['system_arch'] === 'aarch64') {
                $aarch64_id = $v['id'];
                $deps = $repo->getDependencies($aarch64_id);
                // Filter to only dependencies from the same architecture
                $dependencies_by_arch['aarch64'] = array_filter($deps, function($d) { 
                    return $d['system_arch'] === 'aarch64'; 
                });
                $makedeps = $repo->getMakeDependencies($aarch64_id);
                // Filter to only makedependencies from the same architecture
                $makedependencies_by_arch['aarch64'] = array_filter($makedeps, function($d) { 
                    return $d['system_arch'] === 'aarch64'; 
                });
            }
            if ($v['system_arch'] === 'x86_64') {
                $x86_64_id = $v['id'];
                $deps = $repo->getDependencies($x86_64_id);
                // Filter to only dependencies from the same architecture
                $dependencies_by_arch['x86_64'] = array_filter($deps, function($d) { 
                    return $d['system_arch'] === 'x86_64'; 
                });
                $makedeps = $repo->getMakeDependencies($x86_64_id);
                // Filter to only makedependencies from the same architecture
                $makedependencies_by_arch['x86_64'] = array_filter($makedeps, function($d) { 
                    return $d['system_arch'] === 'x86_64'; 
                });
            }
        }
        if ($aarch64_id && $x86_64_id) {
            $discrepancies = $repo->getPackageDiscrepancies($aarch64_id, $x86_64_id);
        }
    } elseif (count($variants) === 1) {
        // Single variant - get dependencies and makedependencies for it
        $arch = $variants[0]['system_arch'];
        $deps = $repo->getDependencies($variants[0]['id']);
        // Filter to only dependencies from the same architecture
        $dependencies_by_arch[$arch] = array_filter($deps, function($d) use ($arch) { 
            return $d['system_arch'] === $arch; 
        });
        $makedeps = $repo->getMakeDependencies($variants[0]['id']);
        // Filter to only makedependencies from the same architecture
        $makedependencies_by_arch[$arch] = array_filter($makedeps, function($d) use ($arch) { 
            return $d['system_arch'] === $arch; 
        });
    }
} catch (Exception $e) {
    error_log("Error in package-detail.php: " . $e->getMessage(), 3, "/var/log/reporting.log");
    die("An internal error occurred. Please contact support.");
}

if (empty($variants)) {
    die("Package not found: " . htmlspecialchars($pkg_name));
}

Layout::header('Package: ' . htmlspecialchars($pkg_name));
?>

<div class="container">
    <div class="card">
        <h2><?php echo Formatter::escape($pkg_name); ?></h2>
        
        <?php if (count($variants) > 1): ?>
            <p style="margin-bottom: 20px; opacity: 0.8;">Viewing all variants of this package</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px;">
                <?php foreach ($variants as $v): ?>
                <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                    <h3 style="color: #90caf9; margin-bottom: 15px;">
                        <span class="badge badge-info"><?php echo Formatter::escape($v['system_arch']); ?></span>
                    </h3>
                    
                    <table style="font-size: 13px; width: 100%; margin-bottom: 10px;">
                        <tr>
                            <td style="font-weight: bold; width: 35%; padding: 5px;">Version:</td>
                            <td style="padding: 5px;"><?php echo Formatter::escape($v['version']); ?></td>
                        </tr>
                        <tr style="background: #0a0a0a;">
                            <td style="font-weight: bold; padding: 5px;">Repository:</td>
                            <td style="padding: 5px;"><?php echo Formatter::escape($v['repo']); ?></td>
                        </tr>
                        <?php if ($v['base'] && $v['base'] !== $v['name']): ?>
                        <tr>
                            <td style="font-weight: bold; padding: 5px;">Base Package:</td>
                            <td style="padding: 5px;">
                                <a href="<?php echo Formatter::url('report-by-base.php', ['base' => $v['base']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                    <?php echo Formatter::escape($v['base']); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr style="background: #0a0a0a;">
                            <td style="font-weight: bold; padding: 5px;">Architecture:</td>
                            <td style="padding: 5px;"><?php echo Formatter::escape($v['arch']); ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 5px;">URL:</td>
                            <td style="padding: 5px;">
                                <?php if ($v['url']): ?>
                                    <a href="<?php echo Formatter::escape($v['url']); ?>" target="_blank" style="color: #64b5f6;">
                                        <?php echo Formatter::escape($v['url']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr style="background: #0a0a0a;">
                            <td style="font-weight: bold; padding: 5px; vertical-align: top;">Description:</td>
                            <td style="padding: 5px;"><?php echo Formatter::escape($v['description']); ?></td>
                        </tr>
                        <?php 
                        $licenses = $repo->getLicenses($v['id']);
                        if (!empty($licenses)):
                        ?>
                        <tr style="background: #0a0a0a;">
                            <td style="font-weight: bold; padding: 5px;">Licenses:</td>
                            <td style="padding: 5px;">
                                <?php foreach ($licenses as $lic): ?>
                                    <div><?php echo Formatter::escape($lic['name']); ?></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="font-weight: bold; padding: 5px;">Download Size:</td>
                            <td style="padding: 5px;"><?php echo Formatter::size($v['csize']); ?></td>
                        </tr>
                        <tr style="background: #0a0a0a;">
                            <td style="font-weight: bold; padding: 5px;">Installed Size:</td>
                            <td style="padding: 5px;"><?php echo Formatter::size($v['isize']); ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; padding: 5px;">Build Date:</td>
                            <td style="padding: 5px;"><?php echo Formatter::date($v['builddate']); ?></td>
                        </tr>
                        <?php if ($v['packager']): ?>
                        <tr style="background: #0a0a0a;">
                            <td style="font-weight: bold; padding: 5px;">Packager:</td>
                            <td style="padding: 5px;"><?php echo Formatter::escape($v['packager']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <?php 
                    $provides = $repo->getProvides($v['id']);
                    if (!empty($provides)):
                    ?>
                    <div style="margin-top: 10px; padding: 8px; background: #0a0a0a; border-radius: 4px;">
                        <div style="font-size: 11px; font-weight: bold; margin-bottom: 4px; opacity: 0.8;">Provides:</div>
                        <div style="font-size: 11px;">
                            <?php foreach ($provides as $prov): ?>
                                <div style="margin: 2px 0;">• <a href="<?php echo Formatter::url('package-detail.php', ['name' => explode('=', $prov['provides_name'])[0]]); ?>" style="color: #64b5f6; text-decoration: none;"><?php echo Formatter::escape($prov['provides_name']); ?></a></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $conflicts = $repo->getConflicts($v['id']);
                    if (!empty($conflicts)):
                    ?>
                    <div style="margin-top: 8px; padding: 8px; background: #0a0a0a; border-radius: 4px;">
                        <div style="font-size: 11px; font-weight: bold; margin-bottom: 4px; opacity: 0.8;">Conflicts With:</div>
                        <div style="font-size: 11px;">
                            <?php foreach ($conflicts as $conf): ?>
                                <div style="margin: 2px 0;">• <?php echo Formatter::escape($conf['conflicts']); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $groups = $repo->getGroups($v['id']);
                    if (!empty($groups)):
                    ?>
                    <div style="margin-top: 8px; padding: 8px; background: #0a0a0a; border-radius: 4px;">
                        <div style="font-size: 11px; font-weight: bold; margin-bottom: 4px; opacity: 0.8;">Groups:</div>
                        <div style="font-size: 11px;">
                            <?php foreach ($groups as $grp): ?>
                                <div style="margin: 2px 0;">• <?php echo Formatter::escape($grp['name']); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $optdeps = $repo->getOptionalDeps($v['id']);
                    if (!empty($optdeps)):
                    ?>
                    <div style="margin-top: 8px; padding: 8px; background: #0a0a0a; border-radius: 4px;">
                        <div style="font-size: 11px; font-weight: bold; margin-bottom: 4px; opacity: 0.8;">Optional Deps:</div>
                        <div style="font-size: 11px;">
                            <?php foreach ($optdeps as $opt): ?>
                                <div style="margin: 2px 0;">• <a href="<?php echo Formatter::url('package-detail.php', ['name' => $opt['name']]); ?>" style="color: #64b5f6; text-decoration: none;"><?php echo Formatter::escape($opt['name']); ?></a><?php if ($opt['description']): ?> — <?php echo Formatter::escape($opt['description']); ?><?php endif; ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $replaces = $repo->getReplaces($v['id']);
                    if (!empty($replaces)):
                    ?>
                    <div style="margin-top: 8px; padding: 8px; background: #0a0a0a; border-radius: 4px;">
                        <div style="font-size: 11px; font-weight: bold; margin-bottom: 4px; opacity: 0.8;">Replaces:</div>
                        <div style="font-size: 11px;">
                            <?php foreach ($replaces as $rep): ?>
                                <div style="margin: 2px 0;">• <?php echo Formatter::escape($rep['replaces']); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php $v = $variants[0]; ?>
            <table style="width: 100%; margin-top: 20px;">
                <tr>
                    <td style="width: 25%; font-weight: bold; padding: 10px;">Repository:</td>
                    <td style="padding: 10px;"><?php echo Formatter::escape($v['repo']); ?></td>
                </tr>
                <tr style="background: #1a1a1a;">
                    <td style="font-weight: bold; padding: 10px;">Architecture:</td>
                    <td style="padding: 10px;"><?php echo Formatter::escape($v['arch']); ?> (<?php echo Formatter::escape($v['system_arch']); ?>)</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; padding: 10px;">Version:</td>
                    <td style="padding: 10px;"><?php echo Formatter::escape($v['version']); ?></td>
                </tr>
                <tr style="background: #1a1a1a;">
                    <td style="font-weight: bold; padding: 10px;">Base Package:</td>
                    <td style="padding: 10px;">
                        <?php if ($v['base']): ?>
                            <a href="<?php echo Formatter::url('report-by-base.php', ['base' => $v['base']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                <?php echo Formatter::escape($v['base']); ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="font-weight: bold; padding: 10px;">URL:</td>
                    <td style="padding: 10px;">
                        <?php if ($v['url']): ?>
                            <a href="<?php echo Formatter::escape($v['url']); ?>" target="_blank" style="color: #64b5f6;">
                                <?php echo Formatter::escape($v['url']); ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <tr style="background: #1a1a1a;">
                    <td style="font-weight: bold; padding: 10px; vertical-align: top;">Description:</td>
                    <td style="padding: 10px;"><?php echo Formatter::escape($v['description']); ?></td>
                </tr>
                <?php 
                $licenses = $repo->getLicenses($v['id']);
                if (!empty($licenses)):
                ?>
                <tr>
                    <td style="font-weight: bold; padding: 10px;">Licenses:</td>
                    <td style="padding: 10px;">
                        <?php foreach ($licenses as $lic): ?>
                            <div><?php echo Formatter::escape($lic['name']); ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr style="background: #1a1a1a;">
                    <td style="font-weight: bold; padding: 10px;">Download Size:</td>
                    <td style="padding: 10px;"><?php echo Formatter::size($v['csize']); ?></td>
                </tr>
                <tr>
                    <td style="font-weight: bold; padding: 10px;">Installed Size:</td>
                    <td style="padding: 10px;"><?php echo Formatter::size($v['isize']); ?></td>
                </tr>
                <tr style="background: #1a1a1a;">
                    <td style="font-weight: bold; padding: 10px;">Build Date:</td>
                    <td style="padding: 10px;"><?php echo Formatter::date($v['builddate']); ?></td>
                </tr>
                <?php if ($v['packager']): ?>
                <tr>
                    <td style="font-weight: bold; padding: 10px;">Packager:</td>
                    <td style="padding: 10px;"><?php echo Formatter::escape($v['packager']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php 
            $provides = $repo->getProvides($v['id']);
            if (!empty($provides)):
            ?>
            <div style="margin-top: 20px; padding-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
                <h3 style="color: #90caf9; margin-bottom: 10px;">Provides (<?php echo count($provides); ?>)</h3>
                <ul style="list-style: none; padding: 0;">
                    <?php foreach ($provides as $prov): ?>
                        <li style="padding: 8px; border-bottom: 1px solid #333;">
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => explode('=', $prov['provides_name'])[0]]); ?>" style="color: #64b5f6; text-decoration: none;">
                                <?php echo Formatter::escape($prov['provides_name']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php 
            $conflicts = $repo->getConflicts($v['id']);
            if (!empty($conflicts)):
            ?>
            <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
                <h3 style="color: #f57c00; margin-bottom: 10px;">Conflicts With (<?php echo count($conflicts); ?>)</h3>
                <ul style="list-style: none; padding: 0;">
                    <?php foreach ($conflicts as $conf): ?>
                        <li style="padding: 8px; border-bottom: 1px solid #333;">
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $conf['conflicts']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                <?php echo Formatter::escape($conf['conflicts']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php 
            $groups = $repo->getGroups($v['id']);
            if (!empty($groups)):
            ?>
            <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
                <h3 style="color: #90caf9; margin-bottom: 10px;">Groups (<?php echo count($groups); ?>)</h3>
                <ul style="list-style: none; padding: 0;">
                    <?php foreach ($groups as $grp): ?>
                        <li style="padding: 8px; border-bottom: 1px solid #333;">
                            <?php echo Formatter::escape($grp['name']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php 
            $optdeps = $repo->getOptionalDeps($v['id']);
            if (!empty($optdeps)):
            ?>
            <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
                <h3 style="color: #90caf9; margin-bottom: 10px;">Optional Deps (<?php echo count($optdeps); ?>)</h3>
                <ul style="list-style: none; padding: 0;">
                    <?php foreach ($optdeps as $opt): ?>
                        <li style="padding: 8px; border-bottom: 1px solid #333;">
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $opt['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                <?php echo Formatter::escape($opt['name']); ?>
                            </a>
                            <?php if ($opt['description']): ?><div style="font-size: 12px; opacity: 0.8;">— <?php echo Formatter::escape($opt['description']); ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php 
            $replaces = $repo->getReplaces($v['id']);
            if (!empty($replaces)):
            ?>
            <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
                <h3 style="color: #90caf9; margin-bottom: 10px;">Replaces (<?php echo count($replaces); ?>)</h3>
                <ul style="list-style: none; padding: 0;">
                    <?php foreach ($replaces as $rep): ?>
                        <li style="padding: 8px; border-bottom: 1px solid #333;">
                            <a href="<?php echo Formatter::url('package-detail.php', ['name' => $rep['replaces']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                <?php echo Formatter::escape($rep['replaces']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php 
        // Show dependencies section (handles both single and multi-variant)
        if (count($variants) > 1):
            // Multi-variant: Group dependencies by architecture for comparison
            $deps_by_arch = [];
            foreach ($variants as $variant) {
                $variant_deps = $repo->getDependencies($variant['id']);
                $dep_names = array_map(function($d) { return $d['name']; }, $variant_deps);
                $deps_by_arch[$variant['system_arch']] = [
                    'variant' => $variant,
                    'deps' => $variant_deps,
                    'dep_names' => $dep_names
                ];
            }
            
            // Check if dependencies differ
            $arch_list = array_keys($deps_by_arch);
            $deps_differ = count($arch_list) > 1 && $deps_by_arch[$arch_list[0]]['dep_names'] !== $deps_by_arch[$arch_list[1]]['dep_names'];
            
            if ($deps_differ):
                // Show side-by-side comparison in a single table
                ?>
                <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
                    <h3 style="color: #f57c00; margin-bottom: 15px;">Dependencies (Differ by Architecture)</h3>
                    <table style="width: 100%; font-size: 13px;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #444; border-right: 1px solid #333; width: 50%;">x86_64</th>
                                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #444;">aarch64</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Filter dependencies to only include packages from that architecture
                            $x86_deps_raw = $deps_by_arch['x86_64']['deps'] ?? [];
                            $aarch_deps_raw = $deps_by_arch['aarch64']['deps'] ?? [];
                            
                            $x86_deps = array_filter($x86_deps_raw, function($d) { return $d['system_arch'] === 'x86_64'; });
                            $aarch_deps = array_filter($aarch_deps_raw, function($d) { return $d['system_arch'] === 'aarch64'; });
                            
                            // Re-index arrays
                            $x86_deps = array_values($x86_deps);
                            $aarch_deps = array_values($aarch_deps);
                            
                            $max_rows = max(count($x86_deps), count($aarch_deps));
                            
                            for ($i = 0; $i < $max_rows; $i++):
                                $x86_dep = $x86_deps[$i] ?? null;
                                $aarch_dep = $aarch_deps[$i] ?? null;
                            ?>
                            <tr style="<?php echo ($i % 2 == 0) ? 'background: #1a1a1a;' : ''; ?>">
                                <td style="padding: 8px; border-right: 1px solid #333; border-bottom: 1px solid #333; vertical-align: top;">
                                    <?php if ($x86_dep): ?>
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $x86_dep['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($x86_dep['name']); ?>
                                        </a>
                                        <div style="font-size: 11px; opacity: 0.6; margin-top: 2px;"><?php echo Formatter::escape($x86_dep['version']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 8px; border-bottom: 1px solid #333; vertical-align: top;">
                                    <?php if ($aarch_dep): ?>
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $aarch_dep['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($aarch_dep['name']); ?>
                                        </a>
                                        <div style="font-size: 11px; opacity: 0.6; margin-top: 2px;"><?php echo Formatter::escape($aarch_dep['version']); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <?php
            endif;
        else:
            // Single variant: show dependencies
            $deps = $repo->getDependencies($variants[0]['id']);
            if (!empty($deps)):
            ?>
            <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
                <h3 style="color: #90caf9; margin-bottom: 15px;">Dependencies (<?php echo count($deps); ?>)</h3>
                <p style="opacity: 0.8; margin-bottom: 15px; font-size: 13px;">Packages that this package depends on</p>
                <table>
                    <thead>
                        <tr>
                            <th>Package Name</th>
                            <th>Architecture</th>
                            <th>Version</th>
                            <th>Repository</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deps as $dep): ?>
                        <tr>
                            <td>
                                <a href="<?php echo Formatter::url('package-detail.php', ['name' => $dep['name']]); ?>">
                                    <?php echo Formatter::escape($dep['name']); ?>
                                </a>
                            </td>
                            <td><span class="badge badge-info"><?php echo Formatter::escape($dep['system_arch']); ?></span></td>
                            <td><?php echo Formatter::escape($dep['version']); ?></td>
                            <td><?php echo Formatter::escape($dep['repo']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Dependencies Section -->
        <?php if (!empty($dependencies_by_arch)): ?>
        <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
            <h3 style="color: #90caf9; margin-bottom: 15px;">Dependencies</h3>
            <p style="opacity: 0.8; margin-bottom: 15px; font-size: 13px;">Packages that this package depends on</p>
            
            <?php
            $deps_aarch64 = $dependencies_by_arch['aarch64'] ?? [];
            $deps_x86_64 = $dependencies_by_arch['x86_64'] ?? [];
            
            // Compare dependencies: extract just the dependency names for comparison
            $aarch64_dep_names = array_map(function($d) { return $d['dependency']; }, $deps_aarch64);
            $x86_64_dep_names = array_map(function($d) { return $d['dependency']; }, $deps_x86_64);
            sort($aarch64_dep_names);
            sort($x86_64_dep_names);
            $deps_identical = ($aarch64_dep_names === $x86_64_dep_names);
            ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 15px;">
                <?php if ($deps_identical && !empty($deps_aarch64)): ?>
                    <!-- Combined view if identical -->
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px; opacity: 0.8;">
                            Both architectures
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deps_aarch64 as $dep):
                                    $base_name = explode('=', $dep['dependency'])[0];
                                ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $base_name]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($dep['dependency']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo $dep['version'] ? Formatter::escape($dep['version']) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <!-- Separate views if different -->
                    <?php if (!empty($deps_aarch64)): ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px;">
                            <span class="badge badge-info">aarch64</span>
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deps_aarch64 as $dep):
                                    $base_name = explode('=', $dep['dependency'])[0];
                                ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $base_name]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($dep['dependency']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo $dep['version'] ? Formatter::escape($dep['version']) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($deps_x86_64)): ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px;">
                            <span class="badge badge-info">x86_64</span>
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deps_x86_64 as $dep):
                                    $base_name = explode('=', $dep['dependency'])[0];
                                ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $base_name]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($dep['dependency']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo $dep['version'] ? Formatter::escape($dep['version']) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <!-- Make Dependencies Section -->
        <?php if (!empty($makedependencies_by_arch)): ?>
        <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
            <h3 style="color: #90caf9; margin-bottom: 15px;">Make Dependencies</h3>
            <p style="opacity: 0.8; margin-bottom: 15px; font-size: 13px;">Packages needed to build this package</p>
            
            <?php
            $makedeps_aarch64 = $makedependencies_by_arch['aarch64'] ?? [];
            $makedeps_x86_64 = $makedependencies_by_arch['x86_64'] ?? [];
            
            // Compare makedependencies: extract just the makedepend names for comparison
            $aarch64_makedep_names = array_map(function($d) { return $d['makedepend']; }, $makedeps_aarch64);
            $x86_64_makedep_names = array_map(function($d) { return $d['makedepend']; }, $makedeps_x86_64);
            sort($aarch64_makedep_names);
            sort($x86_64_makedep_names);
            $makedeps_identical = ($aarch64_makedep_names === $x86_64_makedep_names);
            ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 15px;">
                <?php if ($makedeps_identical && !empty($makedeps_aarch64)): ?>
                    <!-- Combined view if identical -->
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px; opacity: 0.8;">
                            Both architectures
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($makedeps_aarch64 as $dep):
                                    $base_name = explode('=', $dep['makedepend'])[0];
                                ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $base_name]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($dep['makedepend']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo $dep['version'] ? Formatter::escape($dep['version']) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <!-- Separate views if different -->
                    <?php if (!empty($makedeps_aarch64)): ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px;">
                            <span class="badge badge-info">aarch64</span>
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($makedeps_aarch64 as $dep):
                                    $base_name = explode('=', $dep['makedepend'])[0];
                                ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $base_name]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($dep['makedepend']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo $dep['version'] ? Formatter::escape($dep['version']) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($makedeps_x86_64)): ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px;">
                            <span class="badge badge-info">x86_64</span>
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($makedeps_x86_64 as $dep):
                                    $base_name = explode('=', $dep['makedepend'])[0];
                                ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $base_name]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($dep['makedepend']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo $dep['version'] ? Formatter::escape($dep['version']) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($reverse_deps)): ?>
        <div style="margin-top: 20px; padding: 15px; background: #0a0a0a; border-radius: 8px;">
            <h3 style="color: #90caf9; margin-bottom: 15px;">Reverse Dependencies</h3>
            <p style="opacity: 0.8; margin-bottom: 15px; font-size: 13px;">Packages that depend on this package</p>
            
            <?php
            $reverse_deps_aarch64 = array_filter($reverse_deps, function($d) { return $d['system_arch'] === 'aarch64'; });
            $reverse_deps_x86_64 = array_filter($reverse_deps, function($d) { return $d['system_arch'] === 'x86_64'; });
            
            // Check if reverse deps are identical
            $aarch64_names = array_map(function($d) { return $d['name']; }, $reverse_deps_aarch64);
            $x86_64_names = array_map(function($d) { return $d['name']; }, $reverse_deps_x86_64);
            sort($aarch64_names);
            sort($x86_64_names);
            $reverse_deps_identical = ($aarch64_names === $x86_64_names);
            ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 15px;">
                <?php if ($reverse_deps_identical && !empty($reverse_deps_aarch64)): ?>
                    <!-- Combined view if identical -->
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px; opacity: 0.8;">
                            Both architectures
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reverse_deps_aarch64 as $rdep): ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $rdep['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($rdep['name']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo Formatter::escape($rdep['version']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <!-- Separate views if different -->
                    <?php if (!empty($reverse_deps_aarch64)): ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px;">
                            <span class="badge badge-info">aarch64</span>
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reverse_deps_aarch64 as $rdep): ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $rdep['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($rdep['name']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo Formatter::escape($rdep['version']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($reverse_deps_x86_64)): ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #90caf9; margin-bottom: 15px; font-size: 14px;">
                            <span class="badge badge-info">x86_64</span>
                        </h4>
                        <table style="width: 100%; font-size: 13px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Package</th>
                                    <th style="text-align: left; padding: 5px 0; border-bottom: 1px solid #444;">Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reverse_deps_x86_64 as $rdep): ?>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 5px 0;">
                                        <a href="<?php echo Formatter::url('package-detail.php', ['name' => $rdep['name']]); ?>" style="color: #64b5f6; text-decoration: none;">
                                            <?php echo Formatter::escape($rdep['name']); ?>
                                        </a>
                                    </td>
                                    <td style="padding: 5px 0; font-size: 12px; opacity: 0.7;"><?php echo Formatter::escape($rdep['version']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($discrepancies): ?>
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #333;">
            <h3 style="color: #90caf9; margin-bottom: 20px;">📋 Differences Between Architectures</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <?php 
                // Helper function to render discrepancy sections
                function renderDiscrepancy($title, $aarch64_data, $x86_64_data, $key_field = 'name') {
                    $has_diff = false;
                    
                    // Check if there are differences
                    if (count($aarch64_data) != count($x86_64_data)) {
                        $has_diff = true;
                    } else {
                        $aarch64_keys = array_map(function($item) use ($key_field) { 
                            return $item[$key_field]; 
                        }, $aarch64_data);
                        $x86_64_keys = array_map(function($item) use ($key_field) { 
                            return $item[$key_field]; 
                        }, $x86_64_data);
                        if ($aarch64_keys != $x86_64_keys) {
                            $has_diff = true;
                        }
                    }
                    
                    if ($has_diff):
                    ?>
                    <div style="border: 1px solid #555; border-radius: 8px; padding: 15px; background: #1a1a1a;">
                        <h4 style="color: #ffd93d; margin-bottom: 10px; font-size: 14px;">⚠️ <?php echo $title; ?></h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <div style="font-size: 11px; font-weight: bold; margin-bottom: 8px; color: #90caf9;">aarch64:</div>
                                <div style="font-size: 12px; padding: 8px; background: #0a0a0a; border-radius: 4px; max-height: 150px; overflow-y: auto;">
                                    <?php if (empty($aarch64_data)): ?>
                                        <em style="opacity: 0.5;">—</em>
                                    <?php else: ?>
                                        <?php foreach ($aarch64_data as $item): ?>
                                            <div style="margin: 3px 0;">• <?php echo Formatter::escape($item[$key_field]); ?></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div style="font-size: 11px; font-weight: bold; margin-bottom: 8px; color: #90caf9;">x86_64:</div>
                                <div style="font-size: 12px; padding: 8px; background: #0a0a0a; border-radius: 4px; max-height: 150px; overflow-y: auto;">
                                    <?php if (empty($x86_64_data)): ?>
                                        <em style="opacity: 0.5;">—</em>
                                    <?php else: ?>
                                        <?php foreach ($x86_64_data as $item): ?>
                                            <div style="margin: 3px 0;">• <?php echo Formatter::escape($item[$key_field]); ?></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif;
                }
                
                // Check each type of discrepancy
                renderDiscrepancy('License Differences', $discrepancies['licenses_aarch64'], $discrepancies['licenses_x86_64'], 'name');
                renderDiscrepancy('Provides Differences', $discrepancies['provides_aarch64'], $discrepancies['provides_x86_64'], 'provides_name');
                renderDiscrepancy('Conflicts Differences', $discrepancies['conflicts_aarch64'], $discrepancies['conflicts_x86_64'], 'conflicts');
                renderDiscrepancy('Replaces Differences', $discrepancies['replaces_aarch64'], $discrepancies['replaces_x86_64'], 'replaces');
                renderDiscrepancy('Groups Differences', $discrepancies['groups_aarch64'], $discrepancies['groups_x86_64'], 'name');
                renderDiscrepancy('Optional Dependencies Differences', $discrepancies['optdeps_aarch64'], $discrepancies['optdeps_x86_64'], 'name');
                ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #333;">
            <a href="<?php echo Formatter::url('analysis.php'); ?>" style="color: #64b5f6;">← Back to Analysis</a>
        </div>
    </div>
</div>

<?php Layout::footer();
