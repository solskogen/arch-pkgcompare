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
import io
import os
import sys
import MySQLdb
import configparser
from urllib.request import urlopen
from typing import Dict, List, Optional, Tuple, Set
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
try:
    BATCH_SIZE = CONFIG.getint('loader', 'batch_size')
except ValueError:
    BATCH_SIZE = 5000

try:
    PARALLEL_DOWNLOADS = CONFIG.getint('loader', 'parallel_downloads')
except ValueError:
    PARALLEL_DOWNLOADS = 5

try:
    DOWNLOAD_TIMEOUT = CONFIG.getint('loader', 'timeout')
except ValueError:
    DOWNLOAD_TIMEOUT = 30


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


def download_and_extract_db(url: str, repo_name: str) -> List[Dict]:
    """Download and extract package metadata from a database file."""
    packages = []
    print(f"[Download] {repo_name} from {url}")
    
    try:
        start = time.time()
        with urlopen(url, timeout=DOWNLOAD_TIMEOUT) as response:
            data = response.read()
            if not data:
                raise Exception(f"Empty response for {repo_name}")
        
        dl_time = time.time() - start
        print(f"[Extract] {repo_name} ({dl_time:.1f}s)")
        
        extract_start = time.time()
        with gzip.GzipFile(fileobj=io.BytesIO(data)) as gz_file:
            with tarfile.open(fileobj=gz_file, mode='r|') as tar:
                for member in tar:
                    if member.name.endswith('/desc'):
                        extracted = tar.extractfile(member)
                        if extracted:
                            content = extracted.read().decode('utf-8')
                            pkg_data = parse_desc_file(content)
                            pkg_data['repo'] = repo_name
                            packages.append(pkg_data)
        
        extract_time = time.time() - extract_start
        print(f"[Done] {repo_name}: {len(packages)} packages ({extract_time:.1f}s)")
        
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


def ensure_id_exists(cursor, table: str, column: str, value: str, id_map: Dict) -> int:
    """Ensure ID exists, creating if necessary, and return it."""
    if value in id_map:
        return id_map[value]
    
    try:
        cursor.execute(f'INSERT IGNORE INTO {table} ({column}) VALUES (%s)', (value,))
        new_id = cursor.lastrowid
        if new_id:  # Only update map if a new row was inserted
            id_map[value] = new_id
        else:  # Row already existed, fetch its ID
            cursor.execute(f'SELECT id FROM {table} WHERE {column} = %s', (value,))
            result = cursor.fetchone()
            if result:
                new_id = result[0]
                id_map[value] = new_id
    except Exception:
        # Try fetching the ID if insert failed
        cursor.execute(f'SELECT id FROM {table} WHERE {column} = %s', (value,))
        result = cursor.fetchone()
        if result:
            new_id = result[0]
            id_map[value] = new_id
        else:
            return None
    
    return id_map.get(value)


