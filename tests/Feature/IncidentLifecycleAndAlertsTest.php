<?php

use App\Enums\IncidentNotificationType;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Mail\SitePulseAlertMail;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitoring\CheckBatchPersister;
use App\Support\Monitoring\MonitorCheckRunner;
use App\Support\Monitoring\MonitorProbeSnapshot;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\SafeHttpCheckResult;
use App\Support\SafeHttp\SafeHttpClient;
use App\Support\SafeHttp\SafeHttpFailureReason;
use App\Support\SafeHttp\SafeHttpResponse;
use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function bindFakeSequenceHttpClient(array $outcomesByUrl): SafeHttpClient
{
    $dns = new class implements DnsResolverInterface
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    };

    $counters = [];

    $transport = new class($outcomesByUrl, $counters) implements HttpTransportInterface
    {
        /** @var array<string, array<int, callable(): TransportResponse|array<string, mixed>>> */
        private array $outcomesByUrl;

        /** @var array<string, int> */
        private array $counters;

        public function __construct(array $outcomesByUrl, array &$counters)
        {
            $this->outcomesByUrl = $outcomesByUrl;
            $this->counters = &$counters;
        }

        public function send(TransportRequest $request): TransportResponse
        {
            $url = $request->url->url;
            $index = $this->counters[$url] ?? 0;
            $this->counters[$url] = $index + 1;

            $list = $this->outcomesByUrl[$url] ?? [];
            $entry = $list[$index] ?? end($list);

            if ($entry instanceof TransportResponse) {
                return $entry;
            }

            if (is_callable($entry)) {
                return $entry();
            }

            $body = (string) ($entry['body'] ?? 'ok');

            return new TransportResponse(
                statusCode: (int) ($entry['status'] ?? 200),
                headers: ['content-type' => ['text/plain']],
                body: $body,
                bodyTruncated: false,
                bytesReceived: strlen($body),
                primaryIp: $request->pinnedIp,
                certInfo: $entry['cert_info'] ?? [],
                elapsedMs: (int) ($entry['latency_ms'] ?? 15),
            );
        }
    };

    return new SafeHttpClient(dnsResolver: $dns, transport: $transport);
}

test('STATE-01: flapping check (operational -> fail -> success) creates zero incidents and sends zero alert emails', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.scheduled_checks_enabled' => true,
    ]);
    Mail::fake();

    $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
    $monitor = Monitor::factory()->for($owner)->operational()->create([
        'url' => 'https://flapping.example.test/health',
        'check_interval_minutes' => 5,
        'last_checked_at' => CarbonImmutable::parse('2026-11-28 10:00:00', 'UTC'),
        'next_check_at' => CarbonImmutable::parse('2026-11-28 10:05:00', 'UTC'),
    ]);

    $client = bindFakeSequenceHttpClient([
        'https://flapping.example.test/health' => [
            ['status' => 503],
            ['status' => 200],
        ],
    ]);

    $runner = new MonitorCheckRunner($client);

    try {
        Carbon::setTestNow('2026-11-28 10:06:00');
        $run1 = $runner->run();

        expect($run1->completedCount)->toBe(1)
            ->and($run1->incidentsOpenedCount)->toBe(0)
            ->and($monitor->fresh()->status)->toBe(MonitorStatus::PENDING_DOWN);

        Carbon::setTestNow('2026-11-28 10:12:00');
        $run2 = $runner->run();

        expect($run2->completedCount)->toBe(1)
            ->and($run2->incidentsOpenedCount)->toBe(0)
            ->and($monitor->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and(Incident::query()->count())->toBe(0)
            ->and(IncidentNotification::query()->count())->toBe(0);

        Mail::assertNothingSent();
    } finally {
        Carbon::setTestNow();
    }
});

