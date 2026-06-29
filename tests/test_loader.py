#!/usr/bin/env python3
"""
Unit tests for load_arch_packages.py
Tests configuration, database operations, and error handling.
"""

import unittest
import sys
import os
import tempfile
import configparser
import hashlib
import json
import subprocess
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


class TestConfigParsing(unittest.TestCase):
    """Test configuration file parsing"""
    
    def test_config_exists(self):
        """Test that config.ini exists in project root"""
        config_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'config.ini')
        self.assertTrue(os.path.exists(config_path), f"config.ini not found at {config_path}")
    
    def test_config_readable(self):
        """Test that config.ini can be parsed"""
        config_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'config.ini')
        config = configparser.ConfigParser()
        try:
            config.read(config_path)
            self.assertTrue(len(config.sections()) > 0, "config.ini has no sections")
        except Exception as e:
            self.fail(f"Failed to parse config.ini: {e}")
    
    def test_config_has_database_section(self):
        """Test that config has [database] section"""
        config_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'config.ini')
        config = configparser.ConfigParser()
        config.read(config_path)
        self.assertIn('database', config.sections(), "[database] section not found in config.ini")
    
    def test_config_has_repository_section(self):
        """Test that config has exactly 2 [arch-*] sections for binary comparison"""
        config_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'config.ini')
        config = configparser.ConfigParser()
        config.read(config_path)
        
        # Check for [arch-*] sections (exactly 2 for binary comparison)
        arch_sections = [s for s in config.sections() if s.startswith('arch-')]
        self.assertEqual(len(arch_sections), 2, 
            "Exactly 2 [arch-*] sections required (e.g., [arch-aarch64], [arch-x86_64])")
    
    def test_architectures_have_repositories(self):
        """Test that each architecture has template or direct URL repositories"""
        config_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'config.ini')
        config = configparser.ConfigParser()
        config.read(config_path)
        
        arch_sections = [s for s in config.sections() if s.startswith('arch-')]
        for arch_section in arch_sections:
            config_items = dict(config.items(arch_section))
            
            # Check for either template format (url_template + repos) or direct URLs
            has_template = 'url_template' in config_items and 'repos' in config_items
            has_direct_urls = any(k not in ['mirror', 'url_template', 'repos'] 
                                 for k in config_items.keys())
            
            self.assertTrue(has_template or has_direct_urls,
                f"{arch_section} must have either 'url_template' + 'repos' or direct URL entries")
    
    def test_database_config_keys(self):
        """Test that database config has required keys"""
        config_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'config.ini')
        config = configparser.ConfigParser()
        config.read(config_path)
        required_keys = ['host', 'user', 'password', 'database']
        for key in required_keys:
            self.assertIn(key, config['database'], f"Missing database config key: {key}")


class TestCacheDirectory(unittest.TestCase):
    """Test cache directory handling"""
    
    def test_cache_dir_exists(self):
        """Test that cache directory exists"""
        cache_dir = os.path.join(
            os.path.dirname(os.path.dirname(__file__)), 
            'reporting', 'cache'
        )
        self.assertTrue(os.path.isdir(cache_dir), f"Cache directory not found at {cache_dir}")
    
    def test_cache_dir_writable(self):
        """Test that cache directory is writable"""
        cache_dir = os.path.join(
            os.path.dirname(os.path.dirname(__file__)), 
            'reporting', 'cache'
        )
        test_file = os.path.join(cache_dir, '.write_test')
        try:
            with open(test_file, 'w') as f:
                f.write('test')
            os.remove(test_file)
        except Exception as e:
            self.fail(f"Cache directory not writable: {e}")


class TestLoaderPaths(unittest.TestCase):
    """Test that loader uses relative paths, not hardcoded deployment paths"""
    
    def test_no_hardcoded_solskogen_path(self):
        """Test that load_arch_packages.py doesn't hardcode /home/solskogen paths"""
        loader_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'load_arch_packages.py')
        with open(loader_path, 'r') as f:
            content = f.read()
        self.assertNotIn('/home/solskogen', content, "Hardcoded /home/solskogen path found in loader")
        self.assertNotIn('/data/home/solskogen', content, "Hardcoded /data/home/solskogen path found in loader")
        self.assertNotIn('antarctica.no', content, "Domain reference found in loader")
    
    def test_no_hardcoded_public_html_path(self):
        """Test that loader doesn't hardcode public_html paths"""
        loader_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'load_arch_packages.py')
        with open(loader_path, 'r') as f:
            content = f.read()
        self.assertNotIn('public_html', content, "Hardcoded public_html path found in loader")


