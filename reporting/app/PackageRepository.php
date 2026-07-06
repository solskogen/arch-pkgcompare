<?php
/**
 * Repository pattern for package queries
 * Centralizes all package-related database operations
 */
class PackageRepository {
    private $db;
    private $architectures_cache = null;
    public $primaryArch;    // first system arch alphabetically (e.g. 'aarch64')
    public $referenceArch;  // second system arch alphabetically (e.g. 'x86_64')

    public function __construct(Database $db) {
        $this->db = $db;
        $this->loadSystemArchitectures();
    }

    /**
     * Load the two system architectures from the packages table.
     * Falls back to aarch64/x86_64 if the DB is empty.
     */
    private function loadSystemArchitectures() {
        $result = $this->db->query(
            "SELECT DISTINCT system_arch FROM packages ORDER BY system_arch ASC LIMIT 2"
        );
        $archs = [];
        while ($row = $result->fetch_assoc()) {
            $archs[] = $row['system_arch'];
        }
        $this->primaryArch   = $archs[0] ?? 'aarch64';
        $this->referenceArch = $archs[1] ?? 'x86_64';
    }

    /**
     * Get all configured architectures from database
     * Returns array of architecture names ordered alphabetically
     */
    public function getArchitectures() {
        if ($this->architectures_cache !== null) {
            return $this->architectures_cache;
        }
        
        $sql = "SELECT name FROM architectures ORDER BY name ASC";
        $result = $this->db->query($sql);
        $archs = [];
        
        while ($row = $result->fetch_assoc()) {
            $archs[] = $row['name'];
        }
        
        $this->architectures_cache = $archs;
        return $archs;
    }

