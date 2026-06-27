#!/usr/bin/env python3
"""
Script to download Arch Linux package databases (multi-architecture), 
extract metadata, and insert into MySQL using batch operations.

Performance optimizations:
- Batch inserts instead of row-by-row
- Pre-load all IDs into memory
- Reduce database roundtrips
- Use load_data_infile where possible
- Connection pooling
- Parallel downloads
"""

import tarfile
import gzip
import os
import sys
import MySQLdb
import configparser
import argparse
from urllib.request import urlopen
from typing import Dict, List, Optional, Tuple
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
import time

# Load configuration from config.ini
CONFIG = configparser.ConfigParser()
CONFIG_PATH = os.path.join(os.path.dirname(__file__), 'config.ini')

if not os.path.exists(CONFIG_PATH):
    print(f"❌ Error: Configuration file not found at {CONFIG_PATH}", file=sys.stderr)
    print("Please create config.ini in the project root directory.", file=sys.stderr)
    sys.exit(1)

try:
    CONFIG.read(CONFIG_PATH)
except Exception as e:
    print(f"❌ Error reading config.ini: {e}", file=sys.stderr)
    sys.exit(1)

# Database configuration (from config.ini, with environment variable overrides)
MYSQL_CONFIG = {
    'host': os.getenv('DB_HOST', CONFIG.get('database', 'host')),
    'user': os.getenv('DB_USER', CONFIG.get('database', 'user')),
    'passwd': os.getenv('DB_PASS', CONFIG.get('database', 'password')),
    'db': os.getenv('DB_NAME', CONFIG.get('database', 'database')),
}

# Repository URLs configuration (dynamically read from [arch-*] sections)
# Each [arch-*] section defines repositories for that architecture
def load_architectures_from_config():
    """Load architectures and their repository URLs from config.ini.
    
    Supports both formats:
    1. Direct URLs: core = https://mirror.example.com/core/os/arch/core.db
    2. Template URLs: url_template = {mirror}/{repo}/os/{arch}/{repo}.db
    """
    architectures = {}
    for section in CONFIG.sections():
        if section.startswith('arch-'):
            arch_name = section[5:]  # Remove 'arch-' prefix
            
            # Get all config items for this architecture
            config_items = dict(CONFIG.items(section))
            
            # Check if using template format
            if 'url_template' in config_items and 'repos' in config_items:
                # Template-based configuration
                url_template = config_items['url_template']
                repos_list = [r.strip() for r in config_items['repos'].split(',')]
                
                # Get mirror if specified (for {mirror} placeholder)
                mirror = config_items.get('mirror', '')
                
                repos = {}
                for repo_name in repos_list:
                    # Replace placeholders
                    url = url_template.replace('{mirror}', mirror)
                    url = url.replace('{repo}', repo_name)
                    url = url.replace('{arch}', arch_name)
                    repos[repo_name] = url
            else:
                # Direct URL configuration (backward compatible)
                repos = {k: v for k, v in config_items.items() 
                        if k not in ['mirror', 'url_template', 'repos']}
            
            architectures[arch_name] = repos
    
    return architectures

DB_URLS = load_architectures_from_config()

# Validate that we have exactly 2 architectures configured
if len(DB_URLS) != 2:
    print("❌ Error: Exactly 2 architectures must be configured in config.ini", file=sys.stderr)
    print("   This system performs binary comparison between two architectures", file=sys.stderr)
    print("   Define exactly 2 [arch-*] sections (e.g., [arch-aarch64], [arch-x86_64])", file=sys.stderr)
    sys.exit(1)

print(f"📦 Loaded {len(DB_URLS)} architectures: {', '.join(sorted(DB_URLS.keys()))}", file=sys.stderr)
for arch, repos in sorted(DB_URLS.items()):
    print(f"   {arch}: {', '.join(sorted(repos.keys()))}", file=sys.stderr)


# Batch size for inserts
BATCH_SIZE = CONFIG.getint('loader', 'batch_size', fallback=5000)
PARALLEL_DOWNLOADS = CONFIG.getint('loader', 'parallel_downloads', fallback=5)
DOWNLOAD_TIMEOUT = CONFIG.getint('loader', 'timeout', fallback=30)

