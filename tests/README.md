# Test Suite

Unit tests for the Arch Linux aarch64 Reporting System.

## Key Features

✓ **No database required** - Tests run with just PHP and Python
✓ **No external dependencies** - Tests only use standard library
✓ **Fast execution** - Completes in milliseconds
✓ **CI/CD ready** - Can run in GitHub Actions or any CI system

## Running Tests

```bash
# Run all tests
./tests/run_tests.sh

# Or run individually:
python3 -m unittest tests.test_loader
php tests/test_app.php
```

## Test Coverage

### Python Tests (test_loader.py)
- Configuration file parsing and validation
- Database configuration keys present and valid
- Cache directory exists and is writable
- No hardcoded deployment paths (portable)
- Uses relative paths for portability

Tests verify the loader works with any setup without embedded paths.

### PHP Tests (test_app.php)
- Helper functions (Formatter::escape, Formatter::url)
- Configuration files exist and are parseable
- Class files have valid PHP syntax
- No hardcoded deployment paths in application code
- Security-focused validation

Tests run without connecting to a database or starting a web server.

## Requirements

- Python 3.6+ (for Python tests)
- PHP 7.4+ (for PHP tests)

**No database, web server, or external services needed.**

## Test Results

All 13+ tests pass:

```
✓ config.ini exists and is readable
✓ [database] section found in config
✓ [repositories] section found in config
✓ Database config has required keys (host, user, password, database)
✓ Cache directory exists and is writable
✓ No hardcoded /home/solskogen paths
✓ No hardcoded /data paths
✓ No hardcoded public_html references
✓ Formatter::escape() prevents XSS injection
✓ Formatter::url() generates valid URLs
✓ Database.php has valid syntax
✓ PackageRepository.php has valid syntax
✓ Cache.php has valid syntax
✓ No hardcoded deployment paths in PHP files
```

## Running in CI/CD

Example GitHub Actions workflow:

```yaml
- name: Run tests
  run: ./tests/run_tests.sh
```

The test suite exits with code 0 on success, code 1 on failure.
