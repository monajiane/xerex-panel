<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate limit policies.
 *
 * A policy is "for requests matching this scope/key, allow at most
 * `max_requests` within `window_seconds`; if exceeded, take `action`."
 *
 * scope_type / scope_id is the high-level target (global, domain, edge,
 * user), and `limit_type` chooses what to bucket by — ip, user, path, or
 * domain. The combination uniquely identifies a policy.
 *
 * burst_multiplier allows a short grace multiplier (e.g. 1.5x) for the
 * first request of a window to absorb legitimate spikes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rate_limits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('scope_type', 16);        // global|domain|edge|user
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->string('limit_type', 16);        // ip|user|path|domain
            $table->unsignedInteger('max_requests');
            $table->unsignedInteger('window_seconds');
            $table->decimal('burst_multiplier', 4, 2)->default(1.00);

            $table->string('action', 16);            // block|challenge|throttle|log
            $table->boolean('is_active')->default(true);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'limit_type', 'slug'], 'rate_limits_unique');
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limits');
    }
};