LOADER_TABLES = [
    'packages',
    'package_depends',
    'package_provides',
    'package_licenses',
    'package_groups',
    'package_optional_deps',
    'repositories',
    'architectures',
    'licenses',
    'groups',
    'optional_deps',
    'import_metadata',
]

RELATION_TABLES = [
    'package_depends',
    'package_provides',
    'package_licenses',
    'package_groups',
    'package_optional_deps',
]


def parse_desc_file(content: str) -> Dict[str, any]:
    """Parse a desc file from the tar archive into a dictionary."""
    result = {}
    lines = content.strip().split('\n')
    current_key = None
    current_values = []

    SINGLE_VALUE_FIELDS = {'NAME', 'BASE', 'VERSION', 'DESC', 'CSIZE', 'ISIZE', 
                           'SHA256SUM', 'PGPSIG', 'URL', 'ARCH', 'BUILDDATE', 
                           'PACKAGER', 'FILENAME'}

    for line in lines:
        if line.startswith('%') and line.endswith('%'):
            if current_key:
                if current_key in SINGLE_VALUE_FIELDS or len(current_values) == 1:
                    result[current_key] = current_values[0] if current_values else None
                else:
                    result[current_key] = current_values
            current_key = line.strip('%')
            current_values = []
        elif current_key and line.strip():
            current_values.append(line.strip())

    if current_key:
        if current_key in SINGLE_VALUE_FIELDS or len(current_values) == 1:
            result[current_key] = current_values[0] if current_values else None
        else:
            result[current_key] = current_values

    return result


def get_list(pkg: Dict, key: str) -> List[str]:
    """Return a package field as a list, normalizing str → [str]."""
    val = pkg.get(key, [])
    return [val] if isinstance(val, str) else val


def batch_executemany(cursor, sql: str, rows: List) -> None:
    """Execute SQL in BATCH_SIZE chunks."""
    for i in range(0, len(rows), BATCH_SIZE):
        cursor.executemany(sql, rows[i:i + BATCH_SIZE])


def download_and_extract_db(url: str, repo_name: str) -> List[Dict]:
    """Download and extract package metadata from a database file."""
    packages = []
    print(f"[Download] {repo_name} from {url}")
    
    try:
        start = time.time()
        with urlopen(url, timeout=DOWNLOAD_TIMEOUT) as response:
            with gzip.GzipFile(fileobj=response) as gz_file:
                with tarfile.open(fileobj=gz_file, mode='r|') as tar:
                    for member in tar:
                        if member.name.endswith('/desc'):
                            extracted = tar.extractfile(member)
                            if extracted:
                                content = extracted.read().decode('utf-8')
                                pkg_data = parse_desc_file(content)
                                pkg_data['repo'] = repo_name
                                packages.append(pkg_data)

        elapsed = time.time() - start
        if not packages:
            raise Exception(f"Empty response for {repo_name}")
        print(f"[Done] {repo_name}: {len(packages)} packages ({elapsed:.1f}s)")
        
    except Exception as e:
        print(f"[Error] {repo_name}: {e}", file=sys.stderr)
        raise

    return packages


def get_connection():
    """Get a fresh database connection."""
    conn = MySQLdb.connect(**MYSQL_CONFIG)
    conn.autocommit(False)
    return conn


def bulk_load_ids(cursor) -> Tuple[Dict, Dict, Dict]:
    """Load all existing IDs from database into memory for fast lookup."""
    print("[Cache] Loading ID caches from database...")
    
    # Load repositories
    repos = {}
    cursor.execute('SELECT id, name FROM repositories')
    for repo_id, repo_name in cursor.fetchall():
        repos[repo_name] = repo_id
    
    # Load architectures
    archs = {}
    cursor.execute('SELECT id, name FROM architectures')
    for arch_id, arch_name in cursor.fetchall():
        archs[arch_name] = arch_id
    
    # Load licenses
    licenses = {}
    cursor.execute('SELECT id, name FROM licenses')
    for license_id, license_name in cursor.fetchall():
        licenses[license_name] = license_id
    
    print(f"[Cache] Loaded: {len(repos)} repos, {len(archs)} archs, {len(licenses)} licenses")
    return repos, archs, licenses


