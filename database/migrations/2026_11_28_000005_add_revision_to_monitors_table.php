<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add persistent optimistic-concurrency revision counter to monitors for same-second race protection.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->default(1)->after('tls_last_checked_at');
        });
    }

    /**
     * Reverse the migration cleanly.
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
