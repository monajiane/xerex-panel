<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Eloquent\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Per-metric numeric limits for a plan.
     * value = -1 means "unlimited".
     * period = lifetime | month | day  (window the limit is measured in)
     */
    public function up(): void
    {
        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('metric', 64);          // domains, edges, origins, proxy_rules,
                                                   // bandwidth_bytes, requests, team_members, ssl_certs
            $table->bigInteger('value');           // -1 = unlimited
            $table->string('period', 16)->default('lifetime');
            $table->boolean('is_soft')->default(false); // soft = warn but don't block
            $table->timestamps();

            $table->unique(['plan_id', 'metric', 'period'], 'plan_limits_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limits');
    }
};