def ensure_id_exists(cursor, table: str, column: str, value: str, id_map: Dict) -> Optional[int]:
    """Ensure ID exists, creating if necessary, and return it."""
    if value in id_map:
        return id_map[value]
    
    cursor.execute(f'INSERT IGNORE INTO {table} ({column}) VALUES (%s)', (value,))
    new_id = cursor.lastrowid
    if new_id:  # Only update map if a new row was inserted
        id_map[value] = new_id
        return new_id

    cursor.execute(f'SELECT id FROM {table} WHERE {column} = %s', (value,))
    result = cursor.fetchone()
    if result:
        new_id = result[0]
        id_map[value] = new_id
        return new_id

    return None


_PACKAGE_INSERT_SQL = '''
    INSERT INTO packages
    (name, base, version, description, repo_id, arch_id, url,
     builddate, csize, isize, sha256sum, filename, system_arch, packager)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
'''


def _insert_batch_with_fallback(cursor, sql: str, batch: List, entity: str = "row") -> Tuple[int, int]:
    """Try executemany, fall back to individual inserts on error. Returns (inserted, skipped)."""
    try:
        cursor.executemany(sql, batch)
        return len(batch), 0
    except Exception:
        inserted = skipped = 0
        for row in batch:
            try:
                cursor.execute(sql, row)
                inserted += 1
            except Exception as e:
                print(f"[Skip] Error inserting {entity} {row[0]}: {e}", file=sys.stderr)
                skipped += 1
        return inserted, skipped


def batch_insert_packages(cursor, packages: List[Dict], system_arch: str,
                          repo_map: Dict, arch_map: Dict) -> Tuple[int, int]:
    """Insert packages using batch operations for speed."""
    print(f"[Insert] Starting batch insert of {len(packages)} packages for {system_arch}")

    # Pre-populate all repo/arch IDs we'll need (avoids per-package ensure_id_exists)
    for name in {pkg.get('repo', 'unknown') for pkg in packages}:
        if name not in repo_map:
            ensure_id_exists(cursor, 'repositories', 'name', name, repo_map)
    for name in {pkg.get('ARCH', 'unknown') for pkg in packages}:
        if name not in arch_map:
            ensure_id_exists(cursor, 'architectures', 'name', name, arch_map)

    rows = []
    for pkg in packages:
        try:
            rows.append((
                pkg.get('NAME'),
                pkg.get('BASE'),
                pkg.get('VERSION'),
                pkg.get('DESC'),
                repo_map.get(pkg.get('repo', 'unknown')),
                arch_map.get(pkg.get('ARCH', 'unknown')),
                pkg.get('URL'),
                int(pkg['BUILDDATE']) if pkg.get('BUILDDATE') else None,
                int(pkg['CSIZE']) if pkg.get('CSIZE') else None,
                int(pkg['ISIZE']) if pkg.get('ISIZE') else None,
                pkg.get('SHA256SUM'),
                pkg.get('FILENAME'),
                system_arch,
                pkg.get('PACKAGER'),
            ))
        except Exception as e:
            print(f"[Skip] Error preparing package {pkg.get('NAME')}: {e}", file=sys.stderr)

    start_time = time.time()
    inserted = skipped = 0
    for i in range(0, len(rows), BATCH_SIZE):
        n, s = _insert_batch_with_fallback(cursor, _PACKAGE_INSERT_SQL, rows[i:i + BATCH_SIZE], "package")
        inserted += n
        skipped += s

    elapsed = time.time() - start_time
    print(f"[Insert] Complete: {inserted} inserted in {elapsed:.1f}s ({inserted/elapsed:.0f} pkg/sec)")
    return inserted, skipped


def _insert_arch_packages(packages: List[Dict], system_arch: str,
                           repo_map: Dict, arch_map: Dict) -> List[Dict]:
    """Insert packages for one arch using a dedicated connection. Returns the package list."""
    print(f"\n[Process] Processing {system_arch.upper()} ({len(packages)} packages)")
    conn = get_connection()
    cursor = conn.cursor()
    try:
        batch_insert_packages(cursor, packages, system_arch, repo_map, arch_map)
        conn.commit()
    finally:
        cursor.close()
        conn.close()
    return packages


