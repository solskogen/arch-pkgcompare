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

function getAnalysisCacheVersion($db) {
    $result = $db->query("SELECT id, import_timestamp FROM import_metadata ORDER BY id DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return 'analysis-v2:' . $row['id'] . ':' . $row['import_timestamp'];
    }
    return 'analysis-v2:bootstrap';
}

$version = getAnalysisCacheVersion($db);
$statsKey = 'analysis_stats:' . $version;
$countsKeyA = 'analysis_counts_a:' . $version;
$countsKeyB = 'analysis_counts_b:' . $version;
$countsKeyC = 'analysis_counts_c:' . $version;
$segment = $argv[1] ?? 'all';

switch ($segment) {
    case 'stats':
        $cache->set($statsKey, $repo->getStats());
        break;
    case 'counts-a':
        $cache->set($countsKeyA, [
            'repo_diff' => $repo->countRepoDifferences(),
            'dep_diff'  => $repo->countDependencyDifferences(),
        ]);
        break;
    case 'counts-b':
        $cache->set($countsKeyB, [
            'provides_diff' => $repo->countProvidesDifferences(),
            'optdep_diff'   => $repo->countOptionalDepDifferences(),
            'makedep_diff'  => $repo->countMakedepDifferences(),
        ]);
        break;
    case 'counts-c':
        $cache->set($countsKeyC, [
            'group_diff'    => $repo->countGroupDifferences(),
            'conflict_diff' => $repo->countConflictDifferences(),
            'replace_diff'  => $repo->countReplaceDifferences(),
            'cycle_counts'  => $repo->countCyclesByLength(),
        ]);
        break;
    case 'all':
        $cache->set($statsKey, $repo->getStats());
        $cache->set($countsKeyA, [
            'repo_diff' => $repo->countRepoDifferences(),
            'dep_diff'  => $repo->countDependencyDifferences(),
        ]);
        $cache->set($countsKeyB, [
            'provides_diff' => $repo->countProvidesDifferences(),
            'optdep_diff'   => $repo->countOptionalDepDifferences(),
            'makedep_diff'  => $repo->countMakedepDifferences(),
        ]);
        $cache->set($countsKeyC, [
            'group_diff'    => $repo->countGroupDifferences(),
            'conflict_diff' => $repo->countConflictDifferences(),
            'replace_diff'  => $repo->countReplaceDifferences(),
            'cycle_counts'  => $repo->countCyclesByLength(),
        ]);
        break;
    default:
        fwrite(STDERR, "Unknown segment: {$segment}\n");
        exit(1);
}

printf("[Cache] Warmed in %.2fs\n", microtime(true) - $t);
