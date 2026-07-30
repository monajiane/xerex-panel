<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Track metered usage per (user, metric, period).
     * `period_start` / `period_end` bound the rolling window.
     * The unique key is (user_id, metric, period_start) so we get one row
     * per metric per period, updated in place by the UsageMeter.
     */
    public function up(): void
    {
        Schema::create('usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('metric', 64);                  // bandwidth_bytes, requests, ssl_certs
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('last_incremented_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'metric', 'period_start'], 'usages_unique');
            $table->index(['user_id', 'metric', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usages');
    }
};