test('STATE-02, STATE-03, STATE-04: confirms outage at N=2, deduplicates prolonged/flapping failures, and resolves at M=2 with accurate duration', function () {
    config(['sitepulse.alerts_enabled' => true]);
    Mail::fake();

    $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
    $monitor = Monitor::factory()->for($owner)->operational()->create([
        'name' => 'Checkout API',
        'url' => 'https://checkout.example.test/health',
        'check_interval_minutes' => 5,
        'last_checked_at' => CarbonImmutable::parse('2026-11-28 10:00:00', 'UTC'),
        'next_check_at' => CarbonImmutable::parse('2026-11-28 10:05:00', 'UTC'),
    ]);

    // Check sequence: 500 -> 500 (outage confirmed) -> 502 -> 200 (pending_recovery) -> 500 (back to down, same incident!) -> 200 -> 200 (recovered)
    $client = bindFakeSequenceHttpClient([
        'https://checkout.example.test/health' => [
            ['status' => 500],
            ['status' => 500],
            ['status' => 502],
            ['status' => 200],
            ['status' => 500],
            ['status' => 200],
            ['status' => 200],
        ],
    ]);

    $runner = new MonitorCheckRunner($client);

    try {
        // 1st failure: 10:06 -> PENDING_DOWN, no incident
        Carbon::setTestNow('2026-11-28 10:06:00');
        $runner->run();
        expect(Incident::query()->count())->toBe(0);
        Mail::assertNothingSent();

        // 2nd failure: 10:12 -> DOWN, opens incident + dispatches 1 DOWN email
        Carbon::setTestNow('2026-11-28 10:12:00');
        $run2 = $runner->run();
        expect($run2->incidentsOpenedCount)->toBe(1)
            ->and($run2->notificationsSentCount)->toBe(1);

        $incident = Incident::query()->firstOrFail();
        expect($incident->status)->toBe(IncidentStatus::OPEN)
            ->and($incident->open_monitor_id)->toBe($monitor->id)
            ->and($incident->resolved_at)->toBeNull();

        Mail::assertSent(SitePulseAlertMail::class, 1);

        // 3rd failure (10:18), 1st success (10:24 PENDING_RECOVERY), relapse failure (10:30 DOWN): STILL 1 open incident, STILL 1 total email
        foreach (['2026-11-28 10:18:00', '2026-11-28 10:24:00', '2026-11-28 10:30:00'] as $ts) {
            Carbon::setTestNow($ts);
            $runner->run();
        }

        expect(Incident::query()->count())->toBe(1)
            ->and($incident->fresh()->status)->toBe(IncidentStatus::OPEN)
            ->and(IncidentNotification::query()->count())->toBe(1);
        Mail::assertSent(SitePulseAlertMail::class, 1);

        // M=1 success at 10:36 -> PENDING_RECOVERY, still open
        Carbon::setTestNow('2026-11-28 10:36:00');
        $runner->run();
        expect($incident->fresh()->status)->toBe(IncidentStatus::OPEN)
            ->and($monitor->fresh()->status)->toBe(MonitorStatus::PENDING_RECOVERY);

        // M=2 success at 10:42 -> OPERATIONAL, closes incident (30m = 1800s downtime) + 1 RECOVERY email
        Carbon::setTestNow('2026-11-28 10:42:00');
        $runRecover = $runner->run();

        expect($runRecover->incidentsResolvedCount)->toBe(1)
            ->and($runRecover->notificationsSentCount)->toBe(1);

        $resolved = $incident->fresh();
        expect($resolved->status)->toBe(IncidentStatus::RESOLVED)
            ->and($resolved->open_monitor_id)->toBeNull()
            ->and($resolved->duration_seconds)->toBe(1800)
            ->and($resolved->resolved_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-28 10:42:00');

        expect(IncidentNotification::query()->count())->toBe(2);
        Mail::assertSentCount(2);
        Mail::assertSent(SitePulseAlertMail::class, fn (SitePulseAlertMail $mail) => $mail->alertType === IncidentNotificationType::DOWN
            && $mail->hasTo('owner@example.test'));
        Mail::assertSent(SitePulseAlertMail::class, fn (SitePulseAlertMail $mail) => $mail->alertType === IncidentNotificationType::RECOVERY
            && $mail->formattedDuration === '30m 0s'
            && $mail->hasTo('owner@example.test'));
    } finally {
        Carbon::setTestNow();
    }
});

test('STATE-05: database constraints prevent duplicate open incidents and duplicate notification events while permitting multiple resolved incidents and TLS episodes', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();

    $open1 = Incident::query()->create([
        'monitor_id' => $monitor->id,
        'open_monitor_id' => $monitor->id,
        'started_at' => now('UTC')->subMinutes(10),
        'status' => IncidentStatus::OPEN,
    ]);

    expect(fn () => Incident::query()->create([
        'monitor_id' => $monitor->id,
        'open_monitor_id' => $monitor->id,
        'started_at' => now('UTC'),
        'status' => IncidentStatus::OPEN,
    ]))->toThrow(QueryException::class);

    // Resolving incident clears open_monitor_id and allows subsequent incidents cleanly.
    $open1->forceFill([
        'status' => IncidentStatus::RESOLVED,
        'open_monitor_id' => null,
        'resolved_at' => now('UTC')->subMinutes(5),
        'duration_seconds' => 300,
    ])->save();

    $open2 = Incident::query()->create([
        'monitor_id' => $monitor->id,
        'open_monitor_id' => $monitor->id,
        'started_at' => now('UTC'),
        'status' => IncidentStatus::OPEN,
    ]);

    IncidentNotification::query()->create([
        'monitor_id' => $monitor->id,
        'incident_id' => $open2->id,
        'type' => IncidentNotificationType::DOWN,
        'event_key' => "incident:{$open2->id}:down",
        'channel' => 'mail',
        'recipient' => $owner->email,
        'subject' => '[SitePulse] DOWN',
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]);

    // Composite unique(incident_id, type) blocks duplicate event even with a different event_key.
    expect(fn () => IncidentNotification::query()->create([
        'monitor_id' => $monitor->id,
        'incident_id' => $open2->id,
        'type' => IncidentNotificationType::DOWN,
        'event_key' => "incident:{$open2->id}:down:duplicate",
        'channel' => 'mail',
        'recipient' => $owner->email,
        'subject' => '[SitePulse] DOWN',
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]))->toThrow(QueryException::class);

    // Multiple NULL incident_id TLS warning rows are allowed when event_key differs (SQLite + MySQL compatible).
    IncidentNotification::query()->create([
        'monitor_id' => $monitor->id,
        'incident_id' => null,
        'type' => IncidentNotificationType::TLS_WARNING,
        'event_key' => "monitor:{$monitor->id}:tls:c1:1796000000",
        'channel' => 'mail',
        'recipient' => $owner->email,
        'subject' => '[SitePulse] TLS Expiry Warning',
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]);

    IncidentNotification::query()->create([
        'monitor_id' => $monitor->id,
        'incident_id' => null,
        'type' => IncidentNotificationType::TLS_WARNING,
        'event_key' => "monitor:{$monitor->id}:tls:c2:1799000000",
        'channel' => 'mail',
        'recipient' => $owner->email,
        'subject' => '[SitePulse] TLS Expiry Warning',
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]);

    expect(IncidentNotification::query()->whereNull('incident_id')->count())->toBe(2);
});

