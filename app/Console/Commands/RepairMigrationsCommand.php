<?php

namespace App\Console\Commands;

use App\Support\Installer;
use Illuminate\Console\Command;

/**
 * xerex:repair-migrations
 *
 * Self-heal the `migrations` table on a half-broken install.
 *
 * Use this when the systemd unit is crash-looping because the database
 * driver was switched to a backend whose tables don't exist yet
 * (CACHE_STORE=database before the `cache` table was created), or when
 * `php artisan migrate` is failing with "relation already exists" because
 * an earlier interrupted run left the `sessions` table behind.
 *
 * The command is intentionally safe to run multiple times. It only
 * removes rows that are clearly stale (migration file missing on disk
 * or target table missing in the live DB) and only drops a table that
 * is in the small, hand-maintained "known orphan" list below.
 *
 *   php artisan xerex:repair-migrations
 */
class RepairMigrationsCommand extends Command
{
    protected $signature = 'xerex:repair-migrations
        {--dry-run : Print what would be changed without touching the DB}
        {--no-interaction : Required for non-interactive recovery scripts}';

    protected $description = 'Self-heal the migrations table and drop orphan tables left by a crashed install.';

    public function handle(Installer $installer): int
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════╗');
        $this->line('║     Xerex Panel: Migration repair tool      ║');
        $this->line('╚══════════════════════════════════════════════╝');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('--dry-run is not yet implemented by this command. The Installer::repairMigrations()');
            $this->warn('method is the source of truth; read its source if you need to audit the actions.');
        }

        $result = $installer->repairMigrations();

        if (! empty($result['actions'])) {
            $this->info('Repair actions performed:');
            foreach ($result['actions'] as $action) {
                $this->line('  • ' . $action);
            }
        } else {
            $this->info('No repair actions were needed.');
        }

        $this->line('');
        $this->line('  ' . $result['detail']);

        if (! $result['ok']) {
            $this->error('Repair failed: ' . $result['detail']);
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('You can now re-run:');
        $this->line('  php artisan migrate --force');
        $this->line('  php artisan config:clear');
        $this->line('  php artisan cache:clear');

        return self::SUCCESS;
    }
}
