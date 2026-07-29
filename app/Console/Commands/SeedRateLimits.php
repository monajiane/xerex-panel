<?php

namespace App\Console\Commands;

use App\Models\RateLimit;
use App\Services\Security\RateLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Seed the default rate-limit policies:
 *   - Global IP: 600 req / minute per IP (block)
 *   - Global user: 5,000 req / hour per authenticated user (block)
 *   - Login paths: 10 req / minute per IP (challenge)
 */
class SeedRateLimits extends Command
{
    protected $signature = 'xerex:security:seed-rate-limits {--fresh}';
    protected $description = 'Seed the default rate-limit policies (per IP / per user / login)';

    public function handle(RateLimiter $limiter): int
    {
        $presets = [
            [
                'name'        => 'Global per-IP burst',
                'description' => 'Cap any single IP at 600 requests / minute.',
                'scope_type'  => RateLimit::SCOPE_GLOBAL,
                'scope_id'    => null,
                'limit_type'  => RateLimit::LIMIT_IP,
                'max_requests'=> 600,
                'window_seconds' => 60,
                'action'      => RateLimit::ACTION_BLOCK,
                'priority'    => 100,
            ],
            [
                'name'        => 'Global per-user hourly',
                'description' => 'Cap any authenticated user at 5,000 requests / hour.',
                'scope_type'  => RateLimit::SCOPE_GLOBAL,
                'scope_id'    => null,
                'limit_type'  => RateLimit::LIMIT_USER,
                'max_requests'=> 5000,
                'window_seconds' => 3600,
                'action'      => RateLimit::ACTION_BLOCK,
                'priority'    => 100,
            ],
            [
                'name'        => 'Auth endpoint throttling',
                'description' => '10 requests / minute to /auth/login, /auth/register, /auth/forgot.',
                'scope_type'  => RateLimit::SCOPE_GLOBAL,
                'scope_id'    => null,
                'limit_type'  => RateLimit::LIMIT_PATH,
                'max_requests'=> 10,
                'window_seconds' => 60,
                'action'      => RateLimit::ACTION_CHALLENGE,
                'priority'    => 200,
            ],
        ];

        if ($this->option('fresh')) {
            $count = RateLimit::query()
                ->whereIn('name', collect($presets)->pluck('name'))
                ->delete();
            $this->info("Removed {$count} existing default rate-limit policies.");
        }

        $created = 0;
        foreach ($presets as $preset) {
            $policy = RateLimit::updateOrCreate(
                ['name' => $preset['name']],
                array_merge($preset, [
                    'uuid'  => (string) Str::uuid(),
                    'slug'  => Str::slug($preset['name']),
                    'is_active' => true,
                ]),
            );
            $created++;
            $this->line(" • {$policy->name} → {$policy->max_requests}/{$policy->window_seconds}s [{$policy->limit_type}]");
        }

        $limiter->flushCache();
        $this->info("Seeded {$created} rate-limit policies.");
        return self::SUCCESS;
    }
}
