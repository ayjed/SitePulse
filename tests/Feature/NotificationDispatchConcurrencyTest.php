<?php

use App\Enums\IncidentNotificationType;
use App\Enums\IncidentStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitoring\MonitorCheckRunner;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\SafeHttpClient;
use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function makePendingAlertNotification(string $suffix = '1', string $ownerEmail = 'owner@example.test'): IncidentNotification
{
    $owner = User::query()->where('is_owner', true)->first()
        ?? User::factory()->owner()->create(['email' => $ownerEmail]);
    $monitor = Monitor::factory()->for($owner)->down()->create([
        'name' => "API Service {$suffix}",
        'url' => "https://api-{$suffix}.example.test/health",
    ]);
    $incident = Incident::query()->create([
        'monitor_id' => $monitor->id,
        'open_monitor_id' => $monitor->id,
        'started_at' => CarbonImmutable::parse('2026-11-28 10:00:00', 'UTC'),
        'root_cause' => 'HTTP 503',
        'status' => IncidentStatus::OPEN,
    ]);

    return IncidentNotification::query()->create([
        'monitor_id' => $monitor->id,
        'incident_id' => $incident->id,
        'type' => IncidentNotificationType::DOWN,
        'event_key' => "incident:{$incident->id}:down:{$suffix}",
        'channel' => 'mail',
        'recipient' => $owner->email,
        'subject' => "[SitePulse] DOWN: API Service {$suffix}",
        'delivery_status' => NotificationDeliveryStatus::PENDING,
    ]);
}

test('concurrent dispatcher cannot claim an already-active alert when claim timeout has not elapsed', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.notification_claim_timeout_seconds' => 60,
    ]);

    $notification = makePendingAlertNotification('active');
    $worker2Result = null;
    $sendCount = 0;

    try {
        Carbon::setTestNow('2026-11-28 10:05:00');

        Mail::shouldReceive('to')
            ->once()
            ->with('owner@example.test')
            ->andReturn(new class($worker2Result, $sendCount)
            {
                public function __construct(private mixed &$worker2Result, private int &$sendCount) {}

                public function send(mixed $mailable): void
                {
                    $this->sendCount++;

                    // Simulate a second overlapping worker bypassing cache lock 10s later while claim is still active.
                    Carbon::setTestNow('2026-11-28 10:05:10');
                    Cache::lock(NotificationDispatcher::EXECUTION_LOCK_KEY)->forceRelease();
                    $this->worker2Result = app(NotificationDispatcher::class)->dispatchPending();
                }
            });

        $worker1Result = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($sendCount)->toBe(1)
            ->and($worker2Result['claimed'])->toBe(0)
            ->and($worker2Result['sent'])->toBe(0)
            ->and($worker1Result['claimed'])->toBe(1)
            ->and($worker1Result['sent'])->toBe(1)
            ->and($notification->delivery_status)->toBe(NotificationDeliveryStatus::SENT)
            ->and($notification->is_success)->toBeTrue()
            ->and($notification->claim_token)->toBeNull()
            ->and($notification->claim_version)->toBe(1)
            ->and($notification->attempts)->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

test('stale worker completing after a newer worker reclaims and sends is fenced and cannot overwrite delivery state', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.notification_claim_timeout_seconds' => 60,
        'sitepulse.notification_max_attempts' => 3,
    ]);

    $notification = makePendingAlertNotification('stale-success');
    $worker2Result = null;
    $calls = 0;

    try {
        Carbon::setTestNow('2026-11-28 10:10:00');

        Mail::shouldReceive('to')
            ->twice()
            ->with('owner@example.test')
            ->andReturn(new class($worker2Result, $calls)
            {
                public function __construct(private mixed &$worker2Result, private int &$calls) {}

                public function send(mixed $mailable): void
                {
                    $this->calls++;

                    if ($this->calls === 1) {
                        // Worker 1 stalls past claim timeout; Worker 2 reclaims and completes delivery first!
                        Carbon::setTestNow('2026-11-28 10:11:15');
                        Cache::lock(NotificationDispatcher::EXECUTION_LOCK_KEY)->forceRelease();
                        $this->worker2Result = app(NotificationDispatcher::class)->dispatchPending();
                        // Advance clock before Worker 1 tries its post-send DB acknowledgement.
                        Carbon::setTestNow('2026-11-28 10:12:30');
                    }
                }
            });

        $worker1Result = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($calls)->toBe(2)
            ->and($worker2Result['sent'])->toBe(1)
            ->and($worker1Result['sent'])->toBe(0)
            ->and($worker1Result['fenced'])->toBe(1)
            ->and($notification->delivery_status)->toBe(NotificationDeliveryStatus::SENT)
            ->and($notification->is_success)->toBeTrue()
            ->and($notification->attempts)->toBe(2)
            ->and($notification->claim_version)->toBe(2)
            ->and($notification->claim_token)->toBeNull()
            ->and($notification->sent_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-28 10:11:15');
    } finally {
        Carbon::setTestNow();
    }
});

