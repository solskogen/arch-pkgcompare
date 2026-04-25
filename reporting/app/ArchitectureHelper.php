<?php
/**
 * Helper class for architecture-agnostic operations
 * Manages dynamic architecture handling for multi-architecture comparisons
 */
class ArchitectureHelper {
    private static $architectures = null;
    private static $repo = null;

    public static function init(PackageRepository $repo) {
        self::$repo = $repo;
        self::$architectures = $repo->getArchitectures();
    }

    /**
     * Get all configured architectures
     * Returns array of architecture names
     */
    public static function getAll() {
        if (self::$architectures === null && self::$repo === null) {
            throw new Exception("ArchitectureHelper not initialized. Call init() first.");
        }
        return self::$architectures;
    }

    /**
     * Get the first architecture (typically the "primary" one for single-arch-only reports)
     */
    public static function getFirst() {
        $archs = self::getAll();
        return $archs[0] ?? null;
    }

    /**
     * Get the second architecture (typically the "comparison" one)
     */
    public static function getSecond() {
        $archs = self::getAll();
        return $archs[1] ?? null;
    }

    /**
     * Get list of architectures excluding the given one
     */
    public static function getAllExcept($arch) {
        $all = self::getAll();
        return array_filter($all, function($a) use ($arch) {
            return $a !== $arch;
        });
    }

    /**
     * Get the "other" architecture when comparing two
     * Useful when you have one architecture and want the comparison target
     */
    public static function getOther($current_arch) {
        $archs = self::getAll();
        
        // If exactly 2 architectures configured, return the other one
        if (count($archs) == 2) {
            return $archs[0] === $current_arch ? $archs[1] : $archs[0];
        }
        
        // For more than 2, this is ambiguous, so throw error
        throw new Exception(
            "Cannot determine 'other' architecture with " . count($archs) . 
            " architectures configured. Use getAll() instead."
        );
    }

    /**
     * Check if system has exactly 2 architectures configured
     */
    public static function isBinary() {
        return count(self::getAll()) === 2;
    }

    /**
     * Get count of configured architectures
     */
    public static function count() {
        return count(self::getAll());
    }

    /**
     * Generate description text for comparing architectures
     */
    public static function getComparisonText() {
        $archs = self::getAll();
        
        if (count($archs) == 2) {
            return "Comparing " . htmlspecialchars($archs[0]) . " and " . 
                   htmlspecialchars($archs[1]) . " architectures";
        } else {
            return "Comparing " . count($archs) . " architectures: " . 
                   htmlspecialchars(implode(", ", $archs));
        }
    }

    /**
     * Get header text for reports showing packages only in one architecture
     */
    public static function getOnlyInText($arch) {
        return "Packages available in " . htmlspecialchars($arch) . 
               " but not in other configured architectures";
    }

    /**
     * Get dynamic table header for multi-architecture comparison
     * Returns array of column definitions for the primary architectures
     */
    public static function getComparisonColumns() {
        $archs = self::getAll();
        $columns = [];
        
        foreach ($archs as $arch) {
            $columns[$arch] = [
                'version_col' => $arch . '_version',
                'repo_col' => $arch . '_repo',
                'id_col' => $arch . '_id'
            ];
        }
        
        return $columns;
    }
}
