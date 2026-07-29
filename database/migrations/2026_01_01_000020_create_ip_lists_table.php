<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP allow / block lists.
 *
 * Entries are stored as CIDR strings (single IPs are normalised to /32 or /128
 * by the IpListService). Each row has a list_type — "allow" or "block" — and
 * can be optionally scoped to a domain or edge. The IpListCheck middleware
 * consults these tables on every protected request.
 *
 * expires_at is used for temporary bans (e.g. failed-login throttling).
 * source is free-form (manual, feed:abuseipdb, fail2ban, etc) so we can audit
 * which provider added each entry.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ip_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('cidr', 64);              // e.g. "1.2.3.0/24" or "::1/128"
            $table->string('list_type', 8);          // allow|block
            $table->text('reason')->nullable();
            $table->string('source', 64)->nullable();

            // Optional scoping: null = applies globally.
            $table->string('scope_type', 16)->nullable();   // global|domain|edge
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['list_type', 'cidr']);
            $table->index(['scope_type', 'scope_id']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_lists');
    }
};
