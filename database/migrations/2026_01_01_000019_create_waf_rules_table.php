<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAF (Web Application Firewall) rules.
 *
 * Each rule describes a pattern to match against an incoming request and the
 * action to take when it matches. Rules are evaluated in priority order
 * (descending) and can be scoped to global, a specific domain, or a
 * specific edge server.
 *
 * Patterns are validated as PHP regex (after the WafEngine sanitises them)
 * to avoid catastrophic backtracking. Built-in "type" presets (sql, xss,
 * path_traversal, etc) auto-fill the pattern when a rule is created.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('waf_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Rule type — selects the pattern preset.
            $table->string('type', 32); // regex|sql_injection|xss|path_traversal|rce|user_agent|custom
            $table->text('pattern');    // regex body, sans delimiters

            // What part of the request to scan.
            $table->string('target', 32);  // uri|query|body|header|user_agent|any
            $table->string('target_field', 64)->nullable(); // header name when target=header

            // What to do when matched.
            $table->string('action', 16);  // allow|block|challenge|log|rate_limit

            // Higher = evaluated first.
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);

            // Optional scoping: global vs domain vs edge.
            $table->string('scope_type', 16)->nullable();   // global|domain|edge
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waf_rules');
    }
};
