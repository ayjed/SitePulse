<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track per-monitor TLS certificate warning state and renewal cycle cleanly.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->timestamp('tls_warning_expires_at')->nullable()->after('tls_last_checked_at');
            $table->unsignedInteger('tls_warning_cycle')->default(1)->after('tls_warning_expires_at');
        });
    }

    /**
     * Reverse the migration cleanly.
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['tls_warning_expires_at', 'tls_warning_cycle']);
        });
    }
};