test('stale worker failing after a newer worker succeeds is fenced and never downgrades sent status', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.notification_claim_timeout_seconds' => 60,
        'sitepulse.notification_max_attempts' => 3,
    ]);

    $notification = makePendingAlertNotification('stale-fail');
    $worker2Result = null;
    $calls = 0;

    try {
        Carbon::setTestNow('2026-11-28 10:20:00');

        Mail::shouldReceive('to')
            ->twice()
            ->with('owner@example.test')
            ->andReturn(new class($worker2Result, $calls)
            {
                public function __construct(private mixed &$worker2Result, private int &$calls) {}

                public function send(mixed $mailable): void
                {
                    $this->calls++;

                    if ($this->calls === 1) {
                        // Worker 2 reclaims timed-out notification and delivers cleanly before Worker 1 errors out.
                        Carbon::setTestNow('2026-11-28 10:21:10');
                        Cache::lock(NotificationDispatcher::EXECUTION_LOCK_KEY)->forceRelease();
                        $this->worker2Result = app(NotificationDispatcher::class)->dispatchPending();

                        throw new RuntimeException('Late stale SMTP socket timeout');
                    }
                }
            });

        $worker1Result = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($calls)->toBe(2)
            ->and($worker2Result['sent'])->toBe(1)
            ->and($worker1Result['failed'])->toBe(0)
            ->and($worker1Result['fenced'])->toBe(1)
            ->and($notification->delivery_status)->toBe(NotificationDeliveryStatus::SENT)
            ->and($notification->is_success)->toBeTrue()
            ->and($notification->error_message)->toBeNull()
            ->and($notification->next_attempt_at)->toBeNull()
            ->and($notification->claim_version)->toBe(2)
            ->and($notification->sent_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-28 10:21:10');
    } finally {
        Carbon::setTestNow();
    }
});