    /**
     * Get single package by name with all details
     */
    public function getByName($name) {
        $sql = "
            SELECT 
                p.id, p.name, p.version, p.description, p.url, 
                p.builddate, p.csize, p.isize,
                a.name as arch, r.name as repo, p.system_arch,
                GROUP_CONCAT(DISTINCT l.name SEPARATOR ', ') as licenses,
                GROUP_CONCAT(DISTINCT pp.provides_name SEPARATOR ', ') as provides,
                GROUP_CONCAT(DISTINCT d.dependency SEPARATOR ', ') as depends
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            LEFT JOIN package_licenses pl ON p.id = pl.package_id
            LEFT JOIN licenses l ON pl.license_id = l.id
            LEFT JOIN package_provides pp ON p.id = pp.package_id
            LEFT JOIN package_depends d ON p.id = d.package_id
            WHERE p.name = ?
            GROUP BY p.id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /**
     * Get all variants of a package (different archs)
     */
    public function getVariants($name) {
        $sql = "
            SELECT 
                p.id, p.name, p.version, p.base, p.arch_id, a.name as arch, 
                p.system_arch, r.name as repo, p.csize, p.isize,
                p.builddate, p.url, p.description, p.packager
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE p.name = ?
            ORDER BY p.system_arch ASC, a.name
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        return $data;
    }

    /**
     * Get mismatches (split packages) between architectures
     */
    public function getMismatches() {
        $sql = "
            SELECT DISTINCT 
                a.name,
                a.base as aarch64_base,
                x.base as x86_64_base,
                a.id as aarch64_id,
                x.id as x86_64_id
            FROM packages a
            INNER JOIN packages x ON a.name = x.name
            WHERE a.system_arch = 'aarch64'
            AND x.system_arch = 'x86_64'
            AND a.base IS NOT NULL AND a.base != ''
            AND x.base IS NOT NULL AND x.base != ''
            AND a.base != x.base
            ORDER BY a.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get x86_64-only packages
     */
    public function getX86_64Only() {
        $sql = "
            SELECT DISTINCT 
                p.id, p.name, p.version, a.name as arch, 
                r.name as repo, p.csize, p.isize
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE p.system_arch = 'x86_64'
            AND p.name NOT IN (SELECT DISTINCT name FROM packages WHERE system_arch = 'aarch64')
            ORDER BY p.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get x86_64-only packages, excluding those provided by aarch64
     */
    public function getX86_64OnlyNotProvided() {
        $sql = "
            SELECT DISTINCT 
                p.id, p.name, p.version, a.name as arch, 
                r.name as repo, p.csize, p.isize, p.base as pkgbase
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE p.system_arch = 'x86_64'
            AND p.name NOT IN (
                SELECT DISTINCT name FROM packages WHERE system_arch = 'aarch64'
            )
            AND p.name NOT IN (
                SELECT DISTINCT 
                    SUBSTRING_INDEX(pp.provides_name, '=', 1) as provides_pkg
                FROM package_provides pp
                INNER JOIN packages pkg ON pp.package_id = pkg.id
                WHERE pkg.system_arch = 'aarch64'
            )
            ORDER BY p.base, p.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get x86_64-only packages grouped by pkgbase with completeness info
     */
    public function getX86_64OnlyGroupedByPkgbase() {
        $sql = "
            SELECT DISTINCT 
                p.id, p.name, p.version, a.name as arch, 
                r.name as repo, p.base as pkgbase
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE p.system_arch = 'x86_64'
            AND p.name NOT IN (
                SELECT DISTINCT name FROM packages WHERE system_arch = 'aarch64'
            )
            AND p.name NOT IN (
                SELECT DISTINCT 
                    SUBSTRING_INDEX(pp.provides_name, '=', 1) as provides_pkg
                FROM package_provides pp
                INNER JOIN packages pkg ON pp.package_id = pkg.id
                WHERE pkg.system_arch = 'aarch64'
            )
            ORDER BY p.base, p.name
        ";
        
        $packages = $this->db->fetchAll($sql);
        
        // Organize by pkgbase and check if aarch64 has any variants
        $grouped = [];
        foreach ($packages as $pkg) {
            $base = $pkg['pkgbase'];
            if (!isset($grouped[$base])) {
                $grouped[$base] = [
                    'packages' => [],
                    'has_aarch64' => false
                ];
            }
            $grouped[$base]['packages'][] = $pkg;
        }
        
        // Check which pkgbases have aarch64 versions
        foreach (array_keys($grouped) as $base) {
            $check_sql = "
                SELECT COUNT(*) as cnt FROM packages 
                WHERE base = %s AND system_arch = 'aarch64'
            ";
            $result = $this->db->fetchOne(sprintf($check_sql, "'" . $this->db->escape($base) . "'"));
            $grouped[$base]['has_aarch64'] = ($result['cnt'] > 0);
        }
        
        return $grouped;
    }

    /**
     * Count x86_64-only packages not provided by aarch64
     */
    public function countX86_64OnlyNotProvided() {
        $sql = "
            SELECT COUNT(DISTINCT p.id) as count
            FROM packages p
            WHERE p.system_arch = 'x86_64'
            AND p.name NOT IN (
                SELECT DISTINCT name FROM packages WHERE system_arch = 'aarch64'
            )
            AND p.name NOT IN (
                SELECT DISTINCT 
                    SUBSTRING_INDEX(pp.provides_name, '=', 1) as provides_pkg
                FROM package_provides pp
                INNER JOIN packages pkg ON pp.package_id = pkg.id
                WHERE pkg.system_arch = 'aarch64'
            )
        ";
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }

    /**
     * Get aarch64-only packages
     */
    public function getAarch64Only() {
        $sql = "
            SELECT DISTINCT 
                p.id, p.name, p.version, a.name as arch, 
                r.name as repo, p.csize, p.isize
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE p.system_arch = 'aarch64'
            AND p.name NOT IN (SELECT DISTINCT name FROM packages WHERE system_arch = 'x86_64')
            ORDER BY p.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get packages newer in aarch64 than x86_64
     */
    public function getAarch64Newer() {
        $sql = "
            SELECT DISTINCT 
                a.name, a.version as aarch64_version, x.version as x86_64_version,
                a.id as aarch64_id, x.id as x86_64_id
            FROM packages a
            INNER JOIN packages x ON a.name = x.name
            WHERE a.system_arch = 'aarch64' 
            AND x.system_arch = 'x86_64'
            ORDER BY a.name
        ";
        $results = $this->db->fetchAll($sql);
        
        // Filter using PHP version_compare for accurate Semantic Versioning comparison
        return array_filter($results, function($pkg) {
            return version_compare($pkg['aarch64_version'], $pkg['x86_64_version'], '>');
        });
    }

    /**
     * Get packages newer in x86_64 than aarch64
     */
    public function getX86_64Newer() {
        $sql = "
            SELECT DISTINCT 
                x.name, x.version as x86_64_version, a.version as aarch64_version,
                x.id as x86_64_id, a.id as aarch64_id
            FROM packages x
            INNER JOIN packages a ON x.name = a.name
            WHERE x.system_arch = 'x86_64' 
            AND a.system_arch = 'aarch64'
            ORDER BY x.name
        ";
        $results = $this->db->fetchAll($sql);
        
        // Filter using PHP version_compare for accurate Semantic Versioning comparison
        return array_filter($results, function($pkg) {
            return version_compare($pkg['x86_64_version'], $pkg['aarch64_version'], '>');
        });
    }

    /**
     * Get outdated 'any' (universal) packages in aarch64
     */
    public function getOutdatedAnyPackages() {
        $sql = "
            SELECT DISTINCT 
                a.name, a.version as aarch64_version, x.version as x86_64_version
            FROM packages a
            JOIN architectures arch_a ON a.arch_id = arch_a.id
            INNER JOIN packages x ON a.name = x.name
            JOIN architectures arch_x ON x.arch_id = arch_x.id
            WHERE a.system_arch = 'aarch64' 
            AND arch_a.name = 'any'
            AND x.system_arch = 'x86_64' 
            AND arch_x.name = 'any'
            AND a.version < x.version
            ORDER BY a.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get missing 'any' (universal) packages in aarch64
     */
    public function getMissingAnyPackages() {
        $sql = "
            SELECT DISTINCT 
                x.name, x.version as x86_64_version
            FROM packages x
            JOIN architectures arch ON x.arch_id = arch.id
            LEFT JOIN packages a ON x.name = a.name AND a.system_arch = 'aarch64'
            WHERE x.system_arch = 'x86_64' 
            AND arch.name = 'any'
            AND a.id IS NULL
            ORDER BY x.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get orphaned split packages
     */
    public function getOrphanedSplitPackages() {
        $sql = "
            SELECT DISTINCT 
                p.id, p.name, p.version, p.base,
                a.name as arch, r.name as repo, 
                p.system_arch
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE p.system_arch = 'aarch64'
            AND p.name != p.base
            AND p.base NOT IN (
                SELECT DISTINCT name FROM packages 
                WHERE system_arch = 'aarch64'
            )
            AND p.base IN (
                SELECT DISTINCT name FROM packages 
                WHERE system_arch = 'x86_64'
            )
            ORDER BY p.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get repository differences (core vs extra)
     */
    public function getRepoDifferences() {
        $sql = "
            SELECT DISTINCT 
                a.name as pkg_name,
                a.repo as aarch64_repo,
                x.repo as x86_64_repo
            FROM (
                SELECT DISTINCT p.name, r.name as repo 
                FROM packages p
                JOIN repositories r ON p.repo_id = r.id
                WHERE p.system_arch = 'aarch64'
            ) a
            INNER JOIN (
                SELECT DISTINCT p.name, r.name as repo 
                FROM packages p
                JOIN repositories r ON p.repo_id = r.id
                WHERE p.system_arch = 'x86_64'
            ) x ON a.name = x.name
            WHERE a.repo != x.repo
            ORDER BY a.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get per-repository package differences (e.g., core x86_64 vs core aarch64)
     */
    public function getPerRepoComparison() {
        $sql = "
            SELECT 
                r.name as repo,
                SUM(CASE WHEN arch = 'x86_64' THEN 1 ELSE 0 END) as x86_64_count,
                SUM(CASE WHEN arch = 'aarch64' THEN 1 ELSE 0 END) as aarch64_count,
                SUM(CASE WHEN arch = 'x86_64' AND NOT EXISTS (SELECT 1 FROM packages p2 WHERE p2.name = pkg_name AND p2.repo_id = r.id AND p2.system_arch = 'aarch64') THEN 1 ELSE 0 END) as x86_64_only,
                SUM(CASE WHEN arch = 'aarch64' AND NOT EXISTS (SELECT 1 FROM packages p2 WHERE p2.name = pkg_name AND p2.repo_id = r.id AND p2.system_arch = 'x86_64') THEN 1 ELSE 0 END) as aarch64_only,
                SUM(CASE WHEN arch = 'x86_64' AND EXISTS (SELECT 1 FROM packages p2 WHERE p2.name = pkg_name AND p2.repo_id = r.id AND p2.system_arch = 'aarch64') THEN 1 ELSE 0 END) as in_both
            FROM (
                SELECT r.id, r.name, p.name as pkg_name, p.system_arch as arch, p.repo_id
                FROM packages p
                INNER JOIN repositories r ON p.repo_id = r.id
            ) sub
            INNER JOIN repositories r ON sub.repo_id = r.id
            GROUP BY r.id, r.name
            ORDER BY r.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get packages only in a specific repo on x86_64 (not in same repo on aarch64)
     */
    /**
     * Get dashboard statistics
     */
    public function getStats() {
        return [
            'total_packages' => $this->db->fetchOne(
                "SELECT COUNT(DISTINCT name) as count FROM packages"
            )['count'],
            'aarch64_packages' => $this->db->fetchOne(
                "SELECT COUNT(DISTINCT name) as count FROM packages WHERE system_arch = 'aarch64'"
            )['count'],
            'x86_64_packages' => $this->db->fetchOne(
                "SELECT COUNT(DISTINCT name) as count FROM packages WHERE system_arch = 'x86_64'"
            )['count'],
            'aarch64_size_mb' => round(
                $this->db->fetchOne("SELECT SUM(csize) as total FROM packages WHERE system_arch = 'aarch64'")['total'] / (1024 * 1024),
                1
            ),
            'x86_64_size_mb' => round(
                $this->db->fetchOne("SELECT SUM(csize) as total FROM packages WHERE system_arch = 'x86_64'")['total'] / (1024 * 1024),
                1
            ),
            'mismatches_count' => $this->countMismatches(),
            'x86_64_only_count' => $this->countX86_64Only(),
            'x86_64_only_not_provided_count' => $this->countX86_64OnlyNotProvided(),
            'aarch64_only_count' => $this->countAarch64Only(),
            'aarch64_newer_count' => $this->countAarch64Newer(),
            'x86_64_newer_count' => $this->countX86_64Newer(),
            'outdated_count' => $this->countOutdated(),
            'outdated_non_any_count' => $this->countOutdatedNonAny(),
            'outdated_any_count' => $this->countOutdatedAny(),
            'license_discrepancies_count' => $this->countLicenseDiscrepancies(),
            'missing_any_count' => $this->countMissingAnyPackages(),
            'any_diff_count' => $this->countAnyPackageDifferences(),
            'repo_diff_list_count' => $this->countRepoDifferencesList(),
            'orphaned_count' => $this->countOrphanedSplitPackages(),
            'size_diff_count' => $this->countSizeDifferences(),
        ];
    }

    private function countMismatches() {
        return $this->db->fetchOne("
            SELECT COUNT(DISTINCT a.name) as count FROM packages a
            INNER JOIN packages x ON a.name = x.name
            WHERE a.system_arch = 'aarch64'
            AND x.system_arch = 'x86_64'
            AND a.base IS NOT NULL AND a.base != ''
            AND x.base IS NOT NULL AND x.base != ''
            AND a.base != x.base
        ")['count'];
    }

    private function countX86_64Only() {
        return $this->db->fetchOne("
            SELECT COUNT(DISTINCT name) as count FROM packages 
            WHERE system_arch = 'x86_64'
            AND name NOT IN (SELECT DISTINCT name FROM packages WHERE system_arch = 'aarch64')
        ")['count'];
    }

    private function countAarch64Only() {
        return $this->db->fetchOne("
            SELECT COUNT(DISTINCT name) as count FROM packages 
            WHERE system_arch = 'aarch64'
            AND name NOT IN (SELECT DISTINCT name FROM packages WHERE system_arch = 'x86_64')
        ")['count'];
    }

    private function countAarch64Newer() {
        return count($this->getAarch64Newer());
    }

    private function countX86_64Newer() {
        return count($this->getX86_64Newer());
    }

    private function countMissingAnyPackages() {
        return $this->db->fetchOne("
            SELECT COUNT(DISTINCT x.name) as count
            FROM packages x
            JOIN architectures arch ON x.arch_id = arch.id
            LEFT JOIN packages a ON x.name = a.name AND a.system_arch = '{$this->primaryArch}'
            WHERE x.system_arch = '{$this->referenceArch}'
            AND arch.name = 'any'
            AND a.id IS NULL
        ")['count'] ?? 0;
    }

    private function countAnyPackageDifferences() {
        return $this->db->fetchOne("
            SELECT
                (SELECT COUNT(DISTINCT p.name)
                 FROM packages p
                 JOIN architectures a ON p.arch_id = a.id
                 WHERE a.name = 'any' AND p.system_arch = '{$this->primaryArch}'
                   AND p.name NOT IN (
                       SELECT DISTINCT p2.name FROM packages p2
                       JOIN architectures a2 ON p2.arch_id = a2.id
                       WHERE a2.name = 'any' AND p2.system_arch = '{$this->referenceArch}'
                   )
                )
                +
                (SELECT COUNT(DISTINCT p.name)
                 FROM packages p
                 JOIN architectures a ON p.arch_id = a.id
                 WHERE a.name = 'any' AND p.system_arch = '{$this->referenceArch}'
                   AND p.name NOT IN (
                       SELECT DISTINCT p2.name FROM packages p2
                       JOIN architectures a2 ON p2.arch_id = a2.id
                       WHERE a2.name = 'any' AND p2.system_arch = '{$this->primaryArch}'
                   )
                ) AS count
        ")['count'] ?? 0;
    }

    private function countRepoDifferencesList() {
        return $this->db->fetchOne("
            SELECT COUNT(DISTINCT a.name) as count FROM (
                SELECT DISTINCT p.name, r.name as repo
                FROM packages p JOIN repositories r ON p.repo_id = r.id
                WHERE p.system_arch = '{$this->primaryArch}'
            ) a
            INNER JOIN (
                SELECT DISTINCT p.name, r.name as repo
                FROM packages p JOIN repositories r ON p.repo_id = r.id
                WHERE p.system_arch = '{$this->referenceArch}'
            ) x ON a.name = x.name
            WHERE a.repo != x.repo
        ")['count'] ?? 0;
    }

    private function countOrphanedSplitPackages() {
        return $this->db->fetchOne("
            SELECT COUNT(DISTINCT p.name) as count
            FROM packages p
            WHERE p.system_arch = '{$this->primaryArch}'
            AND p.name != p.base
            AND p.base IS NOT NULL
            AND p.base NOT IN (
                SELECT DISTINCT name FROM packages WHERE system_arch = '{$this->primaryArch}'
            )
            AND p.base IN (
                SELECT DISTINCT name FROM packages WHERE system_arch = '{$this->referenceArch}'
            )
        ")['count'] ?? 0;
    }

    private function countSizeDifferences() {
        // Matches the 10 MB filter applied in report-size-differences.php
        $minBytes = 10 * 1024 * 1024;
        return $this->db->fetchOne("
            SELECT COUNT(*) as count FROM (
                SELECT p1.name
                FROM packages p1
                INNER JOIN packages p2 ON p1.name = p2.name
                WHERE p1.system_arch = '{$this->primaryArch}'
                AND p2.system_arch = '{$this->referenceArch}'
                AND (p1.isize >= {$minBytes} OR p2.isize >= {$minBytes})
            ) t
        ")['count'] ?? 0;
    }

    private function countOutdated() {
        $all_packages = $this->db->fetchAll("
            SELECT DISTINCT 
                a.name, a.version as aarch64_version, x.version as x86_64_version
            FROM packages a
            INNER JOIN packages x ON a.name = x.name
            WHERE a.system_arch = 'aarch64' AND x.system_arch = 'x86_64'
        ");
        
        $outdated = array_filter($all_packages, function($pkg) {
            // Count if versions differ (either one is older than the other)
            return version_compare($pkg['aarch64_version'], $pkg['x86_64_version'], '!=');
        });
        
        return count($outdated);
    }

    private function countOutdatedNonAny() {
        $all_packages = $this->db->fetchAll("
            SELECT DISTINCT 
                a.name, a.version as aarch64_version, x.version as x86_64_version
            FROM packages a
            INNER JOIN architectures arch_a ON a.arch_id = arch_a.id
            INNER JOIN packages x ON a.name = x.name
            INNER JOIN architectures arch_x ON x.arch_id = arch_x.id
            WHERE a.system_arch = 'aarch64' AND x.system_arch = 'x86_64'
            AND arch_a.name != 'any' AND arch_x.name != 'any'
        ");
        
        $outdated = array_filter($all_packages, function($pkg) {
            return version_compare($pkg['aarch64_version'], $pkg['x86_64_version'], '!=');
        });
        
        return count($outdated);
    }

    private function countOutdatedAny() {
        return count($this->getOutdatedAnyPackages());
    }

    /**
     * Count packages with different licenses on different architectures
     * Only counts packages where BOTH architectures have license data
     */
    public function countLicenseDiscrepancies() {
        return $this->db->fetchOne("
            SELECT COUNT(*) as count FROM (
                SELECT DISTINCT a.name FROM packages a
                INNER JOIN packages x ON a.name = x.name
                LEFT JOIN package_licenses apl ON a.id = apl.package_id
                LEFT JOIN licenses al ON apl.license_id = al.id
                LEFT JOIN package_licenses xpl ON x.id = xpl.package_id
                LEFT JOIN licenses xl ON xpl.license_id = xl.id
                WHERE a.system_arch = 'aarch64' AND x.system_arch = 'x86_64'
                AND EXISTS (SELECT 1 FROM package_licenses WHERE package_id = a.id)
                AND EXISTS (SELECT 1 FROM package_licenses WHERE package_id = x.id)
                GROUP BY a.id, x.id
                HAVING COALESCE(GROUP_CONCAT(DISTINCT al.id ORDER BY al.id SEPARATOR ','), '') 
                       != COALESCE(GROUP_CONCAT(DISTINCT xl.id ORDER BY xl.id SEPARATOR ','), '')
            ) t
        ")['count'] ?? 0;
    }

    /**
     * Get license discrepancies (packages with different licenses on different architectures)
     * Only returns packages where BOTH architectures have license data
     */
    public function getAllDiscrepancies() {
        $sql = "
            SELECT a.name, a.id as aarch64_id, x.id as x86_64_id,
                   a.version as aarch64_version, x.version as x86_64_version,
                   ar.name as aarch64_repo, xr.name as x86_64_repo,
                   COALESCE(GROUP_CONCAT(DISTINCT al.name ORDER BY al.name SEPARATOR ', '), '(none)') as aarch64_licenses,
                   COALESCE(GROUP_CONCAT(DISTINCT xl.name ORDER BY xl.name SEPARATOR ', '), '(none)') as x86_64_licenses
            FROM packages a
            INNER JOIN packages x ON a.name = x.name
            INNER JOIN repositories ar ON a.repo_id = ar.id
            INNER JOIN repositories xr ON x.repo_id = xr.id
            LEFT JOIN package_licenses apl ON a.id = apl.package_id
            LEFT JOIN licenses al ON apl.license_id = al.id
            LEFT JOIN package_licenses xpl ON x.id = xpl.package_id
            LEFT JOIN licenses xl ON xpl.license_id = xl.id
            WHERE a.system_arch = 'aarch64' AND x.system_arch = 'x86_64'
            AND EXISTS (SELECT 1 FROM package_licenses WHERE package_id = a.id)
            AND EXISTS (SELECT 1 FROM package_licenses WHERE package_id = x.id)
            GROUP BY a.id, x.id
            HAVING COALESCE(GROUP_CONCAT(DISTINCT al.id ORDER BY al.id SEPARATOR ','), '') 
                   != COALESCE(GROUP_CONCAT(DISTINCT xl.id ORDER BY xl.id SEPARATOR ','), '')
            ORDER BY a.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get detailed discrepancies for a specific package pair
     */
    public function getPackageDiscrepancies($aarch64_id, $x86_64_id) {
        return [
            'licenses_aarch64' => $this->getLicenses($aarch64_id),
            'licenses_x86_64' => $this->getLicenses($x86_64_id),
            'provides_aarch64' => $this->getProvides($aarch64_id),
            'provides_x86_64' => $this->getProvides($x86_64_id),
            'conflicts_aarch64' => $this->getConflicts($aarch64_id),
            'conflicts_x86_64' => $this->getConflicts($x86_64_id),
            'replaces_aarch64' => $this->getReplaces($aarch64_id),
            'replaces_x86_64' => $this->getReplaces($x86_64_id),
            'groups_aarch64' => $this->getGroups($aarch64_id),
            'groups_x86_64' => $this->getGroups($x86_64_id),
            'optdeps_aarch64' => $this->getOptionalDeps($aarch64_id),
            'optdeps_x86_64' => $this->getOptionalDeps($x86_64_id),
        ];
    }

    /**
     * Get all dependencies for a package
     */
    public function getDependencies($packageId) {
        // First get the architecture of the package we're fetching dependencies for
        $pkg_sql = "SELECT system_arch FROM packages WHERE id = %d";
        $pkg_result = $this->db->fetchAll(sprintf($pkg_sql, $packageId));
        if (empty($pkg_result)) {
            return [];
        }
        $pkg_arch = $pkg_result[0]['system_arch'];
        
        // Now fetch dependencies for this package, matching only from the same architecture
        $sql = "
            SELECT DISTINCT 
                pd.dependency,
                p.id, p.name, p.version, p.system_arch,
                a.name as arch, r.name as repo
            FROM package_depends pd
            LEFT JOIN packages p ON p.name = SUBSTRING_INDEX(pd.dependency, '=', 1) 
                AND p.system_arch = %s
            LEFT JOIN architectures a ON p.arch_id = a.id
            LEFT JOIN repositories r ON p.repo_id = r.id
            WHERE pd.package_id = %d
            ORDER BY pd.dependency
        ";
        $escaped_arch = $this->db->escape($pkg_arch);
        return $this->db->fetchAll(sprintf($sql, "'" . $escaped_arch . "'", $packageId));
    }

    /**
     * Get make dependencies (build-time dependencies)
     */
    public function getMakeDependencies($packageId) {
        // First get the architecture of the package we're fetching dependencies for
        $pkg_sql = "SELECT system_arch FROM packages WHERE id = %d";
        $pkg_result = $this->db->fetchAll(sprintf($pkg_sql, $packageId));
        if (empty($pkg_result)) {
            return [];
        }
        $pkg_arch = $pkg_result[0]['system_arch'];
        
        // Now fetch makedependencies for this package, matching only from the same architecture
        $sql = "
            SELECT DISTINCT 
                pmd.makedepend,
                p.id, p.name, p.version, p.system_arch,
                a.name as arch, r.name as repo
            FROM package_makedepends pmd
            LEFT JOIN packages p ON p.name = SUBSTRING_INDEX(pmd.makedepend, '=', 1) 
                AND p.system_arch = %s
            LEFT JOIN architectures a ON p.arch_id = a.id
            LEFT JOIN repositories r ON p.repo_id = r.id
            WHERE pmd.package_id = %d
            ORDER BY pmd.makedepend
        ";
        $escaped_arch = $this->db->escape($pkg_arch);
        return $this->db->fetchAll(sprintf($sql, "'" . $escaped_arch . "'", $packageId));
    }

    /**
     * Get all reverse dependencies (packages that depend on this package)
     */
    public function getReverseDependencies($packageName) {
        $sql = "
            SELECT DISTINCT 
                p.id, p.name, p.version, p.system_arch,
                a.name as arch, r.name as repo
            FROM packages p
            INNER JOIN package_depends pd ON p.id = pd.package_id
            INNER JOIN architectures a ON p.arch_id = a.id
            INNER JOIN repositories r ON p.repo_id = r.id
            WHERE pd.dependency LIKE ?
            ORDER BY p.name, p.system_arch
        ";
        $stmt = $this->db->prepare($sql);
        $search = '%' . $packageName . '%';
        $stmt->bind_param("s", $search);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        return $data;
    }
    /**
     * Get licenses for a package
     */
    public function getLicenses($packageId) {
        $sql = "
            SELECT DISTINCT l.name
            FROM package_licenses pl
            INNER JOIN licenses l ON pl.license_id = l.id
            WHERE pl.package_id = %d
            ORDER BY l.name
        ";
        return $this->db->fetchAll(sprintf($sql, $packageId));
    }

    /**
     * Get provides for a package
     */
    public function getProvides($packageId) {
        $sql = "
            SELECT DISTINCT provides_name
            FROM package_provides
            WHERE package_id = %d
            ORDER BY provides_name
        ";
        return $this->db->fetchAll(sprintf($sql, $packageId));
    }

    /**
     * Get conflicts for a package
     */
    public function getConflicts($packageId) {
        $sql = "
            SELECT DISTINCT conflicts
            FROM package_conflicts
            WHERE package_id = %d
            ORDER BY conflicts
        ";
        return $this->db->fetchAll(sprintf($sql, $packageId));
    }

    /**
     * Get replaces for a package
     */
    public function getReplaces($packageId) {
        $sql = "
            SELECT DISTINCT replaces
            FROM package_replaces
            WHERE package_id = %d
            ORDER BY replaces
        ";
        return $this->db->fetchAll(sprintf($sql, $packageId));
    }

    /**
     * Get groups for a package
     */
    public function getGroups($packageId) {
        $sql = "
            SELECT DISTINCT g.name
            FROM package_groups pg
            INNER JOIN groups g ON pg.group_id = g.id
            WHERE pg.package_id = %d
            ORDER BY g.name
        ";
        return $this->db->fetchAll(sprintf($sql, $packageId));
    }

    /**
     * Get optional dependencies for a package
     */
    public function getOptionalDeps($packageId) {
        $sql = "
            SELECT DISTINCT od.name, pod.description
            FROM package_optional_deps pod
            INNER JOIN optional_deps od ON pod.optional_dep_id = od.id
            WHERE pod.package_id = %d
            ORDER BY od.name
        ";
        return $this->db->fetchAll(sprintf($sql, $packageId));
    }

    /**
     * Get all packages with a specific base package
     */
    public function getPackagesByBase($baseName) {
        $sql = "
            SELECT 
                p.id, p.name, p.version, p.system_arch, p.base,
                a.name as arch, r.name as repo,
                p.csize, p.isize, p.packager
            FROM packages p
            INNER JOIN architectures a ON p.arch_id = a.id
            INNER JOIN repositories r ON p.repo_id = r.id
            WHERE p.base = ?
            ORDER BY p.name, p.system_arch
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $baseName);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        return $data;
    }

    public function getPackagesByArch($systemArch) {
        $systemArch = $this->db->escape($systemArch);
        $sql = "
            SELECT 
                p.name, p.version, r.name as repo, p.csize, p.isize,
                a.name as arch
            FROM packages p
            INNER JOIN repositories r ON p.repo_id = r.id
            INNER JOIN architectures a ON p.arch_id = a.id
            WHERE p.system_arch = '$systemArch'
            GROUP BY p.name
            ORDER BY r.name, p.name
        ";
        return $this->db->fetchAll($sql);
    }


    /**
     * Get packages with size differences between architectures
     */
    public function getSizeDifferences() {
        $sql = "
            SELECT 
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                p1.csize as aarch64_csize,
                p2.csize as x86_64_csize,
                p1.isize as aarch64_isize,
                p2.isize as x86_64_isize,
                (p2.csize - p1.csize) as csize_diff,
                (p2.isize - p1.isize) as isize_diff,
                r1.name as aarch64_repo,
                r2.name as x86_64_repo
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            JOIN repositories r1 ON p1.repo_id = r1.id
            JOIN repositories r2 ON p2.repo_id = r2.id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get -any packages (architecture-independent) differences
     */
    public function getAnyPackageDifferences() {
        $sql = "
            SELECT 
                'aarch64_only' as type,
                p.name,
                p.version,
                r.name as repo,
                p.csize,
                p.isize
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE a.name = 'any'
            AND p.system_arch = 'aarch64'
            AND p.name NOT IN (
                SELECT DISTINCT p2.name FROM packages p2
                JOIN architectures a2 ON p2.arch_id = a2.id
                WHERE a2.name = 'any' AND p2.system_arch = 'x86_64'
            )
            UNION ALL
            SELECT 
                'x86_64_only' as type,
                p.name,
                p.version,
                r.name as repo,
                p.csize,
                p.isize
            FROM packages p
            JOIN architectures a ON p.arch_id = a.id
            JOIN repositories r ON p.repo_id = r.id
            WHERE a.name = 'any'
            AND p.system_arch = 'x86_64'
            AND p.name NOT IN (
                SELECT DISTINCT p2.name FROM packages p2
                JOIN architectures a2 ON p2.arch_id = a2.id
                WHERE a2.name = 'any' AND p2.system_arch = 'aarch64'
            )
            ORDER BY type, name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get packages with repository mismatches (different repos between archs)
     * Shows packages where one arch has core but the other has extra, etc.
     */
    public function getRepoDifferencesDetailed() {
        $sql = "
            SELECT DISTINCT 
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                r1.name as aarch64_repo,
                r2.name as x86_64_repo,
                p1.id as aarch64_id,
                p2.id as x86_64_id
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            JOIN repositories r1 ON p1.repo_id = r1.id
            JOIN repositories r2 ON p2.repo_id = r2.id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            AND r1.name != r2.name
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get packages with different dependencies between architectures
     * Only returns packages where dependencies actually differ
     */
    public function getPackagesWithDependencyDifferences() {
        $sql = "
            SELECT DISTINCT
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                p1.id as aarch64_id,
                p2.id as x86_64_id,
                GROUP_CONCAT(DISTINCT ad.dependency SEPARATOR ', ') as aarch64_deps,
                GROUP_CONCAT(DISTINCT xd.dependency SEPARATOR ', ') as x86_64_deps
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_depends ad ON p1.id = ad.package_id
            LEFT JOIN package_depends xd ON p2.id = xd.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.id, p2.id
            HAVING GROUP_CONCAT(DISTINCT ad.dependency SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT xd.dependency SEPARATOR ', ')
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count packages with repository mismatches
     */
    public function countRepoDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT p1.name) as count
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            JOIN repositories r1 ON p1.repo_id = r1.id
            JOIN repositories r2 ON p2.repo_id = r2.id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            AND r1.name != r2.name
        ";
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }

    /**
     * Count packages with dependency differences
     */
    public function countDependencyDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT pkg_name) FROM (
                SELECT p1.name AS pkg_name
                FROM package_depends pd1
                JOIN packages p1 ON pd1.package_id = p1.id AND p1.system_arch = 'aarch64'
                JOIN packages p2 ON p1.name = p2.name AND p2.system_arch = 'x86_64'
                LEFT JOIN package_depends pd2 ON pd2.package_id = p2.id AND pd2.dependency = pd1.dependency
                WHERE pd2.package_id IS NULL
                UNION ALL
                SELECT p2.name AS pkg_name
                FROM package_depends pd2
                JOIN packages p2 ON pd2.package_id = p2.id AND p2.system_arch = 'x86_64'
                JOIN packages p1 ON p2.name = p1.name AND p1.system_arch = 'aarch64'
                LEFT JOIN package_depends pd1 ON pd1.package_id = p1.id AND pd1.dependency = pd2.dependency
                WHERE pd1.package_id IS NULL
            ) t
        ";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['COUNT(DISTINCT pkg_name)'] ?? 0;
    }

    /**
     * Get packages with provides (virtual package) differences
     */
    public function getPackagesWithProvidesDifferences() {
        $sql = "
            SELECT DISTINCT
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                GROUP_CONCAT(DISTINCT pp1.provides_name SEPARATOR ', ') as aarch64_provides,
                GROUP_CONCAT(DISTINCT pp2.provides_name SEPARATOR ', ') as x86_64_provides
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_provides pp1 ON p1.id = pp1.package_id
            LEFT JOIN package_provides pp2 ON p2.id = pp2.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.id, p2.id
            HAVING GROUP_CONCAT(DISTINCT pp1.provides_name SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT pp2.provides_name SEPARATOR ', ')
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count packages with provides differences
     */
    public function countProvidesDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT pkg_name) FROM (
                SELECT p1.name AS pkg_name
                FROM package_provides pp1
                JOIN packages p1 ON pp1.package_id = p1.id AND p1.system_arch = 'aarch64'
                JOIN packages p2 ON p1.name = p2.name AND p2.system_arch = 'x86_64'
                LEFT JOIN package_provides pp2 ON pp2.package_id = p2.id AND pp2.provides_name = pp1.provides_name
                WHERE pp2.package_id IS NULL
                UNION ALL
                SELECT p2.name AS pkg_name
                FROM package_provides pp2
                JOIN packages p2 ON pp2.package_id = p2.id AND p2.system_arch = 'x86_64'
                JOIN packages p1 ON p2.name = p1.name AND p1.system_arch = 'aarch64'
                LEFT JOIN package_provides pp1 ON pp1.package_id = p1.id AND pp1.provides_name = pp2.provides_name
                WHERE pp1.package_id IS NULL
            ) t
        ";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        return $row['COUNT(DISTINCT pkg_name)'] ?? 0;
    }

    /**
     * Get packages with optional dependency differences
     */
    public function getPackagesWithOptionalDepDifferences() {
        $sql = "
            SELECT DISTINCT
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                GROUP_CONCAT(DISTINCT od1.name SEPARATOR ', ') as aarch64_optdeps,
                GROUP_CONCAT(DISTINCT od2.name SEPARATOR ', ') as x86_64_optdeps
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_optional_deps pod1 ON p1.id = pod1.package_id
            LEFT JOIN optional_deps od1 ON pod1.optional_dep_id = od1.id
            LEFT JOIN package_optional_deps pod2 ON p2.id = pod2.package_id
            LEFT JOIN optional_deps od2 ON pod2.optional_dep_id = od2.id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.id, p2.id
            HAVING GROUP_CONCAT(DISTINCT od1.name SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT od2.name SEPARATOR ', ')
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count packages with optional dependency differences
     */
    public function countOptionalDepDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT p1.name) as count
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_optional_deps pod1 ON p1.id = pod1.package_id
            LEFT JOIN optional_deps od1 ON pod1.optional_dep_id = od1.id
            LEFT JOIN package_optional_deps pod2 ON p2.id = pod2.package_id
            LEFT JOIN optional_deps od2 ON pod2.optional_dep_id = od2.id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.name
            HAVING GROUP_CONCAT(DISTINCT od1.name SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT od2.name SEPARATOR ', ')
        ";
        $result = $this->db->query("SELECT COUNT(*) as total FROM (" . $sql . ") t");
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * Get packages with makedepend differences
     */
    public function getPackagesWithMakedepDifferences() {
        $sql = "
            SELECT DISTINCT
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                GROUP_CONCAT(DISTINCT pm1.makedepend SEPARATOR ', ') as aarch64_makedeps,
                GROUP_CONCAT(DISTINCT pm2.makedepend SEPARATOR ', ') as x86_64_makedeps
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_makedepends pm1 ON p1.id = pm1.package_id
            LEFT JOIN package_makedepends pm2 ON p2.id = pm2.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.id, p2.id
            HAVING GROUP_CONCAT(DISTINCT pm1.makedepend SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT pm2.makedepend SEPARATOR ', ')
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count packages with makedepend differences
     */
    public function countMakedepDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT p1.name) as count
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_makedepends pm1 ON p1.id = pm1.package_id
            LEFT JOIN package_makedepends pm2 ON p2.id = pm2.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.name
            HAVING GROUP_CONCAT(DISTINCT pm1.makedepend SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT pm2.makedepend SEPARATOR ', ')
        ";
        $result = $this->db->query("SELECT COUNT(*) as total FROM (" . $sql . ") t");
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * Get packages with group membership differences
     */
    public function getPackagesWithGroupDifferences() {
        $sql = "
            SELECT DISTINCT
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                GROUP_CONCAT(DISTINCT g1.name SEPARATOR ', ') as aarch64_groups,
                GROUP_CONCAT(DISTINCT g2.name SEPARATOR ', ') as x86_64_groups
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_groups pg1 ON p1.id = pg1.package_id
            LEFT JOIN groups g1 ON pg1.group_id = g1.id
            LEFT JOIN package_groups pg2 ON p2.id = pg2.package_id
            LEFT JOIN groups g2 ON pg2.group_id = g2.id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.id, p2.id
            HAVING GROUP_CONCAT(DISTINCT g1.name SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT g2.name SEPARATOR ', ')
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count packages with group membership differences
     */
    public function countGroupDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT p1.name) as count
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_groups pg1 ON p1.id = pg1.package_id
            LEFT JOIN groups g1 ON pg1.group_id = g1.id
            LEFT JOIN package_groups pg2 ON p2.id = pg2.package_id
            LEFT JOIN groups g2 ON pg2.group_id = g2.id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.name
            HAVING GROUP_CONCAT(DISTINCT g1.name SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT g2.name SEPARATOR ', ')
        ";
        $result = $this->db->query("SELECT COUNT(*) as total FROM (" . $sql . ") t");
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * Get packages with conflict differences
     */
    public function getPackagesWithConflictDifferences() {
        $sql = "
            SELECT DISTINCT
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                GROUP_CONCAT(DISTINCT pc1.conflicts SEPARATOR ', ') as aarch64_conflicts,
                GROUP_CONCAT(DISTINCT pc2.conflicts SEPARATOR ', ') as x86_64_conflicts
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_conflicts pc1 ON p1.id = pc1.package_id
            LEFT JOIN package_conflicts pc2 ON p2.id = pc2.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.id, p2.id
            HAVING GROUP_CONCAT(DISTINCT pc1.conflicts SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT pc2.conflicts SEPARATOR ', ')
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count packages with conflict differences
     */
    public function countConflictDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT p1.name) as count
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_conflicts pc1 ON p1.id = pc1.package_id
            LEFT JOIN package_conflicts pc2 ON p2.id = pc2.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.name
            HAVING GROUP_CONCAT(DISTINCT pc1.conflicts SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT pc2.conflicts SEPARATOR ', ')
        ";
        $result = $this->db->query("SELECT COUNT(*) as total FROM (" . $sql . ") t");
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * Get packages with replace differences
     */
    public function getPackagesWithReplaceDifferences() {
        $sql = "
            SELECT DISTINCT
                p1.name,
                p1.version as aarch64_version,
                p2.version as x86_64_version,
                GROUP_CONCAT(DISTINCT pr1.replaces SEPARATOR ', ') as aarch64_replaces,
                GROUP_CONCAT(DISTINCT pr2.replaces SEPARATOR ', ') as x86_64_replaces
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_replaces pr1 ON p1.id = pr1.package_id
            LEFT JOIN package_replaces pr2 ON p2.id = pr2.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.id, p2.id
            HAVING GROUP_CONCAT(DISTINCT pr1.replaces SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT pr2.replaces SEPARATOR ', ')
            ORDER BY p1.name ASC
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count packages with replace differences
     */
    public function countReplaceDifferences() {
        $sql = "
            SELECT COUNT(DISTINCT p1.name) as count
            FROM packages p1
            INNER JOIN packages p2 ON p1.name = p2.name
            LEFT JOIN package_replaces pr1 ON p1.id = pr1.package_id
            LEFT JOIN package_replaces pr2 ON p2.id = pr2.package_id
            WHERE p1.system_arch = 'aarch64'
            AND p2.system_arch = 'x86_64'
            GROUP BY p1.name
            HAVING GROUP_CONCAT(DISTINCT pr1.replaces SEPARATOR ', ') != 
                   GROUP_CONCAT(DISTINCT pr2.replaces SEPARATOR ', ')
        ";
        $result = $this->db->query("SELECT COUNT(*) as total FROM (" . $sql . ") t");
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    }

    /**
     * Get all circular dependencies (cycles) where A depends on B and B depends on A
     */
    public function getCircularDependencies() {
        $sql = "
            SELECT DISTINCT
                p1.name as package_a,
                d1.dependency as package_b,
                p1.system_arch
            FROM packages p1
            JOIN package_depends d1 ON p1.id = d1.package_id
            JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = p1.system_arch
            JOIN package_depends d2 ON p2.id = d2.package_id
            WHERE d2.dependency = p1.name
            AND p1.system_arch IN ('aarch64', 'x86_64')
            AND p1.name < d1.dependency
            ORDER BY p1.system_arch, p1.name, d1.dependency
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Count circular dependencies per architecture
     */
    public function countCircularDependencies($arch = null) {
        if ($arch) {
            $sql = "
                SELECT COUNT(DISTINCT CONCAT(p1.name, ':', d1.dependency)) as count
                FROM packages p1
                JOIN package_depends d1 ON p1.id = d1.package_id
                JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = ?
                JOIN package_depends d2 ON p2.id = d2.package_id
                WHERE d2.dependency = p1.name
                AND p1.system_arch = ?
                AND p1.name < d1.dependency
            ";
            $result = $this->db->query($sql);
            if ($result === false) return 0;
            $row = $result->fetch_assoc();
            return $row['count'] ?? 0;
        } else {
            $sql = "
                SELECT 
                    p1.system_arch,
                    COUNT(DISTINCT CONCAT(p1.name, ':', d1.dependency)) as count
                FROM packages p1
                JOIN package_depends d1 ON p1.id = d1.package_id
                JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = p1.system_arch
                JOIN package_depends d2 ON p2.id = d2.package_id
                WHERE d2.dependency = p1.name
                AND p1.name < d1.dependency
                GROUP BY p1.system_arch
            ";
            return $this->db->fetchAll($sql);
        }
    }

    /**
     * Get unique packages involved in circular dependencies
     */
    public function getPackagesInCircles($arch = null) {
        $where = $arch ? "AND p1.system_arch = '" . $this->db->real_escape_string($arch) . "'" : "";
        
        $sql = "
            SELECT DISTINCT
                p1.system_arch,
                p1.name as package,
                COUNT(DISTINCT d1.dependency) as cycle_count
            FROM packages p1
            JOIN package_depends d1 ON p1.id = d1.package_id
            JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = p1.system_arch
            JOIN package_depends d2 ON p2.id = d2.package_id
            WHERE d2.dependency = p1.name
            $where
            GROUP BY p1.system_arch, p1.name
            ORDER BY p1.system_arch, cycle_count DESC, p1.name
        ";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get all cycles grouped by cycle length (2-way, 3-way, etc.)
     * Returns cycles organized by length with all packages in the cycle
     * Excludes cycles where all packages come from the same pkgbase
     */
    public function getAllCyclesByLength() {
        $cycles = [];
        
        // Get 2-way cycles (excluding same-pkgbase cycles)
        $sql2 = "
            SELECT DISTINCT
                p1.name as package_a,
                d1.dependency as package_b,
                p1.system_arch,
                2 as cycle_length
            FROM packages p1
            JOIN package_depends d1 ON p1.id = d1.package_id
            JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = p1.system_arch
            JOIN package_depends d2 ON p2.id = d2.package_id
            WHERE d2.dependency = p1.name
            AND p1.system_arch IN ('aarch64', 'x86_64')
            AND p1.name < d1.dependency
            AND (p1.base IS NULL OR p2.base IS NULL OR p1.base != p2.base)
            ORDER BY p1.system_arch, p1.name, d1.dependency
        ";
        $cycles['2'] = $this->db->fetchAll($sql2);
        
        // Get 3-way cycles (excluding same-pkgbase cycles)
        $sql3 = "
            SELECT DISTINCT
                CONCAT(p1.name, ' → ', d1.dependency, ' → ', d2.dependency, ' → ', p1.name) as cycle_path,
                p1.name as pkg1,
                d1.dependency as pkg2,
                d2.dependency as pkg3,
                p1.system_arch,
                3 as cycle_length
            FROM packages p1
            JOIN package_depends d1 ON p1.id = d1.package_id
            JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = p1.system_arch
            JOIN package_depends d2 ON p2.id = d2.package_id
            JOIN packages p3 ON p3.name = d2.dependency AND p3.system_arch = p1.system_arch
            JOIN package_depends d3 ON p3.id = d3.package_id
            WHERE d3.dependency = p1.name
            AND p1.name < d1.dependency AND d1.dependency < d2.dependency
            AND p1.name != d1.dependency
            AND d1.dependency != d2.dependency
            AND d2.dependency != p1.name
            AND NOT (p1.base IS NOT NULL AND p2.base IS NOT NULL AND p3.base IS NOT NULL AND p1.base = p2.base AND p2.base = p3.base)
            ORDER BY p1.system_arch, p1.name, d1.dependency, d2.dependency
        ";
        $cycles['3'] = $this->db->fetchAll($sql3);
        
        return $cycles;
    }

    /**
     * Count cycles by length
     */
    public function countCyclesByLength() {
        $counts = [];
        
        // 2-way cycles
        $sql2 = "
            SELECT COUNT(DISTINCT CONCAT(p1.name, ':', d1.dependency)) as count
            FROM packages p1
            JOIN package_depends d1 ON p1.id = d1.package_id
            JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = p1.system_arch
            JOIN package_depends d2 ON p2.id = d2.package_id
            WHERE d2.dependency = p1.name
            AND p1.name < d1.dependency
            AND (p1.base IS NULL OR p2.base IS NULL OR p1.base != p2.base)
        ";
        $result = $this->db->query($sql2);
        $row = $result->fetch_assoc();
        $counts['2'] = $row['count'] ?? 0;
        
        // 3-way cycles (excluding same-pkgbase)
        $sql3 = "
            SELECT COUNT(DISTINCT CONCAT(p1.name, ':', d1.dependency, ':', d2.dependency)) as count
            FROM packages p1
            JOIN package_depends d1 ON p1.id = d1.package_id
            JOIN packages p2 ON p2.name = d1.dependency AND p2.system_arch = p1.system_arch
            JOIN package_depends d2 ON p2.id = d2.package_id
            JOIN packages p3 ON p3.name = d2.dependency AND p3.system_arch = p1.system_arch
            JOIN package_depends d3 ON p3.id = d3.package_id
            WHERE d3.dependency = p1.name
            AND p1.name < d1.dependency AND d1.dependency < d2.dependency
            AND p1.name != d1.dependency
            AND d1.dependency != d2.dependency
            AND d2.dependency != p1.name
            AND NOT (p1.base IS NOT NULL AND p2.base IS NOT NULL AND p3.base IS NOT NULL AND p1.base = p2.base AND p2.base = p3.base)
        ";
        $result = $this->db->query($sql3);
        $row = $result->fetch_assoc();
        $counts['3'] = $row['count'] ?? 0;
        
        return $counts;
    }
    
    /**
     * Get cycles consolidated by their content (not per-architecture)
     * Merges cycles that exist on both architectures and shows which archs they affect
     */
    public function getCyclesConsolidated() {
        $allCycles = $this->getAllCyclesByLength();
        $consolidated = [];
        
        foreach ($allCycles as $length => $cycles) {
            if (empty($cycles)) continue;
            
            $seen = [];
            foreach ($cycles as $cycle) {
                if ($length == 2) {
                    // For 2-way cycles: create key from sorted package names
                    $packages = [$cycle['package_a'], $cycle['package_b']];
                    sort($packages);
                    $key = implode('|', $packages);
                    
                    if (!isset($seen[$key])) {
                        $seen[$key] = [
                            'package_a' => $packages[0],
                            'package_b' => $packages[1],
                            'architectures' => [],
                            'cycle_length' => 2
                        ];
                    }
                    if (!in_array($cycle['system_arch'], $seen[$key]['architectures'])) {
                        $seen[$key]['architectures'][] = $cycle['system_arch'];
                    }
                } else {
                    // For 3+ way cycles: create key from sorted package names
                    $packages = [$cycle['pkg1'], $cycle['pkg2'], $cycle['pkg3']];
                    sort($packages);
                    $key = implode('|', $packages);
                    
                    if (!isset($seen[$key])) {
                        $seen[$key] = [
                            'pkg1' => $cycle['pkg1'],
                            'pkg2' => $cycle['pkg2'],
                            'pkg3' => $cycle['pkg3'],
                            'cycle_path' => $cycle['cycle_path'],
                            'architectures' => [],
                            'cycle_length' => $length
                        ];
                    }
                    if (!in_array($cycle['system_arch'], $seen[$key]['architectures'])) {
                        $seen[$key]['architectures'][] = $cycle['system_arch'];
                    }
                }
            }
            
            $consolidated[$length] = array_values($seen);
            // Sort architectures for consistency
            foreach ($consolidated[$length] as $key => $cycle) {
                sort($consolidated[$length][$key]['architectures']);
            }
        }
        
        return $consolidated;
    }
}