test('notification dispatcher isolates SMTP failures, sanitizes error logs, enforces backoff retries, and recovers crashed sending claims', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.notification_max_attempts' => 3,
        'sitepulse.notification_claim_timeout_seconds' => 60,
        'sitepulse.notification_retry_backoff_seconds' => 60,
    ]);

    $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
    $monitor = Monitor::factory()->for($owner)->down()->create([
        'name' => 'Billing Service',
        'url' => 'https://billing.example.test/health',
    ]);
    $incident = Incident::query()->create([
        'monitor_id' => $monitor->id,
        'open_monitor_id' => $monitor->id,
        'started_at' => CarbonImmutable::parse('2026-11-28 09:55:00', 'UTC'),
        'root_cause' => 'HTTP 503',
        'status' => IncidentStatus::OPEN,
    ]);

    $notification = IncidentNotification::query()->create([
        'monitor_id' => $monitor->id,
        'incident_id' => $incident->id,
        'type' => IncidentNotificationType::DOWN,
        'event_key' => "incident:{$incident->id}:down",
        'channel' => 'mail',
        'recipient' => $owner->email,
        'subject' => '[SitePulse] DOWN: Billing Service',
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]);

    try {
        // Attempt 1 fails with sensitive URL credentials inside exception message.
        Mail::shouldReceive('to')
            ->once()
            ->with('owner@example.test')
            ->andReturn(new class
            {
                public function send(mixed $mailable): void
                {
                    throw new RuntimeException("SMTP auth failed via https://admin:supersecret@smtp.example.test\nAuthorization: Bearer topsecret");
                }
            });

        Carbon::setTestNow('2026-11-28 10:00:00');
        $firstPass = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($firstPass['claimed'])->toBe(1)
            ->and($firstPass['sent'])->toBe(0)
            ->and($firstPass['failed'])->toBe(1)
            ->and($notification->delivery_status)->toBe(NotificationDeliveryStatus::FAILED)
            ->and($notification->is_success)->toBeFalse()
            ->and($notification->sent_at)->toBeNull()
            ->and($notification->attempts)->toBe(1)
            ->and($notification->next_attempt_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-28 10:01:00')
            ->and($notification->error_message)->not->toContain('supersecret')
            ->and($notification->error_message)->not->toContain('topsecret')
            ->and($notification->error_message)->toContain('[REDACTED]');

        // Immediate pass before 60s backoff expires must NOT retry.
        Carbon::setTestNow('2026-11-28 10:00:30');
        $skippedPass = app(NotificationDispatcher::class)->dispatchPending();
        expect($skippedPass['claimed'])->toBe(0);

        // Simulate a worker crash during attempt 2 ('sending' left stale past claim timeout).
        $notification->forceFill([
            'delivery_status' => NotificationDeliveryStatus::SENDING,
            'claimed_at' => CarbonImmutable::parse('2026-11-28 10:01:05', 'UTC'),
            'attempts' => 2,
        ])->save();

        // Within claim_timeout (10:01:30 <= 10:01:05 + 60s), active claim is protected from double delivery.
        Carbon::setTestNow('2026-11-28 10:01:30');
        $protectedPass = app(NotificationDispatcher::class)->dispatchPending();
        expect($protectedPass['claimed'])->toBe(0);

        // Past claim_timeout (10:02:10), stale claim is recovered and delivered cleanly!
        Carbon::setTestNow('2026-11-28 10:02:10');
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();

        $recoveredPass = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($recoveredPass['claimed'])->toBe(1)
            ->and($recoveredPass['sent'])->toBe(1)
            ->and($notification->delivery_status)->toBe(NotificationDeliveryStatus::SENT)
            ->and($notification->is_success)->toBeTrue()
            ->and($notification->sent_at)->not->toBeNull()
            ->and($notification->claimed_at)->toBeNull()
            ->and($notification->error_message)->toBeNull()
            ->and($notification->attempts)->toBe(3);

        Mail::assertSent(SitePulseAlertMail::class, 1);

        // Subsequent dispatcher pass is a strict no-op (no duplicate send).
        $noopPass = app(NotificationDispatcher::class)->dispatchPending();
        expect($noopPass['claimed'])->toBe(0);
        Mail::assertSentCount(1);
    } finally {
        Carbon::setTestNow();
    }
});