test('crash after SMTP acceptance before DB acknowledgement recovers via stale claim TTL and exhausts bounded retries', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.notification_claim_timeout_seconds' => 60,
        'sitepulse.notification_max_attempts' => 3,
        'sitepulse.notification_retry_backoff_seconds' => 60,
    ]);

    $notification = makePendingAlertNotification('crash-and-exhaust');

    try {
        // Simulate process crash leaving row in 'sending' with claim token/version at attempt 1 after SMTP accepted.
        $notification->forceFill([
            'delivery_status' => NotificationDeliveryStatus::SENDING,
            'claimed_at' => CarbonImmutable::parse('2026-11-28 10:30:00', 'UTC'),
            'claim_token' => str_repeat('a', 64),
            'claim_version' => 1,
            'attempts' => 1,
        ])->save();

        // Still within claim_timeout -> protected
        Carbon::setTestNow('2026-11-28 10:30:45');
        $withinTimeout = app(NotificationDispatcher::class)->dispatchPending();
        expect($withinTimeout['claimed'])->toBe(0);

        // Past claim_timeout -> Worker 2 reclaims (attempt 2), SMTP fails transiently -> schedules 120s backoff
        Carbon::setTestNow('2026-11-28 10:31:05');
        Mail::shouldReceive('to')
            ->twice()
            ->with('owner@example.test')
            ->andReturn(new class
            {
                public function send(mixed $mailable): void
                {
                    throw new RuntimeException('SMTP 421 service unavailable');
                }
            });

        $attempt2 = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($attempt2['failed'])->toBe(1)
            ->and($notification->attempts)->toBe(2)
            ->and($notification->claim_version)->toBe(2)
            ->and($notification->claim_token)->toBeNull()
            ->and($notification->next_attempt_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-28 10:33:05');

        // Advance past attempt 2 backoff -> attempt 3 exhausts retries cleanly (no further next_attempt_at)
        Carbon::setTestNow('2026-11-28 10:33:06');
        $attempt3 = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($attempt3['failed'])->toBe(1)
            ->and($notification->delivery_status)->toBe(NotificationDeliveryStatus::FAILED)
            ->and($notification->attempts)->toBe(3)
            ->and($notification->claim_version)->toBe(3)
            ->and($notification->next_attempt_at)->toBeNull()
            ->and($notification->is_success)->toBeFalse();

        // Subsequent passes do NOT retry exhausted notifications.
        Carbon::setTestNow('2026-11-28 12:00:00');
        $exhaustedPass = app(NotificationDispatcher::class)->dispatchPending();
        expect($exhaustedPass['claimed'])->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

test('MonitorCheckRunner decouples HTTP checks completely from Mail/SMTP even when alerts_enabled is true', function () {
    config(['sitepulse.alerts_enabled' => true]);

    Mail::shouldReceive('to')->never();

    $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
    $monitor = Monitor::factory()->for($owner)->pendingDown()->create([
        'url' => 'https://decoupled.example.test/health',
    ]);

    $dns = new class implements DnsResolverInterface
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    };
    $transport = new class implements HttpTransportInterface
    {
        public function send(TransportRequest $request): TransportResponse
        {
            return new TransportResponse(
                statusCode: 503,
                headers: [],
                body: 'down',
                bodyTruncated: false,
                bytesReceived: 4,
                primaryIp: $request->pinnedIp,
                elapsedMs: 12,
            );
        }
    };

    $runner = new MonitorCheckRunner(new SafeHttpClient(dnsResolver: $dns, transport: $transport));
    $report = $runner->run();

    expect($report->completedCount)->toBe(1)
        ->and($report->incidentsOpenedCount)->toBe(1)
        ->and($report->notificationsQueuedCount)->toBe(1)
        ->and(Incident::query()->where('monitor_id', $monitor->id)->count())->toBe(1)
        ->and(IncidentNotification::query()->where('monitor_id', $monitor->id)->firstOrFail()->delivery_status)
        ->toBe(NotificationDeliveryStatus::PENDING);
});

test('sitepulse:dispatch-notifications enforces batch limits, monotonic deadlines, overlap locks, and CLI argument validation', function () {
    config(['sitepulse.alerts_enabled' => true]);
    Mail::fake();

    $this->artisan('sitepulse:dispatch-notifications', ['--limit' => '0'])->assertExitCode(2);
    $this->artisan('sitepulse:dispatch-notifications', ['--deadline' => '-1'])->assertExitCode(2);

    $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
    foreach (range(1, 5) as $idx) {
        $monitor = Monitor::factory()->for($owner)->down()->create([
            'url' => "https://batch-{$idx}.example.test/health",
        ]);
        $incident = Incident::query()->create([
            'monitor_id' => $monitor->id,
            'open_monitor_id' => $monitor->id,
            'started_at' => now('UTC')->subMinutes(5),
            'root_cause' => 'HTTP 500',
            'status' => IncidentStatus::OPEN,
        ]);
        IncidentNotification::query()->create([
            'monitor_id' => $monitor->id,
            'incident_id' => $incident->id,
            'type' => IncidentNotificationType::DOWN,
            'event_key' => "incident:{$incident->id}:down",
            'channel' => 'mail',
            'recipient' => $owner->email,
            'subject' => "[SitePulse] DOWN: Service {$idx}",
            'delivery_status' => NotificationDeliveryStatus::PENDING,
        ]);
    }

    // 1. Overlap lock prevents concurrent invocation from processing.
    $lock = Cache::lock(NotificationDispatcher::EXECUTION_LOCK_KEY, 300);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('sitepulse:dispatch-notifications')
            ->expectsOutputToContain('execution overlap lock is currently held')
            ->assertSuccessful();
        Mail::assertNothingSent();
    } finally {
        $lock->release();
    }

    // 2. --limit=2 processes only 2 alerts, leaving 3 pending cleanly.
    $this->artisan('sitepulse:dispatch-notifications', ['--limit' => '2'])
        ->expectsOutputToContain('claimed=2 sent=2')
        ->assertSuccessful();

    expect(IncidentNotification::query()->where('delivery_status', NotificationDeliveryStatus::SENT->value)->count())->toBe(2)
        ->and(IncidentNotification::query()->where('delivery_status', NotificationDeliveryStatus::PENDING->value)->count())->toBe(3);

    // 3. Monotonic deadline stops cleanly after budget expires, leaving remaining rows untouched.
    $ticks = [0, 0, 2_000_000_000, 2_100_000_000];
    $cursor = 0;
    $clock = static function () use (&$ticks, &$cursor): int {
        $val = $ticks[min($cursor, count($ticks) - 1)];
        $cursor++;

        return $val;
    };

    $deadlineReport = (new NotificationDispatcher($clock))->dispatchPending(limit: 10, deadlineSeconds: 1);

    expect($deadlineReport['claimed'])->toBe(1)
        ->and($deadlineReport['sent'])->toBe(1)
        ->and($deadlineReport['deadlineReached'])->toBeTrue()
        ->and(IncidentNotification::query()->where('delivery_status', NotificationDeliveryStatus::PENDING->value)->count())->toBe(2);
});