def batch_insert_packages(cursor, packages: List[Dict], system_arch: str, 
                         repo_map: Dict, arch_map: Dict) -> Tuple[int, int]:
    """Insert packages using batch operations for speed."""
    print(f"[Insert] Starting batch insert of {len(packages)} packages for {system_arch}")
    
    # Pre-populate all repo/arch IDs we'll need (avoids per-package ensure_id_exists)
    unique_repos = {pkg.get('repo', 'unknown') for pkg in packages}
    unique_archs = {pkg.get('ARCH', 'unknown') for pkg in packages}
    for name in unique_repos:
        if name not in repo_map:
            ensure_id_exists(cursor, 'repositories', 'name', name, repo_map)
    for name in unique_archs:
        if name not in arch_map:
            ensure_id_exists(cursor, 'architectures', 'name', name, arch_map)
    
    inserted = 0
    skipped = 0
    batch_packages = []
    
    start_time = time.time()
    
    for pkg in packages:
        try:
            repo_id = repo_map.get(pkg.get('repo', 'unknown'))
            arch_id = arch_map.get(pkg.get('ARCH', 'unknown'))
            
            batch_packages.append((
                pkg.get('NAME'),
                pkg.get('BASE'),
                pkg.get('VERSION'),
                pkg.get('DESC'),
                repo_id,
                arch_id,
                pkg.get('URL'),
                int(pkg.get('BUILDDATE')) if pkg.get('BUILDDATE') else None,
                int(pkg.get('CSIZE')) if pkg.get('CSIZE') else None,
                int(pkg.get('ISIZE')) if pkg.get('ISIZE') else None,
                pkg.get('SHA256SUM'),
                pkg.get('FILENAME'),
                system_arch,
                pkg.get('PACKAGER'),
            ))
            
            if len(batch_packages) >= BATCH_SIZE:
                try:
                    cursor.executemany('''
                        INSERT INTO packages 
                        (name, base, version, description, repo_id, arch_id, url, 
                         builddate, csize, isize, sha256sum, filename, system_arch, packager)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ''', batch_packages)
                    inserted += len(batch_packages)
                except Exception:
                    for single_pkg in batch_packages:
                        try:
                            cursor.execute('''
                                INSERT INTO packages 
                                (name, base, version, description, repo_id, arch_id, url, 
                                 builddate, csize, isize, sha256sum, filename, system_arch, packager)
                                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                            ''', single_pkg)
                            inserted += 1
                        except Exception as single_error:
                            print(f"[Skip] Error with package {single_pkg[0]}: {single_error}", file=sys.stderr)
                            skipped += 1
                batch_packages = []
        
        except Exception as e:
            print(f"[Skip] Error with package {pkg.get('NAME')}: {e}", file=sys.stderr)
            skipped += 1
    
    if batch_packages:
        try:
            cursor.executemany('''
                INSERT INTO packages 
                (name, base, version, description, repo_id, arch_id, url, 
                 builddate, csize, isize, sha256sum, filename, system_arch, packager)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ''', batch_packages)
            inserted += len(batch_packages)
        except Exception:
            for single_pkg in batch_packages:
                try:
                    cursor.execute('''
                        INSERT INTO packages 
                        (name, base, version, description, repo_id, arch_id, url, 
                         builddate, csize, isize, sha256sum, filename, system_arch, packager)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ''', single_pkg)
                    inserted += 1
                except Exception as single_error:
                    print(f"[Skip] Error with package {single_pkg[0]}: {single_error}", file=sys.stderr)
                    skipped += 1
    
    elapsed = time.time() - start_time
    print(f"[Insert] Complete: {inserted} inserted in {elapsed:.1f}s ({inserted/elapsed:.0f} pkg/sec)")
    
    return inserted, skipped


def prepare_license_data(packages: List[Dict], license_map: Dict, package_id_map: Dict) -> Tuple[List[Tuple], List[Tuple]]:
    """Prepare license data for insertion (pure Python, no DB calls)."""
    # Collect all unique license names
    all_license_names = set()
    for pkg in packages:
        licenses = pkg.get('LICENSE', [])
        if isinstance(licenses, str):
            licenses = [licenses]
        for name in licenses:
            if isinstance(name, str) and name.strip():
                all_license_names.add(name)
    
    new_licenses = [(n,) for n in all_license_names if n not in license_map]
    
    return all_license_names, new_licenses


