<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Edge servers are the public-facing nodes that proxy traffic to origins.
     * Each edge server runs the Xerex Agent (Golang) and reports telemetry.
     */
    public function up(): void
    {
        Schema::create('edge_servers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('hostname')->unique();
            $table->string('ip_address', 45);
            $table->string('ipv6_address', 45)->nullable();
            $table->string('location')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('datacenter')->nullable();

            // Status: online, offline, degraded, maintenance, provisioning
            $table->string('status', 32)->default('provisioning');
            $table->string('agent_version')->nullable();

            // Telemetry (cached/updated by agent reports)
            $table->decimal('cpu_usage', 5, 2)->default(0);
            $table->decimal('ram_usage', 5, 2)->default(0);
            $table->decimal('disk_usage', 5, 2)->default(0);
            $table->bigInteger('bandwidth_in_bytes')->default(0);
            $table->bigInteger('bandwidth_out_bytes')->default(0);
            $table->integer('active_connections')->default(0);
            $table->integer('requests_per_second')->default(0);

            // Capacity limits (for planning)
            $table->integer('cpu_cores')->nullable();
            $table->bigInteger('ram_mb')->nullable();
            $table->bigInteger('disk_gb')->nullable();
            $table->bigInteger('bandwidth_mbps')->nullable();

            // Auth
            $table->string('agent_token', 128)->unique();
            $table->string('agent_token_hash', 128)->nullable();
            $table->timestamp('agent_token_expires_at')->nullable();

            // SSL/TLS for agent connection
            $table->boolean('agent_tls_enabled')->default(true);
            $table->string('agent_tls_fingerprint', 128)->nullable();

            $table->json('capabilities')->nullable(); // ['http2','http3','websocket','grpc',...]
            $table->json('meta')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_config_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'last_seen_at']);
            $table->index('location');
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_servers');
    }
};