test('pausing, changing URL, or deleting a monitor handles open incidents cleanly without false recovery emails', function () {
    config(['sitepulse.alerts_enabled' => true]);
    Mail::fake();

    $owner = User::factory()->owner()->create();

    // 1. Pause resolves open incident silently without emitting recovery alert.
    $pausedMonitor = Monitor::factory()->for($owner)->down()->create();
    $pausedIncident = Incident::query()->create([
        'monitor_id' => $pausedMonitor->id,
        'open_monitor_id' => $pausedMonitor->id,
        'started_at' => now('UTC')->subMinutes(15),
        'status' => IncidentStatus::OPEN,
    ]);

    $this->actingAs($owner)->post(route('monitors.pause', $pausedMonitor))->assertRedirect();
    expect($pausedIncident->fresh()->status)->toBe(IncidentStatus::RESOLVED)
        ->and($pausedIncident->fresh()->open_monitor_id)->toBeNull()
        ->and($pausedMonitor->notifications()->where('type', IncidentNotificationType::RECOVERY->value)->count())->toBe(0);

    // 2. Changing target URL resolves open incident silently and advances TLS cycle.
    $editedMonitor = Monitor::factory()->for($owner)->down()->create([
        'url' => 'https://old.example.test/health',
        'tls_warning_expires_at' => now('UTC')->addDays(5),
        'tls_warning_cycle' => 1,
    ]);
    $editedIncident = Incident::query()->create([
        'monitor_id' => $editedMonitor->id,
        'open_monitor_id' => $editedMonitor->id,
        'started_at' => now('UTC')->subMinutes(12),
        'status' => IncidentStatus::OPEN,
    ]);

    $this->actingAs($owner)->put(route('monitors.update', $editedMonitor), [
        'name' => $editedMonitor->name,
        'url' => 'https://new.example.test/health',
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 299,
        'is_active' => '1',
    ])->assertRedirect();

    $editedMonitor->refresh();
    expect($editedIncident->fresh()->status)->toBe(IncidentStatus::RESOLVED)
        ->and($editedMonitor->tls_warning_expires_at)->toBeNull()
        ->and($editedMonitor->tls_warning_cycle)->toBe(2)
        ->and($editedMonitor->notifications()->count())->toBe(0);

    // 3. Deleting monitor cascades open/historical incidents & notifications cleanly.
    $deletedMonitor = Monitor::factory()->for($owner)->down()->create();
    $deletedIncident = Incident::query()->create([
        'monitor_id' => $deletedMonitor->id,
        'open_monitor_id' => $deletedMonitor->id,
        'started_at' => now('UTC')->subMinutes(8),
        'status' => IncidentStatus::OPEN,
    ]);
    IncidentNotification::query()->create([
        'monitor_id' => $deletedMonitor->id,
        'incident_id' => $deletedIncident->id,
        'type' => IncidentNotificationType::DOWN,
        'event_key' => "incident:{$deletedIncident->id}:down",
        'channel' => 'mail',
        'recipient' => $owner->email,
        'subject' => '[SitePulse] DOWN',
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]);

    $this->actingAs($owner)->delete(route('monitors.destroy', $deletedMonitor))->assertRedirect();
    expect(Incident::query()->whereKey($deletedIncident->id)->exists())->toBeFalse()
        ->and(IncidentNotification::query()->where('monitor_id', $deletedMonitor->id)->exists())->toBeFalse();

    Mail::assertNothingSent();
});