def insert_licenses_thread(packages: List[Dict], license_map: Dict, package_id_map: Dict) -> None:
    """Insert licenses using own connection (thread-safe)."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        all_license_names = {
            name
            for pkg in packages
            for name in get_list(pkg, 'LICENSE')
            if isinstance(name, str) and name.strip()
        }
        new_licenses = [(n,) for n in all_license_names if n not in license_map]
        if new_licenses:
            cursor.executemany('INSERT IGNORE INTO licenses (name) VALUES (%s)', new_licenses)

        # Reload with case-insensitive keys — MySQL collation is utf8mb4_unicode_ci
        cursor.execute('SELECT id, name FROM licenses')
        local_license_map = {lname.lower(): lid for lid, lname in cursor.fetchall()}

        seen = set()
        batch = []
        for pkg in packages:
            package_id = package_id_map.get((pkg.get('NAME'), pkg.get('_system_arch', 'aarch64')))
            if not package_id:
                continue
            for name in get_list(pkg, 'LICENSE'):
                if isinstance(name, str) and name.strip():
                    license_id = local_license_map.get(name.lower())
                    if license_id and (package_id, license_id) not in seen:
                        batch.append((package_id, license_id))
                        seen.add((package_id, license_id))

        batch_executemany(cursor, 'INSERT IGNORE INTO package_licenses (package_id, license_id) VALUES (%s, %s)', batch)
        conn.commit()
        print(f"[License] Complete ({len(batch)} entries)")
    finally:
        cursor.close()
        conn.close()


def insert_provides_thread(packages: List[Dict], package_id_map: Dict) -> None:
    """Insert provides using own connection (thread-safe)."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        seen = set()
        batch = []
        for pkg in packages:
            package_id = package_id_map.get((pkg.get('NAME'), pkg.get('_system_arch', 'aarch64')))
            if not package_id:
                continue
            for name in get_list(pkg, 'PROVIDES'):
                if isinstance(name, str) and name.strip() and (package_id, name) not in seen:
                    batch.append((package_id, name))
                    seen.add((package_id, name))

        batch_executemany(cursor, 'INSERT IGNORE INTO package_provides (package_id, provides_name) VALUES (%s, %s)', batch)
        conn.commit()
        print(f"[Provides] Complete ({len(batch)} entries)")
    finally:
        cursor.close()
        conn.close()


def insert_depends_thread(packages: List[Dict], package_id_map: Dict) -> None:
    """Insert dependencies using own connection (thread-safe)."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        seen = set()
        batch = []
        for pkg in packages:
            package_id = package_id_map.get((pkg.get('NAME'), pkg.get('_system_arch', 'aarch64')))
            if not package_id:
                continue
            for dep in get_list(pkg, 'DEPENDS'):
                if isinstance(dep, str) and dep.strip() and (package_id, dep) not in seen:
                    batch.append((package_id, dep))
                    seen.add((package_id, dep))

        batch_executemany(cursor, 'INSERT IGNORE INTO package_depends (package_id, dependency) VALUES (%s, %s)', batch)
        conn.commit()
        print(f"[Depends] Complete ({len(batch)} entries)")
    finally:
        cursor.close()
        conn.close()


def insert_groups_thread(packages: List[Dict], package_id_map: Dict) -> None:
    """Insert groups using own connection (thread-safe)."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        all_group_names = {
            name
            for pkg in packages
            for name in get_list(pkg, 'GROUPS')
            if isinstance(name, str) and name.strip()
        }
        if all_group_names:
            cursor.executemany('INSERT IGNORE INTO groups (name) VALUES (%s)', [(n,) for n in all_group_names])
        cursor.execute('SELECT id, name FROM groups')
        group_cache = {gname: gid for gid, gname in cursor.fetchall()}

        seen = set()
        batch = []
        for pkg in packages:
            package_id = package_id_map.get((pkg.get('NAME'), pkg.get('_system_arch', 'aarch64')))
            if not package_id:
                continue
            for name in get_list(pkg, 'GROUPS'):
                if isinstance(name, str) and name.strip():
                    group_id = group_cache.get(name)
                    if group_id and (package_id, group_id) not in seen:
                        batch.append((package_id, group_id))
                        seen.add((package_id, group_id))

        batch_executemany(cursor, 'INSERT IGNORE INTO package_groups (package_id, group_id) VALUES (%s, %s)', batch)
        conn.commit()
        print(f"[Groups] Complete ({len(batch)} entries)")
    finally:
        cursor.close()
        conn.close()


