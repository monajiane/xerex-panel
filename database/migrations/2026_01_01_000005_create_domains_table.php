<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Domains represent user-owned domain names that route through edges.
     * A domain maps to one or more proxy rules and tracks DNS/SSL state.
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('domain')->unique();
            $table->string('registrar')->nullable();
            $table->date('expires_at')->nullable();

            // DNS state
            $table->string('dns_status', 32)->default('pending'); // pending, configuring, active, error
            $table->timestamp('dns_verified_at')->nullable();
            $table->string('dns_provider', 32)->default('powerdns'); // powerdns, route53, cloudflare, manual

            // SSL state
            $table->string('ssl_status', 32)->default('pending'); // pending, provisioning, active, expiring, expired, error
            $table->string('ssl_provider', 32)->default('letsencrypt');
            $table->timestamp('ssl_issued_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('ssl_fingerprint', 128)->nullable();
            $table->boolean('wildcard')->default(false);
            $table->boolean('auto_renew')->default(true);

            $table->boolean('is_active')->default(true);
            $table->boolean('cdn_enabled')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            $table->index('dns_status');
            $table->index('ssl_status');
            $table->index('ssl_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
