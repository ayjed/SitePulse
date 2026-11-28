<?php

use App\Enums\MonitorStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Switch new monitors to unchecked `unknown` default status and update active unchecked rows.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->string('status', 32)->default(MonitorStatus::UNKNOWN->value)->change();
        });

        DB::table('monitors')
            ->where('is_active', true)
            ->where('status', MonitorStatus::OPERATIONAL->value)
            ->whereNull('last_checked_at')
            ->where('consecutive_failures', 0)
            ->where('consecutive_successes', 0)
            ->update(['status' => MonitorStatus::UNKNOWN->value]);
    }

    /**
     * Restore the Phase 02 Task 13 default monitor status for clean rollback compatibility.
     */
    public function down(): void
    {
        DB::table('monitors')
            ->where('status', MonitorStatus::UNKNOWN->value)
            ->where('is_active', true)
            ->update(['status' => MonitorStatus::OPERATIONAL->value]);

        DB::table('monitors')
            ->where('status', MonitorStatus::UNKNOWN->value)
            ->where('is_active', false)
            ->update(['status' => MonitorStatus::PAUSED->value]);

        Schema::table('monitors', function (Blueprint $table) {
            $table->string('status', 32)->default(MonitorStatus::OPERATIONAL->value)->change();
        });
    }
};
