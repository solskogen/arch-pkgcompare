<?php
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Helpers.php';

try {
    $db = Database::getInstance();
    $repo = new PackageRepository($db);
    $comparison = $repo->getPerRepoComparison();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

Layout::header('Repository Package Comparison');
?>

<div class="container">
    <div class="card">
        <h2>📚 Per-Repository Package Comparison</h2>
        <p style="margin-bottom: 20px; opacity: 0.8;">Comparing package availability across repositories (core, extra, forge) between aarch64 and x86_64</p>

        <?php if (empty($comparison)): ?>
            <div class="alert alert-info">No repository data found.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Repository</th>
                        <th>x86_64 Count</th>
                        <th>aarch64 Count</th>
                        <th>x86_64 Only</th>
                        <th>aarch64 Only</th>
                        <th>In Both</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($comparison as $c): ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo Formatter::escape($c['repo']); ?></td>
                        <td><?php echo Formatter::number($c['x86_64_count']); ?></td>
                        <td><?php echo Formatter::number($c['aarch64_count']); ?></td>
                        <td>
                            <?php if ($c['x86_64_only'] > 0): ?>
                                <a href="<?php echo Formatter::url('report-repo-x86_64-only.php', ['repo' => $c['repo']]); ?>" style="color: #ff9800;">
                                    <?php echo Formatter::number($c['x86_64_only']); ?>
                                </a>
                            <?php else: ?>
                                <span style="opacity: 0.5;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['aarch64_only'] > 0): ?>
                                <a href="<?php echo Formatter::url('report-repo-aarch64-only.php', ['repo' => $c['repo']]); ?>" style="color: #4caf50;">
                                    <?php echo Formatter::number($c['aarch64_only']); ?>
                                </a>
                            <?php else: ?>
                                <span style="opacity: 0.5;">0</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-success"><?php echo Formatter::number($c['in_both']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 30px; padding: 15px; background: #1a1a1a; border-radius: 8px; border-left: 4px solid #2196f3;">
                <h3 style="color: #64b5f6; margin-bottom: 10px;">📋 Details by Repository</h3>
                <p style="font-size: 13px; opacity: 0.8; margin-bottom: 15px;">
                    Click on the package counts to view detailed listings of packages that differ between architectures.
                </p>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                    <?php foreach ($comparison as $c): ?>
                        <?php if ($c['repo'] != 'forge'): ?>
                        <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; background: #252525;">
                            <h4 style="color: #90caf9; margin-bottom: 10px; text-transform: capitalize;">
                                <?php echo Formatter::escape($c['repo']); ?> Repository
                            </h4>
                            <table style="width: 100%; font-size: 12px;">
                                <tr>
                                    <td style="padding: 5px;">x86_64 packages:</td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo Formatter::number($c['x86_64_count']); ?></td>
                                </tr>
                                <tr style="background: rgba(255,255,255,0.02);">
                                    <td style="padding: 5px;">aarch64 packages:</td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo Formatter::number($c['aarch64_count']); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; color: #ff9800;">Missing in aarch64:</td>
                                    <td style="text-align: right; color: #ff9800; font-weight: 600;">
                                        <?php echo Formatter::number($c['x86_64_only']); ?>
                                        <?php if ($c['x86_64_only'] > 0): ?>
                                            <a href="<?php echo Formatter::url('report-repo-x86_64-only.php', ['repo' => $c['repo']]); ?>" title="View">↗</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr style="background: rgba(255,255,255,0.02);">
                                    <td style="padding: 5px; color: #4caf50;">Missing in x86_64:</td>
                                    <td style="text-align: right; color: #4caf50; font-weight: 600;">
                                        <?php echo Formatter::number($c['aarch64_only']); ?>
                                        <?php if ($c['aarch64_only'] > 0): ?>
                                            <a href="<?php echo Formatter::url('report-repo-aarch64-only.php', ['repo' => $c['repo']]); ?>" title="View">↗</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer();
