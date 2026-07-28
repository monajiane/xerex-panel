<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * DNS records - mirrors the state of the upstream DNS provider.
     * Each record can be linked to a domain, edge, or origin server.
     */
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            $table->string('zone')->unique();
            $table->string('provider', 32)->default('powerdns');
            $table->string('provider_zone_id')->nullable();
            $table->string('status', 32)->default('pending'); // pending, active, error
            $table->text('error')->nullable();
            $table->json('soa')->nullable();
            $table->json('nameservers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignId('edge_server_id')->nullable()->constrained('edge_servers')->nullOnDelete();

            $table->string('name'); // subdomain or @ for apex
            $table->string('type', 16); // A, AAAA, CNAME, TXT, MX, NS, SRV, CAA
            $table->text('value');
            $table->integer('ttl')->default(300);
            $table->integer('priority')->nullable(); // for MX/SRV
            $table->json('meta')->nullable();

            $table->string('provider_record_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['dns_zone_id', 'type']);
            $table->index(['name', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
        Schema::dropIfExists('dns_zones');
    }
};
