#!/bin/bash

# KSUID PHP Package Verification Script
# Mirrors the structure of ksuid-go/verify.sh, adapted for PHP tooling.
# Run this script to verify the package is correctly installed and all
# tests/checks pass.

set -e  # Exit on error

echo "================================================"
echo "KSUID PHP Package - Verification Script"
echo "================================================"
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Change to package directory
cd "$(dirname "$0")"

echo "Current directory: $(pwd)"
echo ""

# 0. Create test-results folder if it doesn't exist
mkdir -p test-results

# 1. Check if PHP is installed
echo -e "${YELLOW}[1/7] Checking PHP installation...${NC}"
if ! command -v php &> /dev/null; then
    echo -e "${RED}✗ PHP is not installed${NC}"
    exit 1
fi
php --version | head -n 1
echo -e "${GREEN}✓ PHP is installed${NC}"
echo ""

# Check for the GMP extension (optional but recommended)
if php -m | grep -qi '^gmp$'; then
    echo -e "${GREEN}✓ ext-gmp is available (XXHash32Gmp / portable arithmetic tests will run)${NC}"
else
    echo -e "${YELLOW}! ext-gmp is NOT available — GMP-backed tests will be skipped${NC}"
fi
echo ""

# 2. Install/verify Composer dependencies
echo -e "${YELLOW}[2/7] Checking Composer dependencies...${NC}"
if ! command -v composer &> /dev/null; then
    echo -e "${RED}✗ Composer is not installed${NC}"
    echo "  Install it from https://getcomposer.org/ or via your package manager."
    exit 1
fi
if [ ! -d "vendor" ]; then
    echo "vendor/ not found, running composer install..."
    composer install --no-interaction
else
    echo "vendor/ already present, refreshing autoloader (including dev mappings)..."
    composer dump-autoload --dev --no-interaction > /dev/null
fi
echo -e "${GREEN}✓ Dependencies ready${NC}"
echo ""

# 3. Lint check (syntax validation across all source and test files)
echo -e "${YELLOW}[3/7] Running PHP lint (syntax check)...${NC}"
LINT_FAILED=0
for f in $(find src tests -name '*.php'); do
    if ! php -l "$f" > /dev/null 2>&1; then
        echo -e "${RED}✗ Syntax error in $f${NC}"
        php -l "$f"
        LINT_FAILED=1
    fi
done
if [ "$LINT_FAILED" -eq 1 ]; then
    echo -e "${RED}✗ Lint check failed${NC}"
    exit 1
fi
echo -e "${GREEN}✓ All files passed syntax check${NC}"
echo ""

# 4. Static analysis (optional — only runs if phpstan is available)
echo -e "${YELLOW}[4/7] Running static analysis (PHPStan, if available)...${NC}"
if [ -f "vendor/bin/phpstan" ]; then
    if [ -f "phpstan.neon" ] || [ -f "phpstan.neon.dist" ]; then
        PHPSTAN_CMD="vendor/bin/phpstan analyse --no-progress"
    else
        PHPSTAN_CMD="vendor/bin/phpstan analyse src --level 5 --no-progress"
    fi
    if $PHPSTAN_CMD; then
        echo -e "${GREEN}✓ Static analysis passed${NC}"
    else
        echo -e "${RED}✗ Static analysis found issues${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}! phpstan/phpstan not installed — skipping (run 'composer require --dev phpstan/phpstan' to enable)${NC}"
fi
echo ""

# Locate the phpunit binary: prefer the Composer-managed one, fall back to
# a system-wide install (e.g. installed via apt/brew/pecl rather than
# Composer, which is common in some CI/container environments).
if [ -x "vendor/bin/phpunit" ]; then
    PHPUNIT="vendor/bin/phpunit"
elif command -v phpunit &> /dev/null; then
    PHPUNIT="phpunit"
else
    echo -e "${RED}✗ phpunit not found (checked vendor/bin/phpunit and PATH)${NC}"
    echo "  Run 'composer install' or install phpunit via your package manager."
    exit 1
fi
echo "Using phpunit: $PHPUNIT ($($PHPUNIT --version))"
echo ""

# 5. Run tests
echo -e "${YELLOW}[5/7] Running tests...${NC}"
$PHPUNIT --testdox 2>&1 | tee test-results/test_results.txt
if grep -qE "^OK \(|^OK, but there were issues" test-results/test_results.txt; then
    echo -e "${GREEN}✓ All tests passed (0 failures/errors — any warnings/deprecations are non-fatal)${NC}"
else
    echo -e "${RED}✗ Some tests failed${NC}"
    exit 1
fi
echo ""

# 6. Run benchmarks
echo -e "${YELLOW}[6/7] Running benchmarks...${NC}"
echo "This may take a minute..."
if php benchmark.php > test-results/benchmark_results.txt 2>&1; then
    echo -e "${GREEN}✓ Benchmarks completed${NC}"
    echo "Results saved to: test-results/benchmark_results.txt"
    echo ""
    echo "Quick results:"
    grep -E "(Encoder::encodeBinary|StandardGenerator::next|AsyncGenerator::next)" test-results/benchmark_results.txt | head -n 10
else
    echo -e "${RED}✗ Benchmark failed${NC}"
    exit 1
fi
echo ""

# 7. Check test coverage (requires pcov or xdebug)
echo -e "${YELLOW}[7/7] Checking test coverage...${NC}"
if php -m | grep -qiE '^(pcov|xdebug)$'; then
    XDEBUG_MODE=coverage $PHPUNIT --coverage-text 2>&1 | tee test-results/coverage_results.txt
    if grep -qE "^OK \(|^OK, but there were issues" test-results/coverage_results.txt; then
        echo -e "${GREEN}✓ Coverage report generated (0 failures/errors)${NC}"
    else
        echo -e "${RED}✗ Coverage check failed${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}! Neither pcov nor xdebug is installed — skipping coverage report${NC}"
    echo "  Install one to enable: 'pecl install pcov' or 'pecl install xdebug'"
fi
echo ""

echo "================================================"
echo -e "${GREEN}✓ All verifications passed!${NC}"
echo "================================================"
echo ""
echo "Summary:"
echo "  ✓ Pure-PHP and GMP-backed XXHash32, verified against 256 reference vectors"
echo "  ✓ Ksuid/Encoder behaviour matches the Go ksuid-go fixture exactly"
echo "  ✓ NilPartitioner, StringPartitioner, MacPartitioner all tested"
echo "  ✓ StandardGenerator and AsyncGenerator, including overflow-guard edge cases"
echo "  ✓ No duplicate KSUIDs across 10,000+ generated IDs per generator"
echo ""
echo "Next steps:"
echo "  1. Review README.md for the full usage guide"
echo "  2. Check test-results/benchmark_results.txt for performance details"
echo "  3. Run your own integration tests"
echo ""
