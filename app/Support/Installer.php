<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Core installer logic shared between the Artisan command (xerex:install)
 * and the web installer wizard.
 *
 * Responsibilities:
 *   1. Verify server requirements (PHP version + extensions).
 *   2. Write database credentials into .env.
 *   3. Verify DB connectivity and run migrations.
 *   4. Seed roles, default plans, WAF rules, and rate-limit policies.
 *   5. Create the first admin user.
 *   6. Generate APP_KEY if missing.
 *   7. Stamp storage/installed.lock so the app knows it is ready.
 *
 * The class is intentionally framework-agnostic at the boundary: every
 * long-running step is wrapped in a public method that returns a
 * structured status array. Callers (CLI or HTTP) decide how to render
 * progress and handle errors.
 */
class Installer
{
    /** Absolute path of the lock file that flags a successful install. */
    public const LOCK_FILE = 'installed.lock';

    /** Minimum supported PHP version. */
    public const PHP_MIN = '8.3';

    /** Extensions the panel needs at runtime. */
    public const REQUIRED_EXTENSIONS = [
        'pdo', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json',
        'bcmath', 'fileinfo', 'curl', 'zip', 'gd', 'intl',
    ];

    /**
     * Build the list of server requirements. Each entry has a human label,
     * a boolean pass/fail flag, and an optional message. The web wizard
     * renders this as a checklist; the CLI command prints it directly.
     *
     * @return array<int, array{label:string, ok:bool, detail:string}>
     */
    public function checkRequirements(): array
    {
        $checks = [];

        // PHP version
        $checks[] = [
            'label'  => sprintf('PHP >= %s', self::PHP_MIN),
            'ok'     => version_compare(PHP_VERSION, self::PHP_MIN, '>='),
            'detail' => 'Detected PHP ' . PHP_VERSION,
        ];

        // Required extensions
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'label'  => sprintf('PHP extension: %s', $ext),
                'ok'     => $loaded,
                'detail' => $loaded ? 'loaded' : 'missing - install php-' . $ext,
            ];
        }

        // Storage & bootstrap/cache writable
        foreach (['storage', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $path) {
            $abs = base_path($path);
            $writable = is_dir($abs) && is_writable($abs);
            $checks[] = [
                'label'  => sprintf('Writable: %s', $path),
                'ok'     => $writable,
                'detail' => $writable ? 'ok' : 'run: chmod -R ug+rw ' . $path,
            ];
        }

        // Composer dependencies installed
        $checks[] = [
            'label'  => 'Composer dependencies installed',
            'ok'     => is_dir(base_path('vendor')) && file_exists(base_path('vendor/autoload.php')),
            'detail' => is_dir(base_path('vendor'))
                ? 'vendor/ present'
                : 'run: composer install --no-dev --optimize-autoloader',
        ];

        return $checks;
    }

    /**
     * Are all requirements satisfied?
     */
    public function requirementsPass(array $checks): bool
    {
        foreach ($checks as $c) {
            if (! $c['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Verify the database is reachable using the current .env credentials.
     * Used by the web installer before committing the connection choice.
     */
    public function testDatabaseConnection(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
    ): array {
        try {
            $config = $this->configForDriver($driver, $host, $port, $database, $username, $password);
            config([
                'database.connections._installer_probe' => $config,
            ]);
            DB::purge('_installer_probe');
            DB::connection('_installer_probe')->getPdo();
            return ['ok' => true, 'detail' => 'Connection successful.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * Persist database credentials into the .env file.
     * Existing keys are replaced; missing keys are appended.
     * APP_KEY, APP_URL, APP_ENV, etc. are left untouched.
     */
    public function writeEnvDatabase(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password
    ): void {
        $this->writeEnvKeys([
            'DB_CONNECTION' => $driver,
            'DB_HOST'       => $host,
            'DB_PORT'       => (string) $port,
            'DB_DATABASE'   => $database,
            'DB_USERNAME'   => $username,
            'DB_PASSWORD'   => $password,
        ]);
    }

    /**
     * Persist APP_URL / APP_ENV into the .env file. The web installer calls
     * this after the user fills in the URL field on step 3.
     */
    public function writeEnvApp(string $appUrl, string $appEnv = 'production'): void
    {
        $this->writeEnvKeys([
            'APP_URL' => $appUrl,
            'APP_ENV' => $appEnv,
        ]);
    }

    /**
     * Set (or regenerate) APP_KEY. If the existing value is a valid base64
     * 32-byte key we leave it alone so repeated installer runs are idempotent.
     */
    public function ensureAppKey(): string
    {
        $existing = (string) config('app.key');
        if ($existing && Str::startsWith($existing, 'base64:') && strlen(base64_decode(substr($existing, 7))) === 32) {
            return $existing;
        }
        Artisan::call('key:generate', ['--force' => true]);
        // Refresh the in-memory config so the caller sees the new key.
        return (string) config('app.key');
    }

    /**
     * Run `php artisan migrate --force`. Wrapped here so the CLI/UI can
     * catch SQL errors (e.g. the user picked a DB that doesn't exist)
     * and report them in a structured way.
     */
    public function runMigrations(): array
    {
        // Self-heal before we touch anything: an earlier crashed install
        // may have left the `migrations` table in a bad state (failed
        // rows, or a half-applied migration recorded as run while the
        // actual table is missing). The repair step clears those out so
        // the upcoming `migrate` can finish cleanly.
        $repair = $this->repairMigrations();
        if (! $repair['ok']) {
            return ['ok' => false, 'detail' => 'pre-migrate repair failed: ' . $repair['detail']];
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            return ['ok' => true, 'detail' => Artisan::output()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * Self-heal a broken `migrations` table.
     *
     * Two things can go wrong on a re-run of the installer:
     *
     *   1. A migration failed halfway, Laravel wrote a `batch` row for it
     *      without ever finishing the table. `migrate` then refuses to
     *      run because the row says it's already applied. We delete any
     *      row whose migration file is missing or whose target table is
     *      missing on the live database.
     *
     *   2. The legacy users migration (000001) used to also create the
     *      `sessions` table. If a previous install was on that old code,
     *      the table is now present but recorded under the users
     *      migration. The dedicated sessions migration would then fail
     *      with "relation already exists". We detect that exact case and
     *      drop the orphan `sessions` table so the dedicated migration
     *      can re-create it cleanly.
     *
     * Returns ['ok' => bool, 'detail' => string, 'actions' => string[]].
     * The actions list is mostly for logging so the operator can see
     * exactly what the repair step changed.
     */
    public function repairMigrations(): array
    {
        $actions = [];

        try {
            // The `migrations` table itself may not exist yet on a
            // completely fresh DB; that's fine, nothing to repair.
            if (! Schema::hasTable('migrations')) {
                return ['ok' => true, 'detail' => 'migrations table absent, nothing to repair', 'actions' => $actions];
            }

            $applied = DB::table('migrations')->get(['id', 'migration'])->keyBy('migration');

            // --- (1) drop rows for migration files that no longer exist
            $diskMigrations = [];
            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                $diskMigrations[] = pathinfo($file, PATHINFO_FILENAME);
            }

            foreach ($applied as $row) {
                if (! in_array($row->migration, $diskMigrations, true)) {
                    DB::table('migrations')->where('id', $row->id)->delete();
                    $actions[] = "removed stale migrations row for missing file: {$row->migration}";
                }
            }

            // --- (2) drop rows whose target table is missing on the live DB
            // We map a small whitelist of migration -> table names. The
            // list is intentionally tiny: only the framework-level
            // tables that we ship. Domain tables are owned by later
            // migrations and are not safe to guess about here.
            $expected = [
                '2025_12_31_235957_create_jobs_table'         => 'jobs',
                '2025_12_31_235958_create_failed_jobs_table'  => 'failed_jobs',
                '2025_12_31_235959_create_sessions_table'     => 'sessions',
                '2025_12_31_235960_create_cache_table'        => 'cache',
                '2026_01_01_000001_create_users_table'        => 'users',
            ];

            foreach ($expected as $migration => $table) {
                if ($applied->has($migration) && ! Schema::hasTable($table)) {
                    DB::table('migrations')->where('migration', $migration)->delete();
                    $actions[] = "removed migrations row for missing table: {$table} ({$migration})";
                }
            }

            // --- (3) special case: orphan `sessions` table from the old
            // users migration. If the table is present but the dedicated
            // sessions migration has not been recorded as run, the
            // dedicated migration will fail. Drop the table; the
            // dedicated migration (which is now also idempotent via
            // hasTable guards) will recreate it.
            if (Schema::hasTable('sessions') && ! $applied->has('2025_12_31_235959_create_sessions_table')) {
                Schema::drop('sessions');
                $actions[] = 'dropped orphan sessions table (legacy users migration)';
            }

            return ['ok' => true, 'detail' => implode('; ', $actions) ?: 'no repair needed', 'actions' => $actions];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'actions' => $actions];
        }
    }

    /**
     * Run the project's database seeders. Idempotent: each seeder uses
     * firstOrCreate so calling it twice won't duplicate rows.
     */
    public function runSeeders(): array
    {
        try {
            Artisan::call('db:seed', ['--force' => true]);
            return ['ok' => true, 'detail' => Artisan::output()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * Run the security domain seeders (WAF presets + rate-limit policies).
     * Pulled out so the installer can run them after the global seeder.
     */
    public function runSecuritySeeders(): array
    {
        $output = [];
        foreach (['xerex:security:seed-waf', 'xerex:security:seed-rate-limits', 'xerex:billing:seed-plans'] as $cmd) {
            try {
                Artisan::call($cmd);
                $output[$cmd] = Artisan::output();
            } catch (\Throwable $e) {
                $output[$cmd] = 'ERROR: ' . $e->getMessage();
            }
        }
        return ['ok' => true, 'detail' => $output];
    }

    /**
     * Create (or update) the first admin user. The web wizard passes the
     * admin details in via form; the CLI command either prompts or uses
     * the values from --admin-* flags.
     *
     * Returns the user model on success.
     */
    public function createAdmin(string $name, string $email, string $password): User
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email: {$email}");
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Admin password must be at least 8 characters.');
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'      => $name,
                'password'  => Hash::make($password),
                'is_admin'  => true,
                'is_active' => true,
            ]
        );

        // If the user existed but is not yet an admin or uses a different
        // password, bring them up to date.
        if (! $user->is_admin || ! $user->is_active) {
            $user->is_admin  = true;
            $user->is_active = true;
            $user->save();
        }

        if (! $user->hasRole('admin')) {
            $user->assignRole('admin');
        }

        return $user;
    }

    /**
     * Drop the install lock. Used by `xerex:install --reset` so a
     * maintainer can re-run the installer on an existing install.
     */
    public function clearLock(): void
    {
        $abs = storage_path(self::LOCK_FILE);
        if (file_exists($abs)) {
            unlink($abs);
        }
    }

    /**
     * Has the panel been installed already?
     */
    public function isInstalled(): bool
    {
        return file_exists(storage_path(self::LOCK_FILE));
    }

    /**
     * Stamp the install lock file with a small JSON payload. The payload
     * is purely informational (install timestamp + installer version)
     * and is read back by /install's "already installed" landing page.
     */
    public function writeLock(array $extra = []): void
    {
        $payload = array_merge([
            'installed_at' => now()->toIso8601String(),
            'php'          => PHP_VERSION,
            'installer'    => 'xerex:install',
        ], $extra);
        File::put(
            storage_path(self::LOCK_FILE),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    }

    /**
     * Resolve and persist a few best-practice defaults so the very first
     * session of the panel "just works":
     *   - APP_DEBUG=false  (in production env)
     *   - QUEUE_CONNECTION=database  (no Redis dependency at first boot)
     *   - SESSION_DRIVER=database    (same reason)
     *   - CACHE_STORE=database       (same reason)
     */
    public function applySaneDefaults(string $appEnv): void
    {
        $updates = [
            'SESSION_DRIVER'    => 'database',
            'QUEUE_CONNECTION'  => 'database',
            'CACHE_STORE'       => 'database',
            'BROADCAST_CONNECTION' => 'log',
        ];
        if ($appEnv === 'production') {
            $updates['APP_DEBUG'] = 'false';
        }
        $this->writeEnvKeys($updates);
    }

    /**
     * Force the storage drivers that require a database table to fall back
     * to their file-based equivalents. Used right before the migration
     * step on a fresh install so that artisan commands like config:clear
     * and cache:clear do not crash with "relation does not exist" while
     * the cache/sessions tables are still missing.
     *
     * The caller is expected to call applySaneDefaults() after the
     * migrations have run, to put the storage drivers back onto the
     * database backend.
     */
    public function useFileBackedStorageDuringMigrate(): void
    {
        $this->writeEnvKeys([
            'CACHE_STORE'      => 'file',
            'SESSION_DRIVER'   => 'file',
            'QUEUE_CONNECTION' => 'sync',
        ]);
    }

    // ============================================================
    //                       Internals
    // ============================================================

    /**
     * Build a database connection config array for the given driver.
     */
    private function configForDriver(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password
    ): array {
        $base = [
            'driver'   => $driver,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset'  => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'   => '',
            'strict'   => true,
        ];

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $base['host']     = $host;
            $base['port']     = $port;
            $base['database'] = $database;
        }

        if ($driver === 'sqlite') {
            // For sqlite the "database" entry is the file path.
            $base['database'] = $database;
            $base['foreign_key_constraints'] = true;
        }

        return $base;
    }

    /**
     * Write (or replace) keys in the .env file. We do a line-by-line replace
     * so existing keys keep their position in the file; new keys are
     * appended to the bottom.
     */
    private function writeEnvKeys(array $kv): void
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $example = base_path('.env.example');
            if (file_exists($example)) {
                copy($example, $envPath);
            } else {
                file_put_contents($envPath, '');
            }
        }

        $contents = file_get_contents($envPath);
        $lines    = explode("\n", $contents);
        $written  = [];

        foreach ($lines as $i => $line) {
            foreach ($kv as $key => $value) {
                $prefix = $key . '=';
                if (str_starts_with($line, $prefix) || str_starts_with($line, $key . ' =')) {
                    $lines[$i] = $key . '=' . $this->quoteEnv($value);
                    $written[$key] = true;
                }
            }
        }

        foreach ($kv as $key => $value) {
            if (! isset($written[$key])) {
                $lines[] = $key . '=' . $this->quoteEnv($value);
            }
        }

        file_put_contents($envPath, implode("\n", $lines));
    }

    /**
     * Wrap a value in double quotes if it contains whitespace or special
     * characters. Empty / simple values are written unquoted.
     */
    private function quoteEnv(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\-\/]+$/', $value)) {
            return $value;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
