<?php

use App\Enums\CheckResultStatus;
use App\Enums\IncidentNotificationType;
use App\Enums\IncidentStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitoring\CheckHistoryPruner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('sitepulse:prune-checks deletes aged check_results in portable chunks on exact cutoff while preserving incidents and notifications', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();

    try {
        $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
        Carbon::setTestNow($now);

        // Seed 23 aged checks strictly older than 30 days (< 2026-10-29 12:00:00)
        $agedRows = [];
        for ($i = 1; $i <= 23; $i++) {
            $agedRows[] = [
                'monitor_id' => $monitor->id,
                'status' => CheckResultStatus::SUCCESS->value,
                'status_code' => 200,
                'latency_ms' => 40,
                'redirects_followed' => 0,
                'body_truncated' => false,
                'checked_at' => $now->subDays(31)->subMinutes($i)->format('Y-m-d H:i:s'),
            ];
        }
        CheckResult::query()->insert($agedRows);

        // Seed exact boundary row (checked_at == cutoff 2026-10-29 12:00:00) -> MUST be preserved
        $boundaryId = CheckResult::query()->insertGetId([
            'monitor_id' => $monitor->id,
            'status' => CheckResultStatus::SUCCESS->value,
            'status_code' => 200,
            'latency_ms' => 35,
            'redirects_followed' => 0,
            'body_truncated' => false,
            'checked_at' => $now->subDays(30)->format('Y-m-d H:i:s'),
        ]);

        // Seed fresh check (1 day old) -> MUST be preserved
        $recentId = CheckResult::query()->insertGetId([
            'monitor_id' => $monitor->id,
            'status' => CheckResultStatus::FAILURE->value,
            'status_code' => 500,
            'latency_ms' => 55,
            'redirects_followed' => 0,
            'body_truncated' => false,
            'checked_at' => $now->subDay()->format('Y-m-d H:i:s'),
        ]);

        // Seed aged incident & notifications older than 30 days -> MUST NEVER be pruned
        $agedIncident = Incident::query()->create([
            'monitor_id' => $monitor->id,
            'open_monitor_id' => null,
            'started_at' => $now->subDays(45),
            'resolved_at' => $now->subDays(45)->addMinutes(15),
            'duration_seconds' => 900,
            'root_cause' => 'HTTP 503',
            'status' => IncidentStatus::RESOLVED,
        ]);

        $agedNotification = IncidentNotification::query()->create([
            'monitor_id' => $monitor->id,
            'incident_id' => $agedIncident->id,
            'type' => IncidentNotificationType::DOWN,
            'event_key' => "incident:{$agedIncident->id}:down",
            'channel' => 'mail',
            'recipient' => $owner->email,
            'subject' => '[SitePulse] DOWN',
            'delivery_status' => NotificationDeliveryStatus::SENT,
            'attempts' => 1,
            'sent_at' => $now->subDays(45),
            'is_success' => true,
        ]);

        // Execute chunked pruning with --chunk=5 across 23 aged records => 5 batches (5+5+5+5+3)
        $exitCode = Artisan::call('sitepulse:prune-checks', ['--days' => 30, '--chunk' => 5]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('deleted=23')
            ->and($output)->toContain('batches=5')
            ->and(CheckResult::query()->count())->toBe(2)
            ->and(CheckResult::query()->whereKey($boundaryId)->exists())->toBeTrue()
            ->and(CheckResult::query()->whereKey($recentId)->exists())->toBeTrue()
            ->and(Incident::query()->whereKey($agedIncident->id)->exists())->toBeTrue()
            ->and(IncidentNotification::query()->whereKey($agedNotification->id)->exists())->toBeTrue()
            ->and(Monitor::query()->whereKey($monitor->id)->exists())->toBeTrue();

        // Repeated run is idempotent with 0 deletions.
        $secondExit = Artisan::call('sitepulse:prune-checks', ['--days' => 30, '--chunk' => 5]);
        $secondOutput = Artisan::output();

        expect($secondExit)->toBe(0)
            ->and($secondOutput)->toContain('deleted=0')
            ->and($secondOutput)->toContain('batches=0');
    } finally {
        Carbon::setTestNow();
    }
});

test('sitepulse:prune-checks validates CLI arguments and respects overlap lock', function () {
    $this->artisan('sitepulse:prune-checks', ['--days' => '0'])
        ->assertExitCode(2);

    $this->artisan('sitepulse:prune-checks', ['--chunk' => '-5'])
        ->assertExitCode(2);

    $lock = Cache::lock(CheckHistoryPruner::EXECUTION_LOCK_KEY, 1800);
    expect($lock->get())->toBeTrue();

    try {
        $exitCode = Artisan::call('sitepulse:prune-checks');
        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('Skipped history pruning pass');
    } finally {
        $lock->release();
    }
});

test('daily check-history pruning scheduler stays disabled by default and obeys SITEPULSE_PRUNE_CHECKS_ENABLED opt-in', function () {
    expect(config('sitepulse.prune_checks_enabled'))->toBeFalse();

    $schedule = app(Schedule::class);
    $pruneEvent = collect($schedule->events())->first(
        static fn ($event): bool => str_contains((string) $event->command, 'sitepulse:prune-checks'),
    );

    expect($pruneEvent)->not->toBeNull()
        ->and($pruneEvent->expression)->toBe('0 0 * * *')
        ->and($pruneEvent->filtersPass(app()))->toBeFalse();

    config(['sitepulse.prune_checks_enabled' => true]);
    expect($pruneEvent->filtersPass(app()))->toBeTrue();

    // Verify schedule:list displays the registered daily prune task cleanly.
    $listExit = Artisan::call('schedule:list');
    expect($listExit)->toBe(0)
        ->and(Artisan::output())->toContain('sitepulse:prune-checks');
});
