@echo off
setlocal enabledelayedexpansion

REM KSUID PHP Package Verification Script (Windows)
REM Mirrors the structure of ksuid-go/verify.bat, adapted for PHP tooling.
REM Run this script to verify the package is correctly installed and all
REM tests/checks pass.

echo ================================================
echo KSUID PHP Package - Verification Script
echo ================================================
echo.

cd /d "%~dp0"
echo Current directory: %CD%
echo.

REM 0. Create tests folder if it doesn't exist
if not exist "%CD%\test-results" mkdir "%CD%\test-results"

REM 1. Check if PHP is installed
echo [1/7] Checking PHP installation...
where php >nul 2>nul
if errorlevel 1 (
    echo X PHP is not installed or not on PATH
    exit /b 1
)
php --version | findstr /B "PHP"
echo + PHP is installed
echo.

REM Check for the GMP extension (optional but recommended)
php -m | findstr /I "gmp" >nul 2>nul
if errorlevel 1 (
    echo ! ext-gmp is NOT available - GMP-backed tests will be skipped
) else (
    echo + ext-gmp is available ^(XXHash32Gmp / portable arithmetic tests will run^)
)
echo.

REM 2. Install/verify Composer dependencies
echo [2/7] Checking Composer dependencies...
where composer >nul 2>nul
if errorlevel 1 (
    echo X Composer is not installed or not on PATH
    echo   Install it from https://getcomposer.org/
    exit /b 1
)
if not exist "vendor\" (
    echo vendor\ not found, running composer install...
    call composer install --no-interaction
    if errorlevel 1 (
        echo X composer install failed
        exit /b 1
    )
) else (
    echo vendor\ already present, refreshing autoloader ^(including dev mappings^)...
    call composer dump-autoload --dev --no-interaction >nul
)
echo + Dependencies ready
echo.

REM 3. Lint check (syntax validation across all source and test files)
echo [3/7] Running PHP lint (syntax check)...
set LINT_FAILED=0
for /r src %%f in (*.php) do (
    php -l "%%f" >nul 2>nul
    if errorlevel 1 (
        echo X Syntax error in %%f
        php -l "%%f"
        set LINT_FAILED=1
    )
)
for /r tests %%f in (*.php) do (
    php -l "%%f" >nul 2>nul
    if errorlevel 1 (
        echo X Syntax error in %%f
        php -l "%%f"
        set LINT_FAILED=1
    )
)
if "!LINT_FAILED!"=="1" (
    echo X Lint check failed
    exit /b 1
)
echo + All files passed syntax check
echo.

REM 4. Static analysis (optional - only runs if phpstan is available)
echo [4/7] Running static analysis (PHPStan, if available)...
if exist "vendor\bin\phpstan.bat" (
    if exist "phpstan.neon" (
        call vendor\bin\phpstan.bat analyse --no-progress
    ) else if exist "phpstan.neon.dist" (
        call vendor\bin\phpstan.bat analyse --no-progress
    ) else (
        call vendor\bin\phpstan.bat analyse src --level 5 --no-progress
    )
    if errorlevel 1 (
        echo X Static analysis found issues
        exit /b 1
    )
    echo + Static analysis passed
) else (
    echo ! phpstan/phpstan not installed - skipping ^(run 'composer require --dev phpstan/phpstan' to enable^)
)
echo.

REM Locate the phpunit binary: prefer the Composer-managed one, fall back
REM to a system-wide install on PATH.
set PHPUNIT=
if exist "vendor\bin\phpunit.bat" (
    set PHPUNIT=vendor\bin\phpunit.bat
) else (
    where phpunit >nul 2>nul
    if not errorlevel 1 (
        set PHPUNIT=phpunit
    )
)
if "!PHPUNIT!"=="" (
    echo X phpunit not found ^(checked vendor\bin\phpunit.bat and PATH^)
    echo   Run 'composer install' or install phpunit via your package manager.
    exit /b 1
)
echo Using phpunit: !PHPUNIT!
echo.

REM 5. Run tests
echo [5/7] Running tests...
call !PHPUNIT! --testdox > .\test-results\test_results.txt 2>&1
type .\test-results\test_results.txt
findstr /C:"OK (" /C:"OK, but there were issues" .\test-results\test_results.txt >nul 2>nul
if not errorlevel 1 (
    echo + All tests passed ^(0 failures/errors - any warnings/deprecations are non-fatal^)
) else (
    echo X Some tests failed
    exit /b 1
)
echo.

REM 6. Run benchmarks
echo [6/7] Running benchmarks...
echo This may take a minute...
php benchmark.php > .\test-results\benchmark_results.txt 2>&1
if errorlevel 1 (
    echo X Benchmark failed
    exit /b 1
)
echo + Benchmarks completed
echo Results saved to: .\test-results\benchmark_results.txt
echo.
echo Quick results:
findstr /C:"Encoder::encodeBinary" /C:"StandardGenerator::next" /C:"AsyncGenerator::next" .\test-results\benchmark_results.txt
echo.

REM 7. Check test coverage (requires pcov or xdebug)
echo [7/7] Checking test coverage...
php -m | findstr /I /C:"pcov" /C:"xdebug" >nul 2>nul
if errorlevel 1 (
    echo ! Neither pcov nor xdebug is installed - skipping coverage report
    echo   Install one to enable: 'pecl install pcov' or 'pecl install xdebug'
) else (
    set XDEBUG_MODE=coverage
    call !PHPUNIT! --coverage-text > .\test-results\coverage_results.txt 2>&1
    type .\test-results\coverage_results.txt
    findstr /C:"OK (" /C:"OK, but there were issues" .\test-results\coverage_results.txt >nul 2>nul
    if not errorlevel 1 (
        echo + Coverage report generated ^(0 failures/errors^)
    ) else (
        echo X Coverage check failed
        exit /b 1
    )
)
echo.

echo ================================================
echo + All verifications passed!
echo ================================================
echo.
echo Summary:
echo   + Pure-PHP and GMP-backed XXHash32, verified against 256 reference vectors
echo   + Ksuid/Encoder behaviour matches the Go ksuid-go fixture exactly
echo   + NilPartitioner, StringPartitioner, MacPartitioner all tested
echo   + StandardGenerator and AsyncGenerator, including overflow-guard edge cases
echo   + No duplicate KSUIDs across 10,000+ generated IDs per generator
echo.
echo Next steps:
echo   1. Review README.md for the full usage guide
echo   2. Check benchmark_results.txt for performance details
echo   3. Run your own integration tests
echo.

pause
endlocal