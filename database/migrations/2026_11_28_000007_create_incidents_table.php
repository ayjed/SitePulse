<?php

use App\Enums\IncidentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create incidents table with portable open-incident uniqueness across SQLite and MySQL 8.0+.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            // Nullable lock column: populated with monitor_id while status='open' and NULL once resolved.
            // Both SQLite and MySQL 8.0 allow unlimited NULLs while strictly enforcing one open incident per monitor.
            $table->unsignedBigInteger('open_monitor_id')->nullable()->unique('incidents_open_monitor_unique');
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('root_cause', 255)->nullable();
            $table->string('status', 16)->default(IncidentStatus::OPEN->value);
            $table->timestamps();

            $table->index(['monitor_id', 'status'], 'incidents_monitor_status_idx');
            $table->index(['status', 'started_at'], 'incidents_status_started_idx');
            $table->index(['monitor_id', 'started_at'], 'incidents_monitor_started_idx');
        });
    }

    /**
     * Reverse the migrations cleanly.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
