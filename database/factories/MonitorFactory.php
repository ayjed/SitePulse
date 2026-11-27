<?php

namespace Database\Factories;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * Define the model's default state using non-probing synthetic `.test` URLs.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'user_id' => User::factory()->owner(),
            'name' => 'Site '.ucfirst(str_replace('-', ' ', $slug)),
            'url' => "https://{$slug}.example.test/health",
            'check_interval_minutes' => Monitor::DEFAULT_INTERVAL_MINUTES,
            'timeout_seconds' => Monitor::DEFAULT_TIMEOUT_SECONDS,
            'expected_status_code_min' => Monitor::DEFAULT_EXPECTED_STATUS_MIN,
            'expected_status_code_max' => Monitor::DEFAULT_EXPECTED_STATUS_MAX,
            'is_active' => true,
            'status' => MonitorStatus::UNKNOWN,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'last_checked_at' => null,
            'next_check_at' => null,
            'last_status_code' => null,
            'last_latency_ms' => null,
            'tls_expires_at' => null,
            'tls_last_checked_at' => null,
        ];
    }

    /**
     * Configure the monitor in an unchecked unknown state.
     */
    public function unknown(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'status' => MonitorStatus::UNKNOWN,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
        ]);
    }

    /**
     * Configure the monitor in a healthy operational state.
     */
    public function operational(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'status' => MonitorStatus::OPERATIONAL,
            'consecutive_failures' => 0,
            'consecutive_successes' => 2,
            'last_checked_at' => now()->subMinutes(5),
            'next_check_at' => now(),
            'last_status_code' => 200,
            'last_latency_ms' => 120,
        ]);
    }

    /**
     * Configure the monitor after one failed check before incident threshold.
     */
    public function pendingDown(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'status' => MonitorStatus::PENDING_DOWN,
            'consecutive_failures' => 1,
            'consecutive_successes' => 0,
            'last_checked_at' => now()->subMinutes(5),
            'next_check_at' => now(),
        ]);
    }

    /**
     * Configure the monitor in a confirmed down state.
     */
    public function down(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'status' => MonitorStatus::DOWN,
            'consecutive_failures' => 2,
            'consecutive_successes' => 0,
            'last_checked_at' => now()->subMinutes(5),
            'next_check_at' => now(),
        ]);
    }

    /**
     * Configure the monitor after one successful check while recovering from down.
     */
    public function pendingRecovery(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'status' => MonitorStatus::PENDING_RECOVERY,
            'consecutive_failures' => 0,
            'consecutive_successes' => 1,
            'last_checked_at' => now()->subMinutes(5),
            'next_check_at' => now(),
            'last_status_code' => 200,
        ]);
    }

    /**
     * Configure the monitor in a paused state.
     */
    public function paused(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'status' => MonitorStatus::PAUSED,
            'next_check_at' => null,
        ]);
    }
}
