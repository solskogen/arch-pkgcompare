<?php
/**
 * Cache warming script — run from CLI after a database import.
 * Pre-computes expensive analysis counts so the first web request is fast.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/PackageRepository.php';
require_once __DIR__ . '/app/Cache.php';

$t = microtime(true);
$cache = new Cache(3600);
$db = Database::getInstance();
$repo = new PackageRepository($db);

$stats = $repo->getStats();
$cache->set('analysis_stats', $stats);

$counts = [
    'repo_diff'     => $repo->countRepoDifferences(),
    'dep_diff'      => $repo->countDependencyDifferences(),
    'provides_diff' => $repo->countProvidesDifferences(),
    'optdep_diff'   => $repo->countOptionalDepDifferences(),
    'makedep_diff'  => $repo->countMakedepDifferences(),
    'group_diff'    => $repo->countGroupDifferences(),
    'conflict_diff' => $repo->countConflictDifferences(),
    'replace_diff'  => $repo->countReplaceDifferences(),
    'cycle_counts'  => $repo->countCyclesByLength(),
];
$cache->set('analysis_counts', $counts);

printf("[Cache] Warmed in %.2fs\n", microtime(true) - $t);
