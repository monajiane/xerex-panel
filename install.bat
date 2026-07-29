@echo off
REM ===========================================================================
REM Xerex Panel — Windows installer helper
REM
REM Convenience wrapper for local development on Windows. It:
REM   1. Copies .env.example to .env (if not present).
REM   2. Generates APP_KEY.
REM   3. Asks for SQLite path (default: database/database.sqlite).
REM   4. Patches .env to point at SQLite.
REM   5. Runs `php artisan xerex:install --no-migrate --no-seed` for admin setup.
REM   6. Runs `php artisan migrate --seed` to bring the DB up.
REM
REM For the full feature set, use the WSL/Linux install.sh instead.
REM ===========================================================================

setlocal ENABLEDELAYEDEXPANSION

cd /d "%~dp0"

echo.
echo === Xerex Panel - Windows installer helper ===
echo.

if not exist .env (
    echo [1/5] Copying .env.example to .env
    copy /Y .env.example .env >NUL
) else (
    echo [1/5] .env already exists; keeping it.
)

echo [2/5] Generating APP_KEY
php artisan key:generate --force >NUL

REM ---- Choose storage driver -------------------------------------------------
set "DRIVER=sqlite"
set "DBFILE=%CD%\database\database.sqlite"
if not exist "%DBFILE%" (
    > "%DBFILE%" echo. >NUL
)

echo [3/5] Configuring .env for SQLite at %DBFILE%
powershell -NoProfile -Command "(Get-Content .env) -replace '^DB_CONNECTION=.*','DB_CONNECTION=sqlite' -replace '^DB_DATABASE=.*','DB_DATABASE=%DBFILE:\=\\%' | Set-Content .env"
>> .env echo.
>> .env echo # --- patched by install.bat ---
>> .env echo DB_CONNECTION=sqlite
>> .env echo DB_DATABASE=%DBFILE:\=\\%

echo [4/5] Running migrations and seeders
php artisan migrate --force || goto :err
php artisan db:seed --force || goto :err
php artisan xerex:security:seed-waf
php artisan xerex:security:seed-rate-limits
php artisan xerex:billing:seed-plans

echo [5/5] Creating admin user
php artisan xerex:install --no-migrate --no-seed --force || goto :err

echo.
echo === Done! ===
echo Run "php artisan serve" to start the dev server.
echo Default admin: admin@xerex.local / password
endlocal
exit /b 0

:err
echo.
echo *** Installer failed. Check the output above. ***
endlocal
exit /b 1
