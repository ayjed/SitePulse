<?php

use App\Enums\MonitorStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the owner-scoped HTTP/HTTPS monitors table compatible with SQLite and MySQL 8.0+.
     */
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 2048);
            $table->unsignedSmallInteger('check_interval_minutes')->default(5);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('expected_status_code_min')->default(200);
            $table->unsignedSmallInteger('expected_status_code_max')->default(299);
            $table->boolean('is_active')->default(true);
            $table->string('status', 32)->default(MonitorStatus::OPERATIONAL->value);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->timestamp('tls_expires_at')->nullable();
            $table->timestamp('tls_last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active'], 'monitors_user_active_idx');
            $table->index(['is_active', 'status', 'last_checked_at'], 'monitors_sched_last_checked_idx');
            $table->index(['is_active', 'next_check_at'], 'monitors_sched_next_check_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