def insert_licenses_thread(packages: List[Dict], license_map: Dict, package_id_map: Dict) -> None:
    """Insert licenses using own connection (thread-safe)."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        # Collect unique names and batch insert new ones
        all_license_names = set()
        for pkg in packages:
            licenses = pkg.get('LICENSE', [])
            if isinstance(licenses, str):
                licenses = [licenses]
            for name in licenses:
                if isinstance(name, str) and name.strip():
                    all_license_names.add(name)
        
        new_licenses = [(n,) for n in all_license_names if n not in license_map]
        if new_licenses:
            cursor.executemany('INSERT IGNORE INTO licenses (name) VALUES (%s)', new_licenses)
        
        # Reload all license IDs — use case-insensitive keys since MySQL
        # collation is utf8mb4_unicode_ci (case-insensitive)
        local_license_map = {}
        cursor.execute('SELECT id, name FROM licenses')
        for lid, lname in cursor.fetchall():
            local_license_map[lname.lower()] = lid
        
        batch = []
        seen = set()
        for pkg in packages:
            pkg_name = pkg.get('NAME')
            system_arch = pkg.get('_system_arch', 'aarch64')
            package_id = package_id_map.get((pkg_name, system_arch))
            if not package_id:
                continue
            licenses = pkg.get('LICENSE', [])
            if isinstance(licenses, str):
                licenses = [licenses]
            for license_name in licenses:
                if isinstance(license_name, str) and license_name.strip():
                    license_id = local_license_map.get(license_name.lower())
                    if license_id:
                        pair = (package_id, license_id)
                        if pair not in seen:
                            batch.append(pair)
                            seen.add(pair)
        
        if batch:
            for i in range(0, len(batch), BATCH_SIZE):
                cursor.executemany(
                    'INSERT IGNORE INTO package_licenses (package_id, license_id) VALUES (%s, %s)',
                    batch[i:i + BATCH_SIZE]
                )
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
        batch = []
        seen = set()
        for pkg in packages:
            pkg_name = pkg.get('NAME')
            system_arch = pkg.get('_system_arch', 'aarch64')
            package_id = package_id_map.get((pkg_name, system_arch))
            if not package_id:
                continue
            provides = pkg.get('PROVIDES', [])
            if isinstance(provides, str):
                provides = [provides]
            for provides_name in provides:
                if isinstance(provides_name, str) and provides_name.strip():
                    pair = (package_id, provides_name)
                    if pair not in seen:
                        batch.append(pair)
                        seen.add(pair)
        
        if batch:
            for i in range(0, len(batch), BATCH_SIZE):
                cursor.executemany(
                    'INSERT IGNORE INTO package_provides (package_id, provides_name) VALUES (%s, %s)',
                    batch[i:i + BATCH_SIZE]
                )
        conn.commit()
        print(f"[Provides] Complete ({len(batch)} entries)")
    finally:
        cursor.close()
        conn.close()


def insert_depends_thread(packages_by_arch: Dict[str, List[Dict]], package_id_map: Dict) -> None:
    """Insert dependencies using own connection (thread-safe)."""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        batch = []
        seen = set()
        for system_arch, packages in packages_by_arch.items():
            for pkg in packages:
                pkg_name = pkg.get('NAME')
                package_id = package_id_map.get((pkg_name, system_arch))
                if not package_id:
                    continue
                depends = pkg.get('DEPENDS', [])
                if isinstance(depends, str):
                    depends = [depends]
                for dependency in depends:
                    if isinstance(dependency, str) and dependency.strip():
                        pair = (package_id, dependency)
                        if pair not in seen:
                            batch.append(pair)
                            seen.add(pair)
        
        if batch:
            for i in range(0, len(batch), BATCH_SIZE):
                cursor.executemany(
                    'INSERT IGNORE INTO package_depends (package_id, dependency) VALUES (%s, %s)',
                    batch[i:i + BATCH_SIZE]
                )
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
        # Collect and batch-insert all group names
        all_group_names = set()
        for pkg in packages:
            groups = pkg.get('GROUPS', [])
            if isinstance(groups, str):
                groups = [groups]
            for name in groups:
                if isinstance(name, str) and name.strip():
                    all_group_names.add(name)
        
        if all_group_names:
            cursor.executemany('INSERT IGNORE INTO groups (name) VALUES (%s)',
                               [(n,) for n in all_group_names])
        group_cache = {}
        cursor.execute('SELECT id, name FROM groups')
        for gid, gname in cursor.fetchall():
            group_cache[gname] = gid
        
        batch = []
        seen = set()
        for pkg in packages:
            pkg_name = pkg.get('NAME')
            system_arch = pkg.get('_system_arch', 'aarch64')
            package_id = package_id_map.get((pkg_name, system_arch))
            if not package_id:
                continue
            groups = pkg.get('GROUPS', [])
            if isinstance(groups, str):
                groups = [groups]
            for group_name in groups:
                if isinstance(group_name, str) and group_name.strip():
                    group_id = group_cache.get(group_name)
                    if group_id:
                        pair = (package_id, group_id)
                        if pair not in seen:
                            batch.append(pair)
                            seen.add(pair)
        
        if batch:
            for i in range(0, len(batch), BATCH_SIZE):
                cursor.executemany(
                    'INSERT IGNORE INTO package_groups (package_id, group_id) VALUES (%s, %s)',
                    batch[i:i + BATCH_SIZE]
                )
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
        # Collect and batch-insert all optdep names
        all_optdep_names = set()
        for pkg in packages:
            optdeps = pkg.get('OPTDEPENDS', [])
            if isinstance(optdeps, str):
                optdeps = [optdeps]
            for optdep in optdeps:
                if isinstance(optdep, str) and optdep.strip():
                    all_optdep_names.add(optdep.split(':', 1)[0].strip())
        
        if all_optdep_names:
            cursor.executemany('INSERT IGNORE INTO optional_deps (name) VALUES (%s)',
                               [(n,) for n in all_optdep_names])
        optdep_cache = {}
        cursor.execute('SELECT id, name FROM optional_deps')
        for oid, oname in cursor.fetchall():
            optdep_cache[oname] = oid
        
        batch = []
        seen = set()
        for pkg in packages:
            pkg_name = pkg.get('NAME')
            system_arch = pkg.get('_system_arch', 'aarch64')
            package_id = package_id_map.get((pkg_name, system_arch))
            if not package_id:
                continue
            optdeps = pkg.get('OPTDEPENDS', [])
            if isinstance(optdeps, str):
                optdeps = [optdeps]
            for optdep in optdeps:
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
        
        if batch:
            for i in range(0, len(batch), BATCH_SIZE):
                cursor.executemany(
                    'INSERT IGNORE INTO package_optional_deps (package_id, optional_dep_id, description) VALUES (%s, %s, %s)',
                    batch[i:i + BATCH_SIZE]
                )
        conn.commit()
        print(f"[OptDeps] Complete ({len(batch)} entries)")
    finally:
        cursor.close()
        conn.close()


def clear_old_data(cursor) -> None:
    """Clear all data from database before reloading."""
    print("[Clean] Wiping all database tables...")
    try:
        cursor.execute('SET FOREIGN_KEY_CHECKS=0')
        cursor.execute('SHOW TABLES')
        tables = [row[0] for row in cursor.fetchall()]
        for table in tables:
            cursor.execute(f'TRUNCATE TABLE {table}')
        cursor.execute('SET FOREIGN_KEY_CHECKS=1')
        print(f"[Clean] Wiped {len(tables)} tables")
    except Exception as e:
        print(f"[Warn] Could not clear old data: {e}")


def main():
    """Main execution function with parallel downloads."""
    try:
        print("=" * 70)
        print("ARCH LINUX PACKAGE DATABASE LOADER")
        print("=" * 70)
        
        total_start = time.time()
        
        # Connect to MySQL
        print("[DB] Connecting to MySQL database...")
        conn = get_connection()
        cursor = conn.cursor()
        
        # Clear old data
        clear_old_data(cursor)
        conn.commit()
        
        # Load ID caches
        repo_map, arch_map, license_map = bulk_load_ids(cursor)
        
        # Download all databases in parallel
        print("[Download] Starting parallel downloads...")
        all_packages = {}
        
        with ThreadPoolExecutor(max_workers=PARALLEL_DOWNLOADS) as executor:
            futures = {}
            for system_arch, repos in DB_URLS.items():
                for repo_name, url in repos.items():
                    future = executor.submit(download_and_extract_db, url, repo_name)
                    futures[future] = (system_arch, repo_name)
            
            for future in as_completed(futures):
                system_arch, repo_name = futures[future]
                packages = future.result()
                if system_arch not in all_packages:
                    all_packages[system_arch] = []
                all_packages[system_arch].extend(packages)
        
        print("[Download] All downloads complete")
        
        # Process each architecture
        all_packages_flat = []
        for system_arch in sorted(all_packages.keys()):
            if system_arch not in all_packages:
                continue
            
            packages = all_packages[system_arch]
            print(f"\n[Process] Processing {system_arch.upper()} ({len(packages)} packages)")
            
            inserted, skipped = batch_insert_packages(cursor, packages, system_arch, 
                                                     repo_map, arch_map)
            
            # Add system_arch to each package dict before flattening
            for pkg in packages:
                pkg['_system_arch'] = system_arch
            all_packages_flat.extend(packages)
        
        # Single commit after all packages are inserted
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
                executor.submit(insert_depends_thread, all_packages, package_id_map),
                executor.submit(insert_groups_thread, all_packages_flat, package_id_map),
                executor.submit(insert_optdeps_thread, all_packages_flat, package_id_map),
            ]
            for future in as_completed(futures):
                future.result()  # Raise any exceptions
        print(f"[Relations] All complete in {time.time() - rel_start:.1f}s")
        
        # Record import timestamp
        cursor.execute(
            'INSERT INTO import_metadata (import_timestamp) VALUES (%s)',
            (datetime.now().strftime('%Y-%m-%d %H:%M:%S'),)
        )
        conn.commit()
        
        # Print summary
        cursor.execute('SELECT COUNT(*) FROM packages')
        total = cursor.fetchone()[0]
        
        total_time = time.time() - total_start
        
        print("\n" + "=" * 70)
        print(f"✓ Successfully loaded {total} packages into the database")
        print(f"Total time: {total_time:.1f}s ({total/total_time:.0f} packages/sec)")
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
        
        # Clear the reporting cache since database has been updated
        cache_dir = os.path.join(os.path.dirname(__file__), 'reporting', 'cache')
        if os.path.exists(cache_dir):
            try:
                import glob
                cache_files = glob.glob(os.path.join(cache_dir, '*.cache'))
                for cache_file in cache_files:
                    os.remove(cache_file)
                print(f"\n✓ Cleared {len(cache_files)} cache file(s)")
            except Exception as e:
                print(f"⚠ Warning: Could not clear cache: {e}", file=sys.stderr)
        
        cursor.close()
        conn.close()
        
    except Exception as e:
        print(f"✗ Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