test('stale check results cannot open incidents or trigger alerts', function () {
    config(['sitepulse.alerts_enabled' => true]);
    Mail::fake();

    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create([
        'status' => MonitorStatus::PENDING_DOWN,
        'consecutive_failures' => 1,
        'revision' => 5,
    ]);

    // Snapshot captured BEFORE monitor was concurrently edited/resumed to revision 6.
    $staleSnapshot = MonitorProbeSnapshot::fromMonitor($monitor);
    $monitor->update(['name' => 'Renamed Endpoint']);
    expect($monitor->fresh()->revision)->toBe(6);

    $persister = app(CheckBatchPersister::class);
    $stats = $persister->persistBatch([
        [
            'snapshot' => $staleSnapshot,
            'result' => SafeHttpCheckResult::failure(
                reason: SafeHttpFailureReason::HTTP_ERROR,
                errorMessage: 'HTTP 500',
                finalUrl: $staleSnapshot->url,
                latencyMs: 30,
                statusCode: 500,
            ),
            'checkedAt' => CarbonImmutable::instance(now('UTC')),
        ],
    ]);

    expect($stats['staleDiscarded'])->toBe(1)
        ->and($stats['incidentsOpened'])->toBe(0)
        ->and(Incident::query()->count())->toBe(0)
        ->and(IncidentNotification::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

test('TLS 14-day warnings trigger independently, deduplicate until renewal, rearm after renewal, and skip missing TLS metadata', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.tls_expiry_warning_days' => 14,
    ]);
    Mail::fake();

    $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
    $monitor = Monitor::factory()->for($owner)->operational()->create([
        'name' => 'Docs Portal',
        'url' => 'https://docs.example.test/health',
        'check_interval_minutes' => 5,
        'last_checked_at' => CarbonImmutable::parse('2026-11-28 09:00:00', 'UTC'),
        'next_check_at' => CarbonImmutable::parse('2026-11-28 09:05:00', 'UTC'),
    ]);

    $firstExpiry = CarbonImmutable::parse('2026-12-08 10:00:00', 'UTC'); // ~10 days from 2026-11-28
    $renewedExpiry = CarbonImmutable::parse('2027-02-26 10:00:00', 'UTC'); // 90 days away

    try {
        Carbon::setTestNow('2026-11-28 10:00:00');
        $persister = app(CheckBatchPersister::class);
        $dispatcher = app(NotificationDispatcher::class);

        // Check 1: HTTP 500 + expiring cert (10d) -> triggers TLS warning independently of HTTP health
        $snap1 = MonitorProbeSnapshot::fromMonitor($monitor->fresh());
        $persister->persistBatch([[
            'snapshot' => $snap1,
            'result' => SafeHttpCheckResult::failure(
                reason: SafeHttpFailureReason::HTTP_ERROR,
                errorMessage: 'HTTP 500',
                finalUrl: $monitor->url,
                latencyMs: 20,
                statusCode: 500,
                tlsExpiresAt: $firstExpiry,
            ),
            'checkedAt' => CarbonImmutable::parse('2026-11-28 10:00:00', 'UTC'),
        ]]);
        $dispatcher->dispatchPending();

        expect(IncidentNotification::query()->where('type', IncidentNotificationType::TLS_WARNING->value)->count())->toBe(1);
        Mail::assertSent(SitePulseAlertMail::class, 1);

        // Check 2: Same expiry next day -> deduplicated (still 1 email)
        $snap2 = MonitorProbeSnapshot::fromMonitor($monitor->fresh());
        $persister->persistBatch([[
            'snapshot' => $snap2,
            'result' => SafeHttpCheckResult::success(
                new SafeHttpResponse(
                    finalUrl: $monitor->url,
                    resolvedIp: '93.184.216.34',
                    statusCode: 200,
                    headers: [],
                    body: 'ok',
                    bodyTruncated: false,
                    bytesReceived: 2,
                    latencyMs: 15,
                    redirectsFollowed: 0,
                    tlsExpiresAt: $firstExpiry,
                ),
                tlsExpiringSoon: true,
                includeResponse: false,
            ),
            'checkedAt' => CarbonImmutable::parse('2026-11-29 10:00:00', 'UTC'),
        ]]);
        $dispatcher->dispatchPending();
        Mail::assertSentCount(1);

        // Check 3: Failed probe with null tlsExpiresAt -> must NOT emit warning or clear renewal state
        $snap3 = MonitorProbeSnapshot::fromMonitor($monitor->fresh());
        $persister->persistBatch([[
            'snapshot' => $snap3,
            'result' => SafeHttpCheckResult::failure(
                reason: SafeHttpFailureReason::TLS_ERROR,
                errorMessage: 'Cert untrusted',
                finalUrl: $monitor->url,
                latencyMs: 25,
                tlsExpiresAt: null,
            ),
            'checkedAt' => CarbonImmutable::parse('2026-11-30 10:00:00', 'UTC'),
        ]]);
        $dispatcher->dispatchPending();
        expect($monitor->fresh()->tls_warning_expires_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-12-08 10:00:00');
        Mail::assertSentCount(1);

        // Check 4: Renewed cert (> 14d) rearms warning tracking cleanly
        $snap4 = MonitorProbeSnapshot::fromMonitor($monitor->fresh());
        $persister->persistBatch([[
            'snapshot' => $snap4,
            'result' => SafeHttpCheckResult::success(
                new SafeHttpResponse(
                    finalUrl: $monitor->url,
                    resolvedIp: '93.184.216.34',
                    statusCode: 200,
                    headers: [],
                    body: 'ok',
                    bodyTruncated: false,
                    bytesReceived: 2,
                    latencyMs: 12,
                    redirectsFollowed: 0,
                    tlsExpiresAt: $renewedExpiry,
                ),
                tlsExpiringSoon: false,
                includeResponse: false,
            ),
            'checkedAt' => CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'),
        ]]);
        $monitor->refresh();
        expect($monitor->tls_warning_expires_at)->toBeNull()
            ->and($monitor->tls_warning_cycle)->toBe(2);

        // Check 5: Months later, renewed cert enters <= 14d window -> new TLS warning dispatched cleanly
        Carbon::setTestNow('2027-02-16 10:00:00');
        $snap5 = MonitorProbeSnapshot::fromMonitor($monitor->fresh());
        $persister->persistBatch([[
            'snapshot' => $snap5,
            'result' => SafeHttpCheckResult::success(
                new SafeHttpResponse(
                    finalUrl: $monitor->url,
                    resolvedIp: '93.184.216.34',
                    statusCode: 200,
                    headers: [],
                    body: 'ok',
                    bodyTruncated: false,
                    bytesReceived: 2,
                    latencyMs: 14,
                    redirectsFollowed: 0,
                    tlsExpiresAt: $renewedExpiry,
                ),
                tlsExpiringSoon: true,
                includeResponse: false,
            ),
            'checkedAt' => CarbonImmutable::parse('2027-02-16 10:00:00', 'UTC'),
        ]]);
        $dispatcher->dispatchPending();

        expect(IncidentNotification::query()->where('type', IncidentNotificationType::TLS_WARNING->value)->count())->toBe(2);
        Mail::assertSentCount(2);
    } finally {
        Carbon::setTestNow();
    }
});

test('alerts stay disabled by default on schedule:run and alert rendering escapes XSS, rejects non-owner recipients, and strips secrets', function () {
    // 1. Default config safety guarantees
    expect(config('sitepulse.alerts_enabled'))->toBeFalse()
        ->and(config('mail.mailers.smtp.timeout'))->toBeLessThanOrEqual(5);

    Mail::fake();

    // 2. With alerts_enabled = false, outages/warnings record in outbox but ZERO emails are sent by dispatcher or schedule:run
    $owner = User::factory()->owner()->create(['email' => 'verified-owner@example.test']);
    $monitor = Monitor::factory()->for($owner)->pendingDown()->create([
        'name' => "<script>alert(1)</script>\r\nBcc: attacker@evil.test",
        'url' => 'https://app.example.test/status',
    ]);

    app(CheckBatchPersister::class)->persistBatch([[
        'snapshot' => MonitorProbeSnapshot::fromMonitor($monitor),
        'result' => SafeHttpCheckResult::failure(
            reason: SafeHttpFailureReason::HTTP_ERROR,
            errorMessage: 'Upstream error via https://user:pw123@app.example.test/status Authorization: Bearer secret-jwt',
            finalUrl: $monitor->url,
            latencyMs: 25,
            statusCode: 500,
        ),
        'checkedAt' => CarbonImmutable::instance(now('UTC')),
    ]]);

    $disabledDispatch = app(NotificationDispatcher::class)->dispatchPending();
    expect($disabledDispatch['alertsEnabled'])->toBeFalse()
        ->and($disabledDispatch['sent'])->toBe(0);
    Mail::assertNothingSent();

    Artisan::call('schedule:run');
    Mail::assertNothingSent();

    // 3. Mailable rendering escapes HTML/XSS, eliminates CR/LF header injection, and strips secrets.
    $mailable = new SitePulseAlertMail(
        alertType: IncidentNotificationType::DOWN,
        monitorName: "<script>alert(1)</script>\r\nBcc: attacker@evil.test",
        monitorUrl: 'https://user:pw123@app.example.test/status?token=abc',
        occurredAt: CarbonImmutable::parse('2026-11-28 10:00:00', 'UTC'),
        summary: CheckBatchPersister::sanitizeErrorMessage('Failed at https://user:pw123@app.example.test Authorization: Bearer secret-jwt'),
    );

    $subject = $mailable->envelope()->subject;
    $renderedHtml = $mailable->render();

    expect($subject)->not->toContain("\r")
        ->and($subject)->not->toContain("\n")
        ->and($subject)->toContain('DOWN')
        ->and($renderedHtml)->not->toContain('<script>')
        ->and($renderedHtml)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($renderedHtml)->not->toContain('pw123')
        ->and($renderedHtml)->not->toContain('secret-jwt')
        ->and($renderedHtml)->not->toContain('?token=abc');

    // 4. Non-owner user cannot receive alerts even if referenced.
    $nonOwner = User::factory()->create(['is_owner' => false, 'email' => 'intruder@example.test']);
    $nonOwnerMonitor = Monitor::factory()->for($nonOwner)->create();
    $nonOwnerNotification = IncidentNotification::query()->create([
        'monitor_id' => $nonOwnerMonitor->id,
        'incident_id' => null,
        'type' => IncidentNotificationType::TLS_WARNING,
        'event_key' => "monitor:{$nonOwnerMonitor->id}:tls:c1:1800000000",
        'channel' => 'mail',
        'recipient' => 'intruder@example.test',
        'subject' => '[SitePulse] TLS Expiry Warning',
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]);

    IncidentNotification::query()->where('monitor_id', $monitor->id)->delete();
    config(['sitepulse.alerts_enabled' => true]);
    $res = app(NotificationDispatcher::class)->dispatchPending();
    $nonOwnerNotification->refresh();

    expect($res['sent'])->toBe(0)
        ->and($res['failed'])->toBe(1)
        ->and($nonOwnerNotification->delivery_status)->toBe(NotificationDeliveryStatus::FAILED)
        ->and($nonOwnerNotification->is_success)->toBeFalse();
    Mail::assertNothingSent();
});
