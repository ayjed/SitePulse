<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create check_results table with portable indexes and cascade foreign key for SQLite and MySQL 8.0+.
     */
    public function up(): void
    {
        Schema::create('check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('failure_reason', 40)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->unsignedSmallInteger('redirects_followed')->default(0);
            $table->boolean('body_truncated')->default(false);
            $table->timestamp('checked_at');

            $table->index(['monitor_id', 'checked_at'], 'check_results_monitor_checked_idx');
            $table->index(['checked_at'], 'check_results_checked_at_idx');
        });
    }

    /**
     * Reverse the migrations cleanly.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_results');
    }
};
