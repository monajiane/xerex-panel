<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * SSL certificates - tracks Let's Encrypt and manual certificates.
     * Stores metadata only; the actual cert files live in storage/certs.
     */
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->string('common_name');
            $table->json('subject_alt_names')->nullable(); // SAN list

            $table->string('provider', 32)->default('letsencrypt'); // letsencrypt, custom, selfsigned
            $table->string('status', 32)->default('pending'); // pending, provisioning, active, expiring, expired, error
            $table->text('error')->nullable();

            $table->string('cert_path')->nullable();
            $table->string('key_path')->nullable();
            $table->string('chain_path')->nullable();

            $table->string('issuer')->nullable();
            $table->string('serial_number', 128)->nullable();
            $table->string('fingerprint_sha256', 128)->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('last_renewal_attempt_at')->nullable();
            $table->integer('renewal_failures')->default(0);

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at']);
            $table->index('common_name');
            $table->index('domain_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
