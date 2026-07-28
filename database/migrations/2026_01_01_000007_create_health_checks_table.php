<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Health checks record results of periodic probes against origins/edges.
     * Drives automatic failover and alerting.
     */
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Polymorphic: can be against an Origin, Edge, or Domain
            $table->morphs('checkable');

            $table->string('check_type', 32); // http, tcp, ping, dns, ssl
            $table->string('target'); // URL/host:port/hostname

            $table->string('status', 32); // up, down, degraded, timeout
            $table->integer('response_code')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('dns_ms')->nullable();
            $table->integer('connect_ms')->nullable();
            $table->integer('tls_ms')->nullable();
            $table->integer('first_byte_ms')->nullable();

            $table->text('error')->nullable();
            $table->json('response_headers')->nullable();
            $table->string('response_body_hash', 64)->nullable();

            $table->string('region')->nullable();
            $table->string('source_ip', 45)->nullable();

            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['status', 'checked_at']);
            $table->index('check_type');
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
