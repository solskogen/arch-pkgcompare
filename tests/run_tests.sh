#!/bin/bash
# Test runner for Arch Linux aarch64 Reporting System

set -e

# Get the absolute path to the project root (parent of tests directory)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
TESTS_DIR="$SCRIPT_DIR"

echo "========================================="
echo "Arch Linux aarch64 Reporting System"
echo "Test Suite v0.1"
echo "========================================="
echo ""

# Check Python is available
if ! command -v python3 &> /dev/null; then
    echo "✗ Python 3 is required but not installed"
    exit 1
fi

# Check PHP is available
if ! command -v php &> /dev/null; then
    echo "✗ PHP is required but not installed"
    exit 1
fi

echo "Running Python loader tests..."
echo "==============================="
cd "$PROJECT_DIR"
python3 -m unittest tests.test_loader -v
TEST_RESULT_PY=$?

echo ""
echo "Running PHP application tests..."
echo "================================="
php "$TESTS_DIR/test_app.php"
TEST_RESULT_PHP=$?

echo ""
echo "========================================="
if [ $TEST_RESULT_PY -eq 0 ] && [ $TEST_RESULT_PHP -eq 0 ]; then
    echo "✓ All tests passed!"
    exit 0
else
    echo "✗ Some tests failed"
    exit 1
fi
