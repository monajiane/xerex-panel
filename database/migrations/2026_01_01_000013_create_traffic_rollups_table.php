<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traffic rollups – pre-aggregated hourly counters per (edge, domain,
 * proxy_rule, hour). Reading the dashboard never has to scan the raw
 * traffic_logs table; rollups are kept up-to-date by TrafficAggregator.
 *
 * For very high volume, swap this table for ClickHouse / TimescaleDB
 * – the public surface of the aggregator is the same.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('traffic_rollups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('edge_server_id')->nullable()->constrained('edge_servers')->nullOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignId('proxy_rule_id')->nullable()->constrained('proxy_rules')->nullOnDelete();

            $table->dateTime('bucket');                // start of the hour, UTC
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('cache_misses')->default(0);
            $table->unsignedInteger('status_2xx')->default(0);
            $table->unsignedInteger('status_3xx')->default(0);
            $table->unsignedInteger('status_4xx')->default(0);
            $table->unsignedInteger('status_5xx')->default(0);
            $table->unsignedInteger('request_time_sum_ms')->default(0);
            $table->unsignedInteger('upstream_time_sum_ms')->default(0);
            $table->unsignedInteger('unique_clients')->default(0);
            $table->timestamps();

            $table->unique(['edge_server_id', 'domain_id', 'proxy_rule_id', 'bucket'], 'traffic_rollups_unique');
            $table->index(['bucket']);
            $table->index(['domain_id', 'bucket']);
            $table->index(['edge_server_id', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_rollups');
    }
};
