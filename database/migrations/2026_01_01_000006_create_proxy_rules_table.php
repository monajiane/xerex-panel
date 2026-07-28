<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Proxy rules define how traffic flows from edge servers to origins.
     * Each rule maps a (domain, path) to a specific origin via an edge.
     * Supports HTTP, WebSocket, gRPC, and TCP proxying.
     */
    public function up(): void
    {
        Schema::create('proxy_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('edge_server_id')->constrained('edge_servers')->cascadeOnDelete();
            $table->foreignId('origin_server_id')->constrained('origin_servers')->cascadeOnDelete();

            $table->string('name')->nullable();

            // Proxy type
            $table->string('type', 32)->default('http');
            // http, websocket, tcp, grpc, sse, redirect

            // Path matching
            $table->string('path', 1024)->default('/');
            $table->string('path_match_type', 16)->default('prefix');
            // exact, prefix, regex, default

            // Port
            $table->integer('listen_port')->default(443);

            // SSL
            $table->boolean('force_https')->default(true);
            $table->boolean('http2_enabled')->default(true);
            $table->boolean('http3_enabled')->default(false);

            // Load balancing / failover
            $table->integer('priority')->default(100);
            $table->integer('weight')->default(100);

            // State
            $table->boolean('enabled')->default(true);
            $table->boolean('is_primary')->default(false);

            // Generated config cache (so we know if regeneration needed)
            $table->text('nginx_config')->nullable();
            $table->string('config_hash', 64)->nullable();
            $table->timestamp('config_generated_at')->nullable();

            // Advanced options
            $table->json('headers_request')->nullable();  // headers to add to request
            $table->json('headers_response')->nullable(); // headers to add to response
            $table->json('cache_rules')->nullable();      // CDN cache rules
            $table->json('rate_limit')->nullable();       // rate limit config
            $table->json('access_rules')->nullable();     // ip whitelist/blacklist
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['domain_id', 'enabled']);
            $table->index(['edge_server_id', 'enabled']);
            $table->index(['origin_server_id', 'enabled']);
            $table->index(['type', 'enabled']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_rules');
    }
};