@unittest.skipUnless(os.environ.get('RUN_LOADER_INTEGRATION_TESTS') == '1',
                     "set RUN_LOADER_INTEGRATION_TESTS=1 to run loader integration tests")
class TestLoaderImportParity(unittest.TestCase):
    """Integration tests comparing full and incremental imports."""

    @classmethod
    def setUpClass(cls):
        cls.root_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        cls.loader = os.path.join(cls.root_dir, 'load_arch_packages.py')
        cls.mysql = configparser.ConfigParser()
        cls.mysql.read(os.path.join(cls.root_dir, 'config.ini'))

    def run_loader(self, *args):
        result = subprocess.run(
            [sys.executable, self.loader, *args],
            cwd=self.root_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            check=False,
        )
        if result.returncode != 0:
            self.fail(f"Loader failed with args {args}:\n{result.stdout}")
        return result.stdout

    def get_connection(self):
        import MySQLdb
        return MySQLdb.connect(
            host=self.mysql['database'].get('host'),
            user=self.mysql['database'].get('user'),
            passwd=self.mysql['database'].get('password'),
            db=self.mysql['database'].get('database'),
        )

    def fingerprint_query(self, cursor, sql):
        digest = hashlib.sha256()
        cursor.execute(sql)
        for row in cursor:
            digest.update(json.dumps(row, separators=(',', ':'), default=str).encode('utf-8'))
            digest.update(b'\n')
        return digest.hexdigest()

    def snapshot(self):
        conn = self.get_connection()
        try:
            cursor = conn.cursor()
            tables = {
                'packages': """
                    SELECT p.name, p.system_arch, p.version, p.base, r.name, a.name,
                           p.url, p.builddate, p.csize, p.isize, p.sha256sum,
                           p.filename, p.packager, p.validated_by
                    FROM packages p
                    JOIN repositories r ON p.repo_id = r.id
                    JOIN architectures a ON p.arch_id = a.id
                    ORDER BY p.name, p.system_arch
                """,
                'depends': """
                    SELECT p.name, p.system_arch, d.dependency
                    FROM package_depends d
                    JOIN packages p ON p.id = d.package_id
                    ORDER BY p.name, p.system_arch, d.dependency
                """,
                'licenses': """
                    SELECT p.name, p.system_arch, l.name
                    FROM package_licenses pl
                    JOIN packages p ON p.id = pl.package_id
                    JOIN licenses l ON l.id = pl.license_id
                    ORDER BY p.name, p.system_arch, l.name
                """,
                'provides': """
                    SELECT p.name, p.system_arch, pp.provides_name
                    FROM package_provides pp
                    JOIN packages p ON p.id = pp.package_id
                    ORDER BY p.name, p.system_arch, pp.provides_name
                """,
                'groups': """
                    SELECT p.name, p.system_arch, g.name
                    FROM package_groups pg
                    JOIN packages p ON p.id = pg.package_id
                    JOIN `groups` g ON g.id = pg.group_id
                    ORDER BY p.name, p.system_arch, g.name
                """,
                'optdeps': """
                    SELECT p.name, p.system_arch, od.name, pod.description
                    FROM package_optional_deps pod
                    JOIN packages p ON p.id = pod.package_id
                    JOIN optional_deps od ON od.id = pod.optional_dep_id
                    ORDER BY p.name, p.system_arch, od.name, pod.description
                """,
            }

            snapshot = {}
            for name, sql in tables.items():
                snapshot[name] = self.fingerprint_query(cursor, sql)
            return snapshot
        finally:
            conn.close()

    def test_incremental_matches_full_import(self):
        """An incremental import should produce the same logical data as a full import."""
        self.run_loader('--full-import')
        full_snapshot = self.snapshot()

        self.run_loader()
        incr_snapshot = self.snapshot()

        self.assertEqual(full_snapshot, incr_snapshot, "Incremental import must match full import snapshot")


if __name__ == '__main__':
    unittest.main()