test('gates scheduled notification delivery behind independent opt-in flags across config:cache, schedule:list, and schedule:run with <= 5s SMTP cap', function () {
    expect(config('sitepulse.alerts_enabled'))->toBeFalse()
        ->and(config('sitepulse.scheduled_notifications_enabled'))->toBeFalse();

    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($item): bool => str_contains((string) $item->command, 'sitepulse:dispatch-notifications'));

    expect($event)->not->toBeNull()
        ->and($event->filtersPass(app()))->toBeFalse();

    config(['sitepulse.alerts_enabled' => true, 'sitepulse.scheduled_notifications_enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['sitepulse.alerts_enabled' => false, 'sitepulse.scheduled_notifications_enabled' => true]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['sitepulse.alerts_enabled' => true, 'sitepulse.scheduled_notifications_enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();

    // Verify end-to-end subprocess config:cache + schedule:list + schedule:run + MAIL_TIMEOUT cap using mailers.array (0 live SMTP).
    if (! extension_loaded('pdo_sqlite')) {
        return;
    }

    $token = bin2hex(random_bytes(5));
    $workDir = sys_get_temp_dir().'/sitepulse_notif_gate_'.$token;
    mkdir($workDir, 0777, true);
    $tempDb = $workDir.'/test.sqlite';
    touch($tempDb);
    $relCacheHalfOn = "storage/framework/cache/sitepulse-notif-half-{$token}.php";
    $relCacheFullOn = "storage/framework/cache/sitepulse-notif-on-{$token}.php";
    $absCacheHalfOn = base_path($relCacheHalfOn);
    $absCacheFullOn = base_path($relCacheFullOn);

    $runArtisan = static function (array $args, array $env): Process {
        $baseEnv = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('n', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $env['DB_DATABASE'],
            'CACHE_STORE' => 'database',
            'CACHE_PREFIX' => 'sitepulse_notif_lock_',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'MAIL_TIMEOUT' => '30',
        ];

        $process = new Process(
            command: [PHP_BINARY, 'artisan', ...$args],
            cwd: base_path(),
            env: array_merge($baseEnv, $env),
        );
        $process->setTimeout(30);
        $process->run();

        return $process;
    };

    try {
        $migrate = $runArtisan(['migrate', '--force', '--no-interaction'], ['DB_DATABASE' => $tempDb]);
        expect($migrate->getExitCode())->toBe(0, $migrate->getOutput()."\n".$migrate->getErrorOutput());

        $pdo = new PDO("sqlite:{$tempDb}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("INSERT INTO users (name, email, password, is_owner, owner_slot, created_at, updated_at) VALUES ('Owner', 'subproc-owner@example.test', 'hash', 1, 1, '2026-11-28 00:00:00', '2026-11-28 00:00:00')");
        $userId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO monitors (user_id, name, url, check_interval_minutes, timeout_seconds, expected_status_code_min, expected_status_code_max, is_active, status, consecutive_failures, consecutive_successes, revision, created_at, updated_at) VALUES ({$userId}, 'Subprocess Portal', 'https://subproc.example.test/health', 5, 10, 200, 299, 1, 'down', 2, 0, 1, '2026-11-28 00:00:00', '2026-11-28 00:00:00')");
        $monitorId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO incidents (monitor_id, open_monitor_id, started_at, root_cause, status, created_at, updated_at) VALUES ({$monitorId}, {$monitorId}, '2026-11-28 00:00:00', 'HTTP 503', 'open', '2026-11-28 00:00:00', '2026-11-28 00:00:00')");
        $incidentId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO incident_notifications (monitor_id, incident_id, type, event_key, channel, recipient, subject, delivery_status, attempts, is_success, created_at, updated_at) VALUES ({$monitorId}, {$incidentId}, 'down', 'incident:{$incidentId}:down', 'mail', 'subproc-owner@example.test', '[SitePulse] DOWN', 'pending', 0, 0, '2026-11-28 00:00:00', '2026-11-28 00:00:00')");
        $notifId = (int) $pdo->lastInsertId();

        // 1) Cache alerts_enabled=true WITH scheduled_notifications_enabled=false -> schedule:run MUST NOT dispatch.
        $cacheHalf = $runArtisan(['config:cache'], [
            'DB_DATABASE' => $tempDb,
            'APP_CONFIG_CACHE' => $relCacheHalfOn,
            'SITEPULSE_ALERTS_ENABLED' => 'true',
            'SITEPULSE_SCHEDULED_NOTIFICATIONS_ENABLED' => 'false',
        ]);
        expect($cacheHalf->getExitCode())->toBe(0, $cacheHalf->getOutput()."\n".$cacheHalf->getErrorOutput());

        $cachedConfig = require $absCacheHalfOn;
        expect($cachedConfig['mail']['mailers']['smtp']['timeout'])->toBe(5);

        $listProc = $runArtisan(['schedule:list'], [
            'DB_DATABASE' => $tempDb,
            'APP_CONFIG_CACHE' => $relCacheHalfOn,
        ]);
        expect($listProc->getExitCode())->toBe(0)
            ->and($listProc->getOutput())->toContain('sitepulse:dispatch-notifications');

        $runHalf = $runArtisan(['schedule:run'], [
            'DB_DATABASE' => $tempDb,
            'APP_CONFIG_CACHE' => $relCacheHalfOn,
            'SITEPULSE_SCHEDULED_NOTIFICATIONS_ENABLED' => 'true',
        ]);
        expect($runHalf->getExitCode())->toBe(0)
            ->and($runHalf->getOutput())->toContain('No scheduled commands are ready to run')
            ->and($pdo->query("SELECT delivery_status FROM incident_notifications WHERE id = {$notifId}")->fetchColumn())->toBe('pending');

        // 2) Cache both alerts_enabled=true & scheduled_notifications_enabled=true -> schedule:run dispatches safely via array mailer.
        $cacheFull = $runArtisan(['config:cache'], [
            'DB_DATABASE' => $tempDb,
            'APP_CONFIG_CACHE' => $relCacheFullOn,
            'SITEPULSE_ALERTS_ENABLED' => 'true',
            'SITEPULSE_SCHEDULED_NOTIFICATIONS_ENABLED' => 'true',
        ]);
        expect($cacheFull->getExitCode())->toBe(0);

        $runFull = $runArtisan(['schedule:run'], [
            'DB_DATABASE' => $tempDb,
            'APP_CONFIG_CACHE' => $relCacheFullOn,
        ]);
        expect($runFull->getExitCode())->toBe(0, $runFull->getOutput()."\n".$runFull->getErrorOutput())
            ->and($runFull->getOutput())->toContain('sitepulse:dispatch-notifications')
            ->and($pdo->query("SELECT delivery_status FROM incident_notifications WHERE id = {$notifId}")->fetchColumn())->toBe('sent')
            ->and((int) $pdo->query("SELECT claim_version FROM incident_notifications WHERE id = {$notifId}")->fetchColumn())->toBe(1)
            ->and($pdo->query("SELECT claim_token FROM incident_notifications WHERE id = {$notifId}")->fetchColumn())->toBeNull();

        $pdo = null;
    } finally {
        foreach ([$absCacheHalfOn, $absCacheFullOn, $tempDb, "{$tempDb}-wal", "{$tempDb}-shm"] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($workDir);
    }
});

test('reconciles stale final-attempt sending notification cleanly without re-sending email and handles concurrent reconciliation', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.notification_claim_timeout_seconds' => 60,
        'sitepulse.notification_max_attempts' => 3,
    ]);

    Mail::fake();

    $exhaustedStale = makePendingAlertNotification('final-crash', 'owner1@example.test');
    $retryableStale = makePendingAlertNotification('retryable-crash', 'owner2@example.test');

    try {
        // Seed final allowed attempt (attempts=3 == max_attempts) stuck in 'sending' after a crash.
        $exhaustedStale->forceFill([
            'delivery_status' => NotificationDeliveryStatus::SENDING,
            'claimed_at' => CarbonImmutable::parse('2026-11-28 11:00:00', 'UTC'),
            'claim_token' => str_repeat('b', 64),
            'claim_version' => 3,
            'attempts' => 3,
        ])->save();

        // Seed non-final attempt (attempts=1 < max_attempts) stuck in 'sending' to verify normal retry preservation.
        $retryableStale->forceFill([
            'delivery_status' => NotificationDeliveryStatus::SENDING,
            'claimed_at' => CarbonImmutable::parse('2026-11-28 11:00:00', 'UTC'),
            'claim_token' => str_repeat('c', 64),
            'claim_version' => 1,
            'attempts' => 1,
        ])->save();

        // Before claim timeout elapses -> neither row is touched.
        Carbon::setTestNow('2026-11-28 11:00:45');
        $withinWindow = app(NotificationDispatcher::class)->dispatchPending();
        expect($withinWindow['reconciled'])->toBe(0)
            ->and($withinWindow['claimed'])->toBe(0);
        Mail::assertNothingSent();

        // Past claim timeout -> exhausted row is reconciled to FAILED (0 extra emails, attempts untouched),
        // while retryable row is reclaimed & sent normally (attempts 1 -> 2).
        Carbon::setTestNow('2026-11-28 11:01:05');
        $reconcilePass = app(NotificationDispatcher::class)->dispatchPending();
        $exhaustedStale->refresh();
        $retryableStale->refresh();

        expect($reconcilePass['reconciled'])->toBe(1)
            ->and($reconcilePass['claimed'])->toBe(1)
            ->and($reconcilePass['sent'])->toBe(1)
            ->and($exhaustedStale->delivery_status)->toBe(NotificationDeliveryStatus::FAILED)
            ->and($exhaustedStale->is_success)->toBeFalse()
            ->and($exhaustedStale->sent_at)->toBeNull()
            ->and($exhaustedStale->next_attempt_at)->toBeNull()
            ->and($exhaustedStale->claimed_at)->toBeNull()
            ->and($exhaustedStale->claim_token)->toBeNull()
            ->and($exhaustedStale->claim_version)->toBe(4)
            ->and($exhaustedStale->attempts)->toBe(3)
            ->and($exhaustedStale->error_message)->toBe(NotificationDispatcher::STALE_EXHAUSTED_ERROR_MESSAGE)
            ->and($exhaustedStale->error_message)->toContain('at-least-once')
            ->and($retryableStale->delivery_status)->toBe(NotificationDeliveryStatus::SENT)
            ->and($retryableStale->is_success)->toBeTrue()
            ->and($retryableStale->attempts)->toBe(2);

        // Exactly 1 email sent (only retryableStale); exhaustedStale sent ZERO extra emails.
        Mail::assertSentCount(1);

        // Concurrent/repeated reconciliation pass is an exact idempotent no-op.
        $secondPass = app(NotificationDispatcher::class)->dispatchPending();
        expect($secondPass['reconciled'])->toBe(0)
            ->and($secondPass['claimed'])->toBe(0)
            ->and($exhaustedStale->fresh()->claim_version)->toBe(4)
            ->and($exhaustedStale->fresh()->attempts)->toBe(3);
        Mail::assertSentCount(1);
    } finally {
        Carbon::setTestNow();
    }
});