def insert_optdeps_thread(packages: List[Dict], package_id_map: Dict) -> None:
    """Insert optional dependencies using own connection (thread-safe)."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        all_optdep_names = {
            optdep.split(':', 1)[0].strip()
            for pkg in packages
            for optdep in get_list(pkg, 'OPTDEPENDS')
            if isinstance(optdep, str) and optdep.strip()
        }
        if all_optdep_names:
            cursor.executemany('INSERT IGNORE INTO optional_deps (name) VALUES (%s)', [(n,) for n in all_optdep_names])
        cursor.execute('SELECT id, name FROM optional_deps')
        optdep_cache = {oname: oid for oid, oname in cursor.fetchall()}

        seen = set()
        batch = []
        for pkg in packages:
            package_id = package_id_map.get((pkg.get('NAME'), pkg.get('_system_arch', 'aarch64')))
            if not package_id:
                continue
            for optdep in get_list(pkg, 'OPTDEPENDS'):
                if isinstance(optdep, str) and optdep.strip():
                    parts = optdep.split(':', 1)
                    optdep_name = parts[0].strip()
                    description = parts[1].strip() if len(parts) > 1 else None
                    optdep_id = optdep_cache.get(optdep_name)
                    if optdep_id:
                        entry = (package_id, optdep_id, description)
                        if entry not in seen:
                            batch.append(entry)
                            seen.add(entry)

        batch_executemany(cursor, 'INSERT IGNORE INTO package_optional_deps (package_id, optional_dep_id, description) VALUES (%s, %s, %s)', batch)
        conn.commit()
        print(f"[OptDeps] Complete ({len(batch)} entries)")
    finally:
        cursor.close()
        conn.close()


def clear_old_data_if_needed(cursor, force_full_import: bool = False) -> bool:
    """Clear data only if DB is empty. Returns True if full wipe happened.
    
    On first run (empty DB): truncate all tables for clean initialization.
    On subsequent runs (existing data): skip truncate, delete old packages
      for configured architectures, and do incremental update (faster).
    """
    if force_full_import:
        print("[Clean] Full import requested, performing complete rebuild...")
        cursor.execute('SET FOREIGN_KEY_CHECKS=0')
        try:
            for table in LOADER_TABLES:
                cursor.execute(f'TRUNCATE TABLE {table}')
            print(f"[Clean] Wiped {len(LOADER_TABLES)} tables")
        finally:
            try:
                cursor.execute('SET FOREIGN_KEY_CHECKS=1')
            except Exception as e:
                print(f"[Warn] Could not re-enable foreign key checks: {e}", file=sys.stderr)
        return True

    cursor.execute('SELECT COUNT(*) FROM packages')
    has_data = cursor.fetchone()[0] > 0
    
    if not has_data:
        # First run: full wipe
        print("[Clean] Database empty, performing full initialization...")
        cursor.execute('SET FOREIGN_KEY_CHECKS=0')
        try:
            for table in LOADER_TABLES:
                cursor.execute(f'TRUNCATE TABLE {table}')
            print(f"[Clean] Wiped {len(LOADER_TABLES)} tables")
        finally:
            try:
                cursor.execute('SET FOREIGN_KEY_CHECKS=1')
            except Exception as e:
                print(f"[Warn] Could not re-enable foreign key checks: {e}", file=sys.stderr)
        return True

    # If relation tables contain orphan rows, the database has been left in an
    # inconsistent state by a previous interrupted or buggy run. Fall back to a
    # full wipe so we can rebuild from scratch correctly.
    for table in RELATION_TABLES:
        cursor.execute(
            f'''
            SELECT 1
            FROM {table} t
            LEFT JOIN packages p ON p.id = t.package_id
            WHERE p.id IS NULL
            LIMIT 1
            '''
        )
        if cursor.fetchone():
            print(f"[Clean] Detected orphan rows in {table}, performing full rebuild...")
            cursor.execute('SET FOREIGN_KEY_CHECKS=0')
            try:
                for wipe_table in LOADER_TABLES:
                    cursor.execute(f'TRUNCATE TABLE {wipe_table}')
                print(f"[Clean] Wiped {len(LOADER_TABLES)} tables")
            finally:
                try:
                    cursor.execute('SET FOREIGN_KEY_CHECKS=1')
                except Exception as e:
                    print(f"[Warn] Could not re-enable foreign key checks: {e}", file=sys.stderr)
            return True
    
    # Subsequent runs: delete old data for configured architectures only
    print("[Clean] Database has existing data, using incremental update mode...")
    archs_to_delete = list(DB_URLS.keys())
    placeholders = ','.join(['%s'] * len(archs_to_delete))

    for table in RELATION_TABLES:
        cursor.execute(
            f'''
            DELETE t
            FROM {table} t
            JOIN packages p ON p.id = t.package_id
            WHERE p.system_arch IN ({placeholders})
            ''',
            archs_to_delete,
        )
    cursor.execute(f'DELETE FROM packages WHERE system_arch IN ({placeholders})', archs_to_delete)
    deleted = cursor.rowcount
    print(f"[Clean] Deleted {deleted} old packages and related rows from {', '.join(archs_to_delete)}")
    return False


def main():
    """Main execution function."""
    try:
        parser = argparse.ArgumentParser(description='Download and import Arch Linux package databases.')
        parser.add_argument(
            '--full-import',
            action='store_true',
            help='wipe the database before importing'
        )
        args = parser.parse_args()

        print("=" * 70)
        print("ARCH LINUX PACKAGE DATABASE LOADER")
        print("=" * 70)

        total_start = time.time()

        # Start downloads immediately — they don't need the DB
        print("[Download] Starting parallel downloads...")
        future_to_arch: Dict = {}
        arch_pending: Dict[str, int] = {}
        arch_packages_dl: Dict[str, List] = {}

        dl_executor = ThreadPoolExecutor(max_workers=PARALLEL_DOWNLOADS)
        for arch, repos in DB_URLS.items():
            arch_pending[arch] = len(repos)
            arch_packages_dl[arch] = []
            for repo_name, url in repos.items():
                f = dl_executor.submit(download_and_extract_db, url, repo_name)
                future_to_arch[f] = arch

        # DB setup runs while downloads are in flight
        print("[DB] Connecting to MySQL database...")
        conn = get_connection()
        cursor = conn.cursor()
        clear_old_data_if_needed(cursor, force_full_import=args.full_import)
        conn.commit()

        repo_map, arch_map, license_map = bulk_load_ids(cursor)

        # Pre-populate all known repos and archs so insert threads never mutate shared maps
        for arch_name in DB_URLS:
            ensure_id_exists(cursor, 'architectures', 'name', arch_name, arch_map)
            for repo_name in DB_URLS[arch_name]:
                ensure_id_exists(cursor, 'repositories', 'name', repo_name, repo_map)
        ensure_id_exists(cursor, 'architectures', 'name', 'any', arch_map)
        conn.commit()

        # Pipeline: as each arch finishes downloading, insert it immediately (own connection)
        all_packages_flat = []
        with ThreadPoolExecutor(max_workers=len(DB_URLS)) as insert_executor:
            insert_futures: Dict = {}

            for f in as_completed(future_to_arch):
                arch = future_to_arch[f]
                arch_packages_dl[arch].extend(f.result())
                arch_pending[arch] -= 1
                if arch_pending[arch] == 0:
                    pkgs = arch_packages_dl[arch]
                    for pkg in pkgs:
                        pkg['_system_arch'] = arch
                    insert_futures[insert_executor.submit(
                        _insert_arch_packages, pkgs, arch, repo_map, arch_map
                    )] = arch

            for f in as_completed(insert_futures):
                all_packages_flat.extend(f.result())

        dl_executor.shutdown(wait=False)
        print("[Download] All downloads and inserts complete")

        # Commit to start a fresh transaction that sees all arch-thread commits
        conn.commit()

        # Now pre-compute ALL package ID maps AFTER packages are inserted
        print("[Lookup] Pre-computing package ID maps...")
        package_id_map = {}
        cursor.execute('SELECT name, system_arch, id FROM packages')
        for name, arch, pkg_id in cursor.fetchall():
            package_id_map[(name, arch)] = pkg_id

        # Insert all relations in parallel (each uses its own connection)
        print("\n[Relations] Inserting package relationships (parallel)...")
        rel_start = time.time()
        with ThreadPoolExecutor(max_workers=5) as executor:
            futures = [
                executor.submit(insert_licenses_thread, all_packages_flat, license_map, package_id_map),
                executor.submit(insert_provides_thread, all_packages_flat, package_id_map),
                executor.submit(insert_depends_thread, all_packages_flat, package_id_map),
                executor.submit(insert_groups_thread, all_packages_flat, package_id_map),
                executor.submit(insert_optdeps_thread, all_packages_flat, package_id_map),
            ]
            for future in as_completed(futures):
                future.result()  # Raise any exceptions
        print(f"[Relations] All complete in {time.time() - rel_start:.1f}s")

        # Update query statistics so MySQL has accurate plans on first post-import query
        print("[Analyze] Updating table statistics...")
        for table in ['packages', 'package_depends', 'package_provides',
                      'package_licenses', 'package_groups', 'package_optional_deps']:
            cursor.execute(f'ANALYZE TABLE {table}')
            cursor.fetchall()

        # Record import timestamp
        cursor.execute(
            'INSERT INTO import_metadata (import_timestamp) VALUES (%s)',
            (datetime.now().strftime('%Y-%m-%d %H:%M:%S'),)
        )
        conn.commit()

        # Print summary
        cursor.execute('SELECT COUNT(*) FROM packages')
        total = cursor.fetchone()[0]

        import_time = time.time() - total_start

        print("\n" + "=" * 70)
        print(f"✓ Successfully loaded {total} packages into the database")
        print(f"Import time: {import_time:.1f}s ({total/import_time:.0f} packages/sec)")
        print("=" * 70)

        # Print breakdown
        cursor.execute('''
        SELECT p.system_arch, r.name as repo, COUNT(*) as count
        FROM packages p
        JOIN repositories r ON p.repo_id = r.id
        GROUP BY p.system_arch, r.id, r.name
        ORDER BY p.system_arch, r.name
        ''')

        print("\nPackages by architecture and repository:")
        for system_arch, repo, count in cursor.fetchall():
            print(f"  {system_arch:10} {repo:10} {count:6,} packages")

        # Determine the deployed reporting directory (may differ from source)
        try:
            reporting_dir = CONFIG.get('deploy', 'reporting_dir')
        except Exception:
            reporting_dir = os.path.join(os.path.dirname(__file__), 'reporting')

        # Pre-warm the analysis cache so the first web request is fast
        warm_script = os.path.join(reporting_dir, 'warm_cache.php')
        if os.path.exists(warm_script):
            try:
                import subprocess
                warm_jobs = [
                    ('stats', ['php', warm_script, 'stats']),
                    ('counts-a', ['php', warm_script, 'counts-a']),
                    ('counts-b', ['php', warm_script, 'counts-b']),
                    ('counts-c', ['php', warm_script, 'counts-c']),
                ]
                processes = []
                for label, cmd in warm_jobs:
                    proc = subprocess.Popen(
                        cmd,
                        cwd=reporting_dir,
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE,
                        text=True,
                    )
                    processes.append((label, proc))

                for label, proc in processes:
                    stdout, stderr = proc.communicate(timeout=120)
                    if stdout:
                        print(stdout.strip())
                    if proc.returncode != 0:
                        if stderr:
                            print(f"⚠ Cache warm warning ({label}): {stderr.strip()}", file=sys.stderr)
                        else:
                            print(f"⚠ Cache warm warning ({label}): exit code {proc.returncode}", file=sys.stderr)
            except Exception as e:
                print(f"⚠ Warning: Could not warm cache: {e}", file=sys.stderr)

        end_to_end_time = time.time() - total_start
        print(f"\nEnd-to-end time (including cache warm): {end_to_end_time:.1f}s")

        cursor.close()
        conn.close()

    except Exception as e:
        if 'dl_executor' in locals():
            dl_executor.shutdown(wait=False)
        print(f"✗ Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
