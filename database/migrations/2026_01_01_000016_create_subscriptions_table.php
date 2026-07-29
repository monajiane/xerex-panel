<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Eloquent\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A user subscribes to one plan at a time. status drives the lifecycle:
     *
     *   trialing   -> active (after trial_ends_at)
     *   active     -> past_due (payment failed)
     *   active     -> canceled (user requested cancel)
     *   canceled   -> expired (current_period_end passed)
     *   past_due   -> active (payment retried OK)
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();

            $table->string('status', 24)->default('active'); // trialing|active|past_due|canceled|expired
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('meta')->nullable(); // payment provider IDs, etc.
            $table->timestamps();

            // One active subscription per user (partial unique via app-layer check + this index)
            $table->index(['user_id', 'status']);
            $table->index('current_period_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
