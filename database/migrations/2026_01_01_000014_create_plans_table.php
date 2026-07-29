<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Eloquent\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Plan = the catalog row a customer subscribes to.
     * One plan -> many plan_limits (one row per metric).
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 64)->unique();          // free / pro / business / enterprise
            $table->string('name', 128);                   // human label
            $table->text('description')->nullable();
            $table->string('tagline', 255)->nullable();   // short marketing tagline
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('billing_period', 16)->default('month'); // month | year
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);   // show on signup page
            $table->boolean('is_default')->default(false); // auto-assigned on signup
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('sort_order')->default(100);
            $table->json('features')->nullable();         // {support:"email", sla:"99.9%"}
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