test('successful final-attempt delivery and concurrent reconciliation fence cleanly in both race orderings', function () {
    config([
        'sitepulse.alerts_enabled' => true,
        'sitepulse.notification_claim_timeout_seconds' => 60,
        'sitepulse.notification_max_attempts' => 3,
    ]);

    $notification = makePendingAlertNotification('final-race', 'owner@example.test');
    $notification->forceFill([
        'delivery_status' => NotificationDeliveryStatus::FAILED,
        'attempts' => 2,
        'next_attempt_at' => CarbonImmutable::parse('2026-11-28 11:10:00', 'UTC'),
        'claim_version' => 2,
    ])->save();

    $reconcileResult = null;
    $sendCount = 0;

    try {
        // Ordering A: Final-attempt worker stalls in Mail::send() past timeout; reconciler runs first and reconciles;
        // then slow worker finishes Mail::send() and is fenced without overwriting reconciled FAILED state.
        Carbon::setTestNow('2026-11-28 11:10:05');

        Mail::shouldReceive('to')
            ->once()
            ->with('owner@example.test')
            ->andReturn(new class($reconcileResult, $sendCount)
            {
                public function __construct(private mixed &$reconcileResult, private int &$sendCount) {}

                public function send(mixed $mailable): void
                {
                    $this->sendCount++;

                    // Stalls past claim_timeout -> concurrent dispatcher reconciles the stale final attempt first.
                    Carbon::setTestNow('2026-11-28 11:11:15');
                    Cache::lock(NotificationDispatcher::EXECUTION_LOCK_KEY)->forceRelease();
                    $this->reconcileResult = app(NotificationDispatcher::class)->dispatchPending();
                    Carbon::setTestNow('2026-11-28 11:11:20');
                }
            });

        $finalWorkerResult = app(NotificationDispatcher::class)->dispatchPending();
        $notification->refresh();

        expect($sendCount)->toBe(1)
            ->and($reconcileResult['reconciled'])->toBe(1)
            ->and($finalWorkerResult['sent'])->toBe(0)
            ->and($finalWorkerResult['fenced'])->toBe(1)
            ->and($notification->delivery_status)->toBe(NotificationDeliveryStatus::FAILED)
            ->and($notification->is_success)->toBeFalse()
            ->and($notification->sent_at)->toBeNull()
            ->and($notification->claim_token)->toBeNull()
            ->and($notification->claim_version)->toBe(4)
            ->and($notification->attempts)->toBe(3);

        // Ordering B: Final-attempt worker commits SENT just before reconciler executes -> reconciler sees 0 stale rows
        // and never downgrades or alters the committed SENT state.
        $sentRace = makePendingAlertNotification('sent-before-reconcile', 'owner@example.test');
        $sentRace->forceFill([
            'delivery_status' => NotificationDeliveryStatus::FAILED,
            'attempts' => 2,
            'next_attempt_at' => CarbonImmutable::parse('2026-11-28 11:20:00', 'UTC'),
            'claim_version' => 2,
        ])->save();

        Carbon::setTestNow('2026-11-28 11:20:05');
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();

        // Suppose Mail::send succeeds after a slow delay beyond 60s before any reconciler starts -> commits SENT cleanly.
        $sentResult = app(NotificationDispatcher::class)->dispatchPending();
        Carbon::setTestNow('2026-11-28 11:22:00');
        $lateReconciler = app(NotificationDispatcher::class)->dispatchPending();
        $sentRace->refresh();

        expect($sentResult['sent'])->toBe(1)
            ->and($lateReconciler['reconciled'])->toBe(0)
            ->and($lateReconciler['claimed'])->toBe(0)
            ->and($sentRace->delivery_status)->toBe(NotificationDeliveryStatus::SENT)
            ->and($sentRace->is_success)->toBeTrue()
            ->and($sentRace->sent_at)->not->toBeNull()
            ->and($sentRace->attempts)->toBe(3);
    } finally {
        Carbon::setTestNow();
    }
});
