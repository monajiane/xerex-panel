<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Traffic logs - aggregated access logs from edge servers.
     * Use partitioning or move-to-ClickHouse at scale; default = Postgres.
     */
    public function up(): void
    {
        Schema::create('traffic_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignId('edge_server_id')->nullable()->constrained('edge_servers')->nullOnDelete();
            $table->foreignId('proxy_rule_id')->nullable()->constrained('proxy_rules')->nullOnDelete();

            $table->string('method', 8)->nullable();
            $table->string('scheme', 8)->nullable(); // http, https, ws, wss
            $table->text('url')->nullable();
            $table->string('host')->nullable();
            $table->string('path', 2048)->nullable();

            $table->integer('response_code')->nullable();
            $table->bigInteger('bytes_sent')->default(0);
            $table->bigInteger('bytes_received')->default(0);
            $table->integer('request_time_ms')->nullable();
            $table->integer('upstream_time_ms')->nullable();

            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('referer', 1024)->nullable();

            $table->string('protocol', 16)->nullable(); // HTTP/1.1, HTTP/2, HTTP/3
            $table->boolean('cached')->default(false);
            $table->string('cache_status', 32)->nullable(); // HIT, MISS, BYPASS, EXPIRED

            $table->timestamp('logged_at');
            $table->timestamps();

            // Note: production scale should use partitioning by month or move to ClickHouse
            $table->index(['domain_id', 'logged_at']);
            $table->index(['edge_server_id', 'logged_at']);
            $table->index('response_code');
            $table->index('client_ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_logs');
    }
};
