<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Origin servers are the backends that the edges proxy to.
     * Can be web servers, application servers, or upstream services.
     */
    public function up(): void
    {
        Schema::create('origin_servers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('host'); // hostname or IP
            $table->integer('port')->default(80);
            $table->string('protocol', 16)->default('http'); // http, https, grpc, tcp
            $table->string('upstream_type', 32)->default('web'); // web, websocket, tcp, grpc, sse

            // SSL
            $table->boolean('ssl_enabled')->default(false);
            $table->boolean('ssl_verify')->default(true);
            $table->string('ssl_sni')->nullable();

            // Load balancing weights (for multiple origins of same domain)
            $table->integer('weight')->default(100);
            $table->integer('max_fails')->default(3);
            $table->integer('fail_timeout')->default(10); // seconds

            // Health check
            $table->boolean('health_check_enabled')->default(true);
            $table->string('health_check_path', 255)->nullable();
            $table->integer('health_check_interval')->default(30);
            $table->integer('health_check_timeout')->default(5);
            $table->integer('health_check_expected_status')->default(200);
            $table->boolean('health_check_use_tls')->default(false);
            $table->string('health_status', 32)->default('unknown'); // up, down, unknown
            $table->timestamp('last_health_check_at')->nullable();
            $table->integer('consecutive_failures')->default(0);

            // Connection limits
            $table->integer('max_connections')->nullable();
            $table->integer('connect_timeout')->default(5);
            $table->integer('read_timeout')->default(60);
            $table->integer('send_timeout')->default(60);

            $table->json('headers')->nullable(); // custom headers to send
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['host', 'port']);
            $table->index(['health_status', 'is_active']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('origin_servers');
    }
};
