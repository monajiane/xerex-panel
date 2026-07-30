<?php

namespace App\Console\Commands;

use App\Support\Installer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * One-shot installer command.
 *
 *   php artisan xerex:install
 *   php artisan xerex:install --reset
 *
 * The command is fully driven by flags so it's friendly to non-interactive
 * provisioning (Docker, cloud-init, packer). When a flag is missing the
 * command falls back to a prompted value so the same code path is also
 * usable on a fresh dev box.
 */
class InstallCommand extends Command
{
    protected $signature = 'xerex:install
        {--db-driver= : Database driver (mysql|pgsql|sqlite)}
        {--db-host=127.0.0.1 : Database host}
        {--db-port= : Database port (driver default if blank)}
        {--db-name=xerex_panel : Database name}
        {--db-user=xerex : Database username}
        {--db-password= : Database password}
        {--app-url= : Public URL of the panel (e.g. https://panel.example.com)}
        {--admin-name="Xerex Admin" : Initial admin display name}
        {--admin-email=admin@xerex.local : Initial admin email}
        {--admin-password= : Initial admin password (random if blank)}
        {--reset : Drop the install lock and re-run from scratch}
        {--no-seed : Skip the security / plan / role seeders}
        {--no-migrate : Skip the migration step (for dev DB that is already up to date)}
        {--force : Skip the "are you sure" prompt on existing installs}';

    protected $description = 'Install / re-install the Xerex Panel: write .env, migrate, seed, create admin.';

    public function handle(Installer $installer): int
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════╗');
        $this->line('║         Xerex Panel Installer (CLI)          ║');
        $this->line('╚══════════════════════════════════════════════╝');
        $this->newLine();

        // --reset wipes the install lock and (optionally) the DB.
        if ($this->option('reset')) {
            $installer->clearLock();
            $this->warn('--reset used: install lock cleared. Re-running install.');
        }

        if ($installer->isInstalled() && ! $this->option('reset')) {
            if (! $this->option('force')) {
                $this->error('Panel is already installed.');
                $this->line('Re-run with --reset to wipe the lock, or --force to ignore.');
                return self::FAILURE;
            }
            $this->warn('--force: proceeding even though the panel is already installed.');
        }

        // ---------- 1. Requirements ----------
        $this->section('Step 1 / 6 - Server requirements');
        $checks = $installer->checkRequirements();
        foreach ($checks as $c) {
            $this->line(sprintf(
                '  %s %s%s',
                $c['ok'] ? '<fg=green>[OK]</>' : '<fg=red>[FAIL]</>',
                $c['label'],
                $c['detail'] ? "  <fg=gray>{$c['detail']}</>" : '',
            ));
        }
        if (! $installer->requirementsPass($checks)) {
            $this->error('Server requirements are not satisfied. Fix the items above and re-run.');
            return self::FAILURE;
        }
        $this->info('All requirements satisfied.');

        // ---------- 2. Database ----------
        $this->section('Step 2 / 6 - Database connection');
        $driver   = $this->option('db-driver')   ?? $this->askWithDefault('Driver', 'mysql', ['mysql', 'pgsql', 'sqlite']);
        $host     = $this->option('db-host')     ?? $this->ask('Database host', '127.0.0.1');
        $portRaw  = $this->option('db-port');
        $port     = $portRaw !== null ? (int) $portRaw : (int) $this->ask('Database port', (string) ($driver === 'pgsql' ? 5432 : 3306));
        $dbName   = $this->option('db-name')     ?? $this->ask('Database name', 'xerex_panel');
        $dbUser   = $this->option('db-user')     ?? $this->ask('Database user', 'xerex');
        $dbPass   = $this->option('db-password') ?? $this->secret('Database password');

        $probe = $installer->testDatabaseConnection($driver, $host, $port, $dbName, $dbUser, $dbPass);
        if (! $probe['ok']) {
            $this->error('Could not connect to the database:');
            $this->line('  ' . $probe['detail']);
            $this->line('Create the database first (or use --db-name for an existing one), then re-run.');
            return self::FAILURE;
        }
        $this->info("Connected to {$driver}://{$dbUser}@{$host}:{$port}/{$dbName}");

        $installer->writeEnvDatabase($driver, $host, $port, $dbName, $dbUser, $dbPass);
        $this->info('Wrote DB_* keys into .env');

        // ---------- 3. APP_URL + APP_KEY ----------
        $this->section('Step 3 / 6 - Application URL & key');
        $appUrl = $this->option('app-url') ?? $this->ask('Public URL of the panel', 'http://localhost:8000');
        $installer->writeEnvApp(rtrim($appUrl, '/'), 'production');
        $installer->applySaneDefaults('production');
        $this->info("Wrote APP_URL={$appUrl}");

        // Reload .env so the migration step sees the new DB config.
        Artisan::call('config:clear');
        $appKey = $installer->ensureAppKey();
        $this->info('APP_KEY: ' . substr($appKey, 0, 16) . '...');

        // ---------- 4. Migrations ----------
        if ($this->option('no-migrate')) {
            $this->warn('Skipping migrations (--no-migrate).');
        } else {
            $this->section('Step 4 / 6 - Migrations');

            // The cache/sessions/jobs tables do not exist yet on a fresh
            // install. If .env still says CACHE_STORE=database, every
            // artisan command that touches the cache (including
            // config:clear) will explode. Temporarily force the
            // file/sync backends so the migration step can finish, then
            // switch back to the database backends in step 5.
            $installer->useFileBackedStorageDuringMigrate();
            $this->line('  <fg=gray>temporarily switched CACHE/SESSION/QUEUE to file/sync for the migration step</>');

            $result = $installer->runMigrations();
            if (! $result['ok']) {
                $this->error('Migrations failed:');
                $this->line($result['detail']);
                $this->line('Hint: re-run with `--reset` to clear the install lock and try again.');
                return self::FAILURE;
            }
            $this->info('Migrations applied.');

            // Now that the cache/sessions/jobs tables exist, restore
            // the production-grade database backends.
            $installer->applySaneDefaults('production');
            Artisan::call('config:clear');
            $this->line('  <fg=gray>restored CACHE/SESSION/QUEUE to database backend</>');
        }

        // ---------- 5. Seeders ----------
        if ($this->option('no-seed')) {
            $this->warn('Skipping seeders (--no-seed).');
        } else {
            $this->section('Step 5 / 6 - Seeders (roles, plans, WAF, rate-limits)');
            $result = $installer->runSeeders();
            if (! $result['ok']) {
                $this->error('Database seeder failed:');
                $this->line($result['detail']);
                return self::FAILURE;
            }
            $sec = $installer->runSecuritySeeders();
            foreach ($sec['detail'] as $cmd => $out) {
                $this->line("  → <fg=cyan>{$cmd}</>  done");
            }
            $this->info('Seeders finished.');
        }

        // ---------- 6. Admin user ----------
        $this->section('Step 6 / 6 - Initial admin user');
        $adminName  = $this->option('admin-name')  ?? $this->ask('Admin name', 'Xerex Admin');
        $adminEmail = $this->option('admin-email') ?? $this->ask('Admin email', 'admin@xerex.local');
        $adminPass  = $this->option('admin-password') ?? $this->secret('Admin password (min 8 chars)');
        if (empty($adminPass)) {
            $adminPass = bin2hex(random_bytes(8));
            $this->warn("Generated random admin password: {$adminPass}");
        }
        try {
            $installer->createAdmin($adminName, $adminEmail, $adminPass);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info("Admin user {$adminEmail} ready.");

        // Lock and finish.
        $installer->writeLock([
            'admin_email' => $adminEmail,
            'app_url'     => $appUrl,
        ]);
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   Installation complete. Panel is ready.     ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->newLine();
        $this->line("Login at:   <fg=cyan>{$appUrl}/login</>");
        $this->line("Admin user: <fg=cyan>{$adminEmail}</>");
        $this->line('');
        $this->comment('Next steps:');
        $this->line('  • Run `php artisan storage:link` if you serve files from public/');
        $this->line('  • Run `php artisan horizon` for queue workers (or use systemd)');
        $this->line('  • Run `npm run build` to compile the Vue SPA');
        $this->line('  • Visit /admin/security/* to configure WAF + IP lists + rate limits');

        return self::SUCCESS;
    }

    /**
     * Render a small section header.
     */
    private function section(string $label): void
    {
        $this->newLine();
        $this->line("<fg=yellow>── {$label} ──</>");
    }

    /**
     * Prompt with a default value, validating against an allowed list.
     */
    private function askWithDefault(string $label, string $default, array $allowed): string
    {
        $answer = $this->anticipate("{$label} [" . implode('|', $allowed) . "]", $allowed, $default);
        return in_array($answer, $allowed, true) ? $answer : $default;
    }
}
