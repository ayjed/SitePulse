<?php

use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create durable incident notification outbox and delivery audit table.
     */
    public function up(): void
    {
        Schema::create('incident_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('event_key', 120)->unique('incident_notifications_event_key_unique');
            $table->string('channel', 32)->default('mail');
            $table->string('recipient', 255);
            $table->string('subject', 200);
            $table->string('delivery_status', 16)->default(NotificationDeliveryStatus::PENDING->value);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->unsignedBigInteger('claim_version')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->boolean('is_success')->default(false);
            $table->string('error_message', 255)->nullable();
            $table->string('error_summary', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('tls_expires_at')->nullable();
            $table->timestamps();

            // Enforces at-most-once alert record per (incident_id, type) while permitting NULL incident_id for TLS warnings.
            $table->unique(['incident_id', 'type'], 'incident_notifications_incident_type_unique');
            $table->index(['delivery_status', 'next_attempt_at', 'claimed_at'], 'incident_notifications_outbox_idx');
            $table->index(['monitor_id', 'type', 'created_at'], 'incident_notifications_monitor_type_idx');
        });
    }

    /**
     * Reverse the migration cleanly.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_notifications');
    }
};
