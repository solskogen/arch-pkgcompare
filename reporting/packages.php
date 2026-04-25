<?php
require_once 'boot.php';

$conn = getDbConnection();

// Get the filter type from URL
$type = $_GET['type'] ?? 'aarch64-only';

// Determine query based on type
if ($type === 'aarch64-only') {
    $result = $conn->query('
        SELECT p.id, p.name, p.version, r.name as repo, p.filename, p.isize, p.base,
               CASE WHEN p.base IS NOT NULL AND p.base != p.name AND 
                    EXISTS (SELECT 1 FROM packages p2 WHERE p2.name = p.base AND p2.system_arch = "x86_64")
                    THEN "provided_by_base" ELSE "true_unique" END as uniqueness_type
        FROM packages p
        JOIN repositories r ON p.repo_id = r.id
        WHERE p.system_arch = "aarch64"
        AND p.name NOT IN (SELECT DISTINCT name FROM packages WHERE system_arch = "x86_64")
        ORDER BY r.name, p.name
    ');
    $page_title = 'aarch64-Only Packages';
    $page_description = 'Packages available only on aarch64';
} elseif ($type === 'x86_64-only') {
    $result = $conn->query('
        SELECT p.id, p.name, p.version, r.name as repo, p.filename, p.isize, p.base,
               CASE WHEN p.base IS NOT NULL AND p.base != p.name AND 
                    EXISTS (SELECT 1 FROM packages p2 WHERE p2.name = p.base AND p2.system_arch = "aarch64")
                    THEN "provided_by_base" ELSE "true_unique" END as uniqueness_type
        FROM packages p
        JOIN repositories r ON p.repo_id = r.id
        WHERE p.system_arch = "x86_64"
        AND p.name NOT IN (SELECT DISTINCT name FROM packages WHERE system_arch = "aarch64")
        ORDER BY r.name, p.name
    ');
    $page_title = 'x86_64-Only Packages';
    $page_description = 'Packages available only on x86_64';
} else {
    $result = null;
    $page_title = 'Packages';
    $page_description = 'Package listings';
}

$packages = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Arch Linux Package Comparison</title>
    <link rel="stylesheet" href="<?php echo baseUrl(); ?>css/style.css">
    <style>
        .back-link { display: inline-block; margin-bottom: 20px; padding: 10px 15px; background: rgba(100, 181, 246, 0.1); border: 1px solid rgba(100, 181, 246, 0.3); border-radius: 4px; color: #64b5f6; text-decoration: none; transition: all 0.3s; }
        .back-link:hover { background: rgba(100, 181, 246, 0.2); }
        .count-badge { background: rgba(100, 181, 246, 0.2); padding: 5px 10px; border-radius: 3px; color: #64b5f6; margin-left: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        thead { background: rgba(100, 181, 246, 0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(100, 181, 246, 0.1); }
        tr:hover { background: rgba(100, 181, 246, 0.05); }
        .pkg-name { color: #64b5f6; font-weight: 500; }
        .repo-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 0.85em; }
        .repo-core { background: rgba(244, 67, 54, 0.2); color: #ef5350; }
        .repo-extra { background: rgba(76, 175, 80, 0.2); color: #66bb6a; }
        .repo-forge { background: rgba(255, 152, 0, 0.2); color: #ffa726; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <a href="<?php echo baseUrl(); ?>">📊 Arch Comparison</a>
            </div>
            <div class="nav-links">
                <a href="<?php echo baseUrl(); ?>">Home</a>
                <a href="<?php echo baseUrl(); ?>comparison.php">Comparison</a>
                <a href="<?php echo baseUrl(); ?>analysis.php">Analysis</a>
            </div>
        </div>
    </nav>

    <main class="container">
        <div class="page-header">
            <h1>📦 <?php echo esc($page_title); ?></h1>
            <p><?php echo esc($page_description); ?></p>
        </div>

        <a href="<?php echo baseUrl(); ?>" class="back-link">← Back to Home</a>

        <div style="margin: 30px 0; padding: 15px; background: rgba(100, 181, 246, 0.1); border-left: 4px solid rgba(100, 181, 246, 0.3); border-radius: 4px;">
            <strong><?php echo count($packages); ?> packages</strong> found
            <br>
            <small style="color: #999; margin-top: 8px; display: block;">
                💡 <em>Some packages listed here are "split packages" of a base package that exists in both architectures.
                For example, gcc-ada is a split package from gcc, meaning the functionality is provided by the gcc package in aarch64.</em>
            </small>
        </div>

        <?php if (count($packages) > 0): ?>
            <div class="packages-table">
                <table>
                    <thead>
                        <tr>
                            <th>Package Name</th>
                            <th>Version</th>
                            <th>Repository</th>
                            <th data-numeric>Size</th>
                            <th>Filename</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $pkg): 
                            $repo_class = 'repo-' . strtolower($pkg['repo']);
                            $is_split = $pkg['uniqueness_type'] === 'provided_by_base';
                        ?>
                        <tr <?php echo $is_split ? 'style="opacity: 0.8;"' : ''; ?>>
                            <td class="pkg-name"><?php echo esc($pkg['name']); ?></td>
                            <td><?php echo esc($pkg['version']); ?></td>
                            <td><span class="repo-badge <?php echo $repo_class; ?>"><?php echo esc($pkg['repo']); ?></span></td>
                            <td data-numeric><?php echo fmtSize($pkg['isize']); ?></td>
                            <td style="font-size: 0.9em; color: #999;"><?php echo esc($pkg['filename']); ?></td>
                            <td style="font-size: 0.85em;">
                                <?php if ($is_split): ?>
                                    <span style="background: rgba(255, 152, 0, 0.2); color: #ffa726; padding: 3px 6px; border-radius: 3px;">
                                        Split of <?php echo esc($pkg['base']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #666;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 20px; background: rgba(76, 175, 80, 0.1); border: 1px solid rgba(76, 175, 80, 0.2); border-radius: 4px; text-align: center; color: #4caf50;">
                ✓ No packages found
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; 2026 Arch Linux Package Comparison Tool. Data from official Arch repositories.</p>
    </footer>
</body>
</html>
