<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add failover_group + failover_priority to origin_servers so multiple
 * origins can share a logical group, ordered by priority. The health
 * check service promotes the next healthy origin when the leader fails.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('origin_servers', function (Blueprint $table) {
            $table->string('failover_group', 64)->nullable()->after('meta');
            $table->unsignedInteger('failover_priority')->default(0)->after('failover_group');
            $table->index('failover_group');
        });
    }

    public function down(): void
    {
        Schema::table('origin_servers', function (Blueprint $table) {
            $table->dropIndex(['failover_group']);
            $table->dropColumn(['failover_group', 'failover_priority']);
        });
    }
};
