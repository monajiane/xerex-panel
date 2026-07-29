<?php

namespace App\Console\Commands;

use App\Models\WafRule;
use App\Services\Security\WafEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Seed the built-in WAF rules (SQL injection, XSS, path traversal, etc).
 *
 *   php artisan xerex:security:seed-waf
 *   php artisan xerex:security:seed-waf --fresh
 *
 * --fresh will deactivate existing built-in rules and recreate them, so
 * rule customisations made by the operator are preserved but obsolete
 * entries are removed.
 */
class SeedWafRules extends Command
{
    protected $signature = 'xerex:security:seed-waf
                            {--fresh : Deactivate any existing built-in rules before seeding}';

    protected $description = 'Seed the default WAF rule set (XSS, SQLi, path traversal, RCE, scanners)';

    public function handle(WafEngine $waf): int
    {
        $presets = [
            [
                'name'        => 'Block SQL injection attempts',
                'description' => 'Match common SQL meta-characters, union-select, etc.',
                'type'        => WafRule::TYPE_SQL_INJECTION,
                'target'      => WafRule::TARGET_ANY,
                'action'      => WafRule::ACTION_BLOCK,
                'priority'    => 200,
            ],
            [
                'name'        => 'Block XSS payloads',
                'description' => 'Match <script>, onerror=, javascript: and similar.',
                'type'        => WafRule::TYPE_XSS,
                'target'      => WafRule::TARGET_ANY,
                'action'      => WafRule::ACTION_BLOCK,
                'priority'    => 200,
            ],
            [
                'name'        => 'Block path traversal',
                'description' => 'Match ../, encoded variants, and known sensitive files.',
                'type'        => WafRule::TYPE_PATH_TRAVERSAL,
                'target'      => WafRule::TARGET_URI,
                'action'      => WafRule::ACTION_BLOCK,
                'priority'    => 180,
            ],
            [
                'name'        => 'Block remote code execution',
                'description' => 'Match ;ls, |cat, $(id), backtick subshells.',
                'type'        => WafRule::TYPE_RCE,
                'target'      => WafRule::TARGET_ANY,
                'action'      => WafRule::ACTION_BLOCK,
                'priority'    => 220,
            ],
            [
                'name'        => 'Block common vulnerability scanners',
                'description' => 'Match user-agents of nikto, sqlmap, nmap, nuclei, …',
                'type'        => WafRule::TYPE_USER_AGENT,
                'target'      => WafRule::TARGET_USER_AGENT,
                'action'      => WafRule::ACTION_CHALLENGE,
                'priority'    => 150,
            ],
            [
                'name'        => 'Audit admin path probing',
                'description' => 'Log requests to /wp-admin, /phpmyadmin, /.env (not blocking).',
                'type'        => WafRule::TYPE_REGEX,
                'pattern'     => '(?i)/(?:wp-admin|wp-login|phpmyadmin|\.env|\.git|xmlrpc\.php|admin\.php)',
                'target'      => WafRule::TARGET_URI,
                'action'      => WafRule::ACTION_LOG,
                'priority'    => 100,
            ],
        ];

        if ($this->option('fresh')) {
            $count = WafRule::query()
                ->whereIn('type', [WafRule::TYPE_REGEX, WafRule::TYPE_CUSTOM], 'or', false)
                ->whereIn('name', collect($presets)->pluck('name'))
                ->update(['is_active' => false]);
            $this->info("Deactivated {$count} existing built-in rules.");
        }

        $created = 0;
        foreach ($presets as $preset) {
            $pattern = $preset['pattern'] ?? WafRule::presetPatterns()[$preset['type']] ?? '';
            $rule = WafRule::updateOrCreate(
                ['name' => $preset['name']],
                array_merge($preset, [
                    'uuid'     => (string) Str::uuid(),
                    'slug'     => Str::slug($preset['name']),
                    'pattern'  => $pattern,
                    'is_active'=> true,
                ]),
            );
            $created++;
            $this->line(" • {$rule->name} → {$rule->action} [{$rule->type}]");
        }

        $waf->flushCache();
        $this->info("Seeded {$created} WAF rules.");
        return self::SUCCESS;
    }
}
