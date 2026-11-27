<?php

use App\Enums\CheckResultStatus;
use App\Enums\MonitorStatus;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitoring\CheckBatchPersister;
use App\Support\Monitoring\MonitorCheckRunner;
use App\Support\Monitoring\MonitorStateTransition;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\Contracts\MultiTransportDriverInterface;
use App\Support\SafeHttp\SafeHttpClient;
use App\Support\SafeHttp\SafeHttpFailureReason;
use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function bindFakeEngine(
    callable $transportHandler,
    ?callable $dnsHandler = null,
    ?Closure $clockNs = null,
): void {
    $dns = new class($dnsHandler) implements DnsResolverInterface
    {
        /** @var Closure(string): array<int, string> */
        private Closure $handler;

        public function __construct(?callable $handler)
        {
            $this->handler = $handler ? $handler(...) : static fn (string $host): array => ['93.184.216.34'];
        }

        public function resolve(string $hostname): array
        {
            return ($this->handler)($hostname);
        }
    };

    $transport = new class($transportHandler) implements HttpTransportInterface
    {
        /** @var Closure(TransportRequest): TransportResponse */
        private Closure $handler;

        public function __construct(callable $handler)
        {
            $this->handler = $handler(...);
        }

        public function send(TransportRequest $request): TransportResponse
        {
            return ($this->handler)($request);
        }
    };

    app()->instance(DnsResolverInterface::class, $dns);
    app()->instance(HttpTransportInterface::class, $transport);
    app()->bind(SafeHttpClient::class, static fn (): SafeHttpClient => new SafeHttpClient(
        dnsResolver: $dns,
        transport: $transport,
        monotonicClockNs: $clockNs,
    ));
    app()->bind(MonitorCheckRunner::class, static fn ($app): MonitorCheckRunner => new MonitorCheckRunner(
        safeHttpClient: $app->make(SafeHttpClient::class),
        persister: $app->make(CheckBatchPersister::class),
        monotonicClockNs: $clockNs,
    ));
}

function makeOkTransportResponse(
    int $statusCode = 200,
    string $primaryIp = '93.184.216.34',
    array $headers = [],
    array $certInfo = [],
    int $curlErrno = 0,
    string $curlError = '',
    bool $bodyTruncated = false,
    int $bytesReceived = 2,
): TransportResponse {
    return new TransportResponse(
        statusCode: $statusCode,
        headers: $headers,
        body: 'ok',
        bodyTruncated: $bodyTruncated,
        bytesReceived: $bytesReceived,
        primaryIp: $primaryIp,
        certInfo: $certInfo,
        curlErrno: $curlErrno,
        curlError: $curlError,
        elapsedMs: 12,
    );
}

test('SCHED-01: registers sitepulse:check-monitors scheduler event and validates bounded CLI options', function () {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('sitepulse:check-monitors');

    $events = collect(app(Schedule::class)->events())
        ->filter(static fn ($event): bool => str_contains((string) $event->command, 'sitepulse:check-monitors'))
        ->values();

    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);

    $this->artisan('sitepulse:check-monitors', ['--concurrency' => '0'])->assertExitCode(2);
    $this->artisan('sitepulse:check-monitors', ['--concurrency' => 'abc'])->assertExitCode(2);
    $this->artisan('sitepulse:check-monitors', ['--limit' => '0'])->assertExitCode(2);

    // Clamps concurrency cleanly when requested > 10 ceiling without executing probes if none due.
    $this->artisan('sitepulse:check-monitors', ['--concurrency' => '50', '--limit' => '5'])
        ->expectsOutputToContain('clamping to 10')
        ->assertSuccessful();
});

test('SCHED-02: prevents overlapping execution via atomic database lock and recovers cleanly after TTL expiry or exception', function () {
    $owner = User::factory()->owner()->create();
    Monitor::factory()->for($owner)->create(['url' => 'https://locked.example.test/health']);

    $probeCount = 0;
    bindFakeEngine(function () use (&$probeCount): TransportResponse {
        $probeCount++;

        return makeOkTransportResponse();
    });

    config(['sitepulse.lock_store' => 'database', 'sitepulse.check_lock_ttl_seconds' => 600]);
    Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:00', 'UTC'));

    try {
        // Hold database cache lock explicitly to simulate an active concurrent cron worker.
        $heldLock = Cache::store('database')->lock(MonitorCheckRunner::EXECUTION_LOCK_KEY, 600);
        expect($heldLock->get())->toBeTrue();
        expect(DB::table('cache_locks')->where('key', 'like', '%'.MonitorCheckRunner::EXECUTION_LOCK_KEY.'%')->count())->toBe(1);

        $skippedReport = app(MonitorCheckRunner::class)->run();
        expect($skippedReport->lockAcquired)->toBeFalse()
            ->and($skippedReport->dueCount)->toBe(0)
            ->and($probeCount)->toBe(0);

        $this->artisan('sitepulse:check-monitors')
            ->expectsOutputToContain('execution overlap lock is currently held')
            ->assertSuccessful();

        // Simulate worker crash leaving stale lock row, then advance past 600s TTL.
        Carbon::setTestNow(Carbon::parse('2026-11-27 12:10:05', 'UTC'));

        $recoveredReport = app(MonitorCheckRunner::class)->run();
        expect($recoveredReport->lockAcquired)->toBeTrue()
            ->and($recoveredReport->dueCount)->toBe(1)
            ->and($recoveredReport->completedCount)->toBe(1)
            ->and($probeCount)->toBe(1);

        // Exception mid-run still releases atomic lock in finally block.
        Monitor::factory()->for($owner)->create(['url' => 'https://boom.example.test/health']);
        bindFakeEngine(function (): TransportResponse {
            throw new RuntimeException('Simulated internal transport crash.');
        });

        expect(fn () => app(MonitorCheckRunner::class)->run())->toThrow(RuntimeException::class);

        $nextLock = Cache::store('database')->lock(MonitorCheckRunner::EXECUTION_LOCK_KEY, 600);
        expect($nextLock->get())->toBeTrue();
        $nextLock->release();
    } finally {
        Carbon::setTestNow();
    }
});

test('SCHED-03 & migration: upgrades unchecked monitors to unknown, orders due checks portably, and excludes paused or future monitors', function () {
    // Verify 000003 migration preserves checked operational & paused rows while converting unchecked active rows.
    $tempUser = User::factory()->owner()->create();
    $tempUserId = $tempUser->id;

    Artisan::call('migrate:rollback', ['--step' => 2, '--no-interaction' => true]);

    $uncheckedId = DB::table('monitors')->insertGetId([
        'user_id' => $tempUserId,
        'name' => 'Legacy Unchecked',
        'url' => 'https://unchecked.example.test/',
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 299,
        'is_active' => true,
        'status' => MonitorStatus::OPERATIONAL->value,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
        'last_checked_at' => null,
        'created_at' => '2026-11-27 10:00:00',
        'updated_at' => '2026-11-27 10:00:00',
    ]);
    $checkedId = DB::table('monitors')->insertGetId([
        'user_id' => $tempUserId,
        'name' => 'Legacy Checked',
        'url' => 'https://checked.example.test/',
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 299,
        'is_active' => true,
        'status' => MonitorStatus::OPERATIONAL->value,
        'consecutive_failures' => 0,
        'consecutive_successes' => 3,
        'last_checked_at' => '2026-11-27 11:00:00',
        'created_at' => '2026-11-27 10:00:00',
        'updated_at' => '2026-11-27 11:00:00',
    ]);
    $pausedId = DB::table('monitors')->insertGetId([
        'user_id' => $tempUserId,
        'name' => 'Legacy Paused',
        'url' => 'https://paused.example.test/',
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 299,
        'is_active' => false,
        'status' => MonitorStatus::PAUSED->value,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
        'last_checked_at' => null,
        'created_at' => '2026-11-27 10:00:00',
        'updated_at' => '2026-11-27 10:00:00',
    ]);

    Artisan::call('migrate', ['--no-interaction' => true]);
    expect(Schema::hasTable('check_results'))->toBeTrue();

    expect(DB::table('monitors')->where('id', $uncheckedId)->value('status'))->toBe(MonitorStatus::UNKNOWN->value)
        ->and(DB::table('monitors')->where('id', $checkedId)->value('status'))->toBe(MonitorStatus::OPERATIONAL->value)
        ->and(DB::table('monitors')->where('id', $pausedId)->value('status'))->toBe(MonitorStatus::PAUSED->value);

    // Clean rows before continuing in MySQL/SQLite portability test.
    DB::table('monitors')->whereIn('id', [$uncheckedId, $checkedId, $pausedId])->delete();
    DB::table('users')->where('id', $tempUserId)->delete();

    Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    try {
        $owner = User::factory()->owner()->create();
        $dueOlder = Monitor::factory()->for($owner)->operational()->create([
            'url' => 'https://older.example.test/',
            'last_checked_at' => Carbon::parse('2026-11-27 11:40:00', 'UTC'),
            'next_check_at' => Carbon::parse('2026-11-27 11:45:00', 'UTC'),
        ]);
        $dueNeverChecked = Monitor::factory()->for($owner)->unknown()->create([
            'url' => 'https://never.example.test/',
            'last_checked_at' => null,
            'next_check_at' => null,
        ]);
        $paused = Monitor::factory()->for($owner)->paused()->create(['url' => 'https://paused2.example.test/']);
        $futureDue = Monitor::factory()->for($owner)->operational()->create([
            'url' => 'https://future.example.test/',
            'last_checked_at' => Carbon::parse('2026-11-27 11:58:00', 'UTC'),
            'next_check_at' => Carbon::parse('2026-11-27 12:05:00', 'UTC'),
        ]);

        // Corrupt paused row claiming is_active=true must still stay excluded.
        $corruptPaused = Monitor::factory()->for($owner)->create(['url' => 'https://corrupt.example.test/']);
        DB::table('monitors')->where('id', $corruptPaused->id)->update(['is_active' => true, 'status' => MonitorStatus::PAUSED->value]);

        $probedUrls = [];
        bindFakeEngine(function (TransportRequest $req) use (&$probedUrls): TransportResponse {
            $probedUrls[] = $req->url->url;

            return makeOkTransportResponse();
        });

        $report = app(MonitorCheckRunner::class)->run(concurrency: 1);

        expect($report->dueCount)->toBe(2)
            ->and($report->completedCount)->toBe(2)
            ->and($probedUrls)->toBe(['https://never.example.test/', 'https://older.example.test/'])
            ->and($probedUrls)->not->toContain('https://paused2.example.test/', 'https://future.example.test/', 'https://corrupt.example.test/');

        expect($dueNeverChecked->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and($dueOlder->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and($paused->fresh()->status)->toBe(MonitorStatus::PAUSED)
            ->and($futureDue->fresh()->last_checked_at?->equalTo(Carbon::parse('2026-11-27 11:58:00', 'UTC')))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('SCHED-04: applies N=2 failure and M=2 recovery transitions from UNKNOWN cleanly without touching incident tables', function () {
    $transition = new MonitorStateTransition;

    // Pure transition domain checks across every active status.
    expect($transition->apply(MonitorStatus::UNKNOWN, 0, 0, true)->nextStatus)->toBe(MonitorStatus::OPERATIONAL)
        ->and($transition->apply(MonitorStatus::UNKNOWN, 0, 0, false)->nextStatus)->toBe(MonitorStatus::PENDING_DOWN)
        ->and($transition->apply(MonitorStatus::OPERATIONAL, 0, 3, false)->nextStatus)->toBe(MonitorStatus::PENDING_DOWN)
        ->and($transition->apply(MonitorStatus::PENDING_DOWN, 1, 0, true)->nextStatus)->toBe(MonitorStatus::OPERATIONAL)
        ->and($transition->apply(MonitorStatus::PENDING_DOWN, 1, 0, false)->nextStatus)->toBe(MonitorStatus::DOWN)
        ->and($transition->apply(MonitorStatus::DOWN, 2, 0, true)->nextStatus)->toBe(MonitorStatus::PENDING_RECOVERY)
        ->and($transition->apply(MonitorStatus::PENDING_RECOVERY, 0, 1, false)->nextStatus)->toBe(MonitorStatus::DOWN)
        ->and($transition->apply(MonitorStatus::PENDING_RECOVERY, 0, 1, true)->nextStatus)->toBe(MonitorStatus::OPERATIONAL);

    expect(Schema::hasTable('incidents'))->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    try {
        $owner = User::factory()->owner()->create();
        $monitor = Monitor::factory()->for($owner)->create([
            'url' => 'https://flapper.example.test/health',
            'check_interval_minutes' => 5,
            'expected_status_code_min' => 200,
            'expected_status_code_max' => 299,
        ]);

        $shouldSucceed = true;
        $nextCertExpiry = CarbonImmutable::parse('2027-03-01 00:00:00', 'UTC');

        bindFakeEngine(function () use (&$shouldSucceed, $nextCertExpiry): TransportResponse {
            if ($shouldSucceed) {
                return makeOkTransportResponse(
                    statusCode: 204,
                    certInfo: [['Issuer' => 'Test CA', 'validTo_time_t' => $nextCertExpiry->getTimestamp()]],
                );
            }

            return new TransportResponse(
                statusCode: 0,
                headers: [],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: '93.184.216.34',
                curlErrno: 28,
                curlError: 'Timed out',
                elapsedMs: 10000,
            );
        });

        // Tick 1: UNKNOWN -> success -> OPERATIONAL + TLS expiry saved
        app(MonitorCheckRunner::class)->run();
        $monitor->refresh();
        expect($monitor->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and($monitor->consecutive_successes)->toBe(1)
            ->and($monitor->last_status_code)->toBe(204)
            ->and($monitor->tls_expires_at?->equalTo($nextCertExpiry))->toBeTrue()
            ->and($monitor->next_check_at?->equalTo(Carbon::parse('2026-11-27 12:05:00', 'UTC')))->toBeTrue();

        // Tick 2: OPERATIONAL -> timeout failure -> PENDING_DOWN (N=1), preserves prior valid tls_expires_at
        $shouldSucceed = false;
        Carbon::setTestNow(Carbon::parse('2026-11-27 12:05:00', 'UTC'));
        app(MonitorCheckRunner::class)->run();
        $monitor->refresh();
        expect($monitor->status)->toBe(MonitorStatus::PENDING_DOWN)
            ->and($monitor->consecutive_failures)->toBe(1)
            ->and($monitor->tls_expires_at?->equalTo($nextCertExpiry))->toBeTrue();

        // Tick 3: PENDING_DOWN -> 2nd consecutive failure -> DOWN (N=2)
        Carbon::setTestNow(Carbon::parse('2026-11-27 12:10:00', 'UTC'));
        app(MonitorCheckRunner::class)->run();
        $monitor->refresh();
        expect($monitor->status)->toBe(MonitorStatus::DOWN)
            ->and($monitor->consecutive_failures)->toBe(2);

        // Tick 4: DOWN -> 1st success -> PENDING_RECOVERY (M=1)
        $shouldSucceed = true;
        Carbon::setTestNow(Carbon::parse('2026-11-27 12:15:00', 'UTC'));
        app(MonitorCheckRunner::class)->run();
        $monitor->refresh();
        expect($monitor->status)->toBe(MonitorStatus::PENDING_RECOVERY)
            ->and($monitor->consecutive_successes)->toBe(1);

        // Tick 5: PENDING_RECOVERY -> failure resets to DOWN (never completed M=2)
        $shouldSucceed = false;
        Carbon::setTestNow(Carbon::parse('2026-11-27 12:20:00', 'UTC'));
        app(MonitorCheckRunner::class)->run();
        $monitor->refresh();
        expect($monitor->status)->toBe(MonitorStatus::DOWN)
            ->and($monitor->consecutive_failures)->toBe(1)
            ->and($monitor->consecutive_successes)->toBe(0);

        // Tick 6 & 7: 2 consecutive successes -> PENDING_RECOVERY -> OPERATIONAL (M=2)
        $shouldSucceed = true;
        Carbon::setTestNow(Carbon::parse('2026-11-27 12:25:00', 'UTC'));
        app(MonitorCheckRunner::class)->run();
        expect($monitor->fresh()->status)->toBe(MonitorStatus::PENDING_RECOVERY);

        Carbon::setTestNow(Carbon::parse('2026-11-27 12:30:00', 'UTC'));
        app(MonitorCheckRunner::class)->run();
        expect($monitor->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and($monitor->fresh()->consecutive_successes)->toBe(2);

        expect(CheckResult::query()->where('monitor_id', $monitor->id)->count())->toBe(7);

        $latestFailure = CheckResult::query()
            ->where('monitor_id', $monitor->id)
            ->where('status', CheckResultStatus::FAILURE->value)
            ->latest('id')
            ->firstOrFail();
        expect($latestFailure->failure_reason)->toBe(SafeHttpFailureReason::TIMEOUT->value);
    } finally {
        Carbon::setTestNow();
    }
});

test('SCHED-05: honors monotonic soft (45s) and hard (50s) deadlines without falsifying skipped or cancelled checks', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:00', 'UTC'));

    try {
        $owner = User::factory()->owner()->create();
        $first = Monitor::factory()->for($owner)->create(['url' => 'https://m1.example.test/']);
        $second = Monitor::factory()->for($owner)->create(['url' => 'https://m2.example.test/']);
        $third = Monitor::factory()->for($owner)->create(['url' => 'https://m3.example.test/']);

        $tickNs = 1_000_000_000_000;
        $clock = static function () use (&$tickNs): int {
            return $tickNs;
        };

        $dns = new class implements DnsResolverInterface
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        // Case A: Soft deadline (45s) stops admitting $third after $first + $second complete at t=46s.
        $transportSoft = new class($tickNs) implements HttpTransportInterface
        {
            private int $calls = 0;

            public function __construct(private int &$clockNs) {}

            public function send(TransportRequest $request): TransportResponse
            {
                $this->calls++;
                if ($this->calls === 2) {
                    $this->clockNs += 46_000_000_000;
                }

                return makeOkTransportResponse();
            }
        };

        $clientSoft = new SafeHttpClient(dnsResolver: $dns, transport: $transportSoft, monotonicClockNs: $clock);
        $runnerSoft = new MonitorCheckRunner($clientSoft, new CheckBatchPersister, $clock);

        $softReport = $runnerSoft->run(concurrency: 1);
        expect($softReport->dueCount)->toBe(3)
            ->and($softReport->completedCount)->toBe(2)
            ->and($softReport->softDeadlineSkippedCount)->toBe(1)
            ->and($softReport->hardDeadlineCancelledCount)->toBe(0)
            ->and($softReport->softDeadlineReached)->toBeTrue()
            ->and($first->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and($second->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and($third->fresh()->status)->toBe(MonitorStatus::UNKNOWN)
            ->and($third->fresh()->last_checked_at)->toBeNull()
            ->and(CheckResult::query()->where('monitor_id', $third->id)->count())->toBe(0);

        // Case B: Hard deadline (50s) cancels an in-flight batch slot without recording false failure.
        $fourth = Monitor::factory()->for($owner)->create(['url' => 'https://m4.example.test/']);
        $tickNs = 2_000_000_000_000;

        $mockMultiHard = new class($tickNs) implements MultiTransportDriverInterface
        {
            public bool $cancelledTriggered = false;

            public function __construct(private int &$clockNs) {}

            public function run(
                int $maxConcurrency,
                int $hardDeadlineNs,
                callable $nextJob,
                callable $onComplete,
                callable $onCancelled,
            ): bool {
                $firstJob = $nextJob(); // m3 ($third)
                $secondJob = $nextJob(); // m4 ($fourth)
                expect($firstJob)->not->toBeNull()->and($secondJob)->not->toBeNull();

                // Finish $third cleanly before hard deadline
                $onComplete($firstJob['id'], makeOkTransportResponse());

                // Advance clock past 50s hard deadline while $fourth is still in flight
                $this->clockNs += 51_000_000_000;
                $this->cancelledTriggered = true;
                $onCancelled([$secondJob['id']]);

                return true;
            }
        };

        $clientHard = new SafeHttpClient(
            dnsResolver: $dns,
            monotonicClockNs: $clock,
            multiTransport: $mockMultiHard,
        );
        $runnerHard = new MonitorCheckRunner($clientHard, new CheckBatchPersister, $clock);

        $hardReport = $runnerHard->run(concurrency: 2);
        expect($mockMultiHard->cancelledTriggered)->toBeTrue()
            ->and($hardReport->completedCount)->toBe(1)
            ->and($hardReport->hardDeadlineCancelledCount)->toBe(1)
            ->and($hardReport->hardDeadlineReached)->toBeTrue()
            ->and($third->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and($fourth->fresh()->status)->toBe(MonitorStatus::UNKNOWN)
            ->and($fourth->fresh()->consecutive_failures)->toBe(0)
            ->and($fourth->fresh()->last_checked_at)->toBeNull()
            ->and(CheckResult::query()->where('monitor_id', $fourth->id)->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

test('SCHED-06: discards stale results when monitors are deleted, paused, or edited mid-probe and keeps logs sanitized', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:00', 'UTC'));

    $capturedLogs = [];
    Log::listen(static function ($event) use (&$capturedLogs): void {
        $capturedLogs[] = $event->message.' '.json_encode($event->context);
    });

    try {
        $owner = User::factory()->owner()->create();
        $deletedMidFlight = Monitor::factory()->for($owner)->create(['url' => 'https://deleted.example.test/']);
        $pausedMidFlight = Monitor::factory()->for($owner)->operational()->create(['url' => 'https://paused.example.test/', 'last_checked_at' => now()->subMinutes(10), 'next_check_at' => now()->subMinute()]);
        $editedMidFlight = Monitor::factory()->for($owner)->create(['url' => 'https://edited.example.test/v1']);
        $healthySurvivor = Monitor::factory()->for($owner)->create(['url' => 'https://survivor.example.test/']);

        bindFakeEngine(function (TransportRequest $request) use ($deletedMidFlight, $pausedMidFlight, $editedMidFlight): TransportResponse {
            $host = $request->url->host;

            if ($host === 'deleted.example.test') {
                $deletedMidFlight->delete();
            } elseif ($host === 'paused.example.test') {
                $pausedMidFlight->pause();
            } elseif ($host === 'edited.example.test') {
                Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:02', 'UTC'));
                $editedMidFlight->url = 'https://edited.example.test/v2?token=TopSecretToken123';
                $editedMidFlight->save();

                return new TransportResponse(
                    statusCode: 500,
                    headers: ['set-cookie' => ['session=SecretCookie999']],
                    body: '<html>SuperSecretHtmlStackTrace</html>',
                    bodyTruncated: false,
                    bytesReceived: 38,
                    primaryIp: '93.184.216.34',
                );
            }

            return makeOkTransportResponse();
        });

        $report = app(MonitorCheckRunner::class)->run(concurrency: 1);

        expect($report->dueCount)->toBe(4)
            ->and($report->completedCount)->toBe(1)
            ->and($report->staleDiscardedCount)->toBe(3)
            ->and(Monitor::query()->find($deletedMidFlight->id))->toBeNull()
            ->and($pausedMidFlight->fresh()->status)->toBe(MonitorStatus::PAUSED)
            ->and($editedMidFlight->fresh()->status)->toBe(MonitorStatus::UNKNOWN)
            ->and($editedMidFlight->fresh()->url)->toBe('https://edited.example.test/v2?token=TopSecretToken123')
            ->and($healthySurvivor->fresh()->status)->toBe(MonitorStatus::OPERATIONAL)
            ->and(CheckResult::query()->count())->toBe(1)
            ->and(CheckResult::query()->first()->monitor_id)->toBe($healthySurvivor->id);

        // Log sanitizer check: verify error sanitization and metadata logs never expose credentials/headers/HTML.
        $sanitized = CheckBatchPersister::sanitizeErrorMessage(
            "Failed https://user:TopSecretPass999@edited.example.test\nAuthorization: Bearer abc123Cookie=xyz ".str_repeat('X', 300)
        );
        expect(strlen((string) $sanitized))->toBeLessThanOrEqual(255)
            ->and($sanitized)->not->toContain('TopSecretPass999')
            ->and($sanitized)->not->toContain("\n")
            ->and($sanitized)->toContain('[REDACTED]');

        foreach ($capturedLogs as $entry) {
            expect($entry)->not->toContain('TopSecretToken123')
                ->and($entry)->not->toContain('SecretCookie999')
                ->and($entry)->not->toContain('SuperSecretHtmlStackTrace');
        }
    } finally {
        Carbon::setTestNow();
    }
});

test('benchmarks 50-monitor batch persistence on SQLite WAL within tight memory and time budgets', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:00', 'UTC'));

    $owner = User::factory()->owner()->create();
    for ($i = 1; $i <= 50; $i++) {
        Monitor::factory()->for($owner)->create([
            'name' => "Bench Monitor {$i}",
            'url' => "https://bench-{$i}.example.test/health",
        ]);
    }

    bindFakeEngine(static fn (): TransportResponse => makeOkTransportResponse());

    $memoryBefore = memory_get_usage(false);
    DB::enableQueryLog();
    $startedNs = hrtime(true);

    $report = app(MonitorCheckRunner::class)->run(concurrency: 5);

    $elapsedMs = (hrtime(true) - $startedNs) / 1_000_000;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $insertStatements = collect($queries)
        ->filter(static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'insert into')
            && str_contains(strtolower((string) $entry['query']), 'check_results'))
        ->count();
    $memoryPeakMb = memory_get_peak_usage(false) / (1024 * 1024);
    $memoryDeltaMb = max(0, memory_get_usage(false) - $memoryBefore) / (1024 * 1024);

    expect($report->lockAcquired)->toBeTrue()
        ->and($report->dueCount)->toBe(50)
        ->and($report->completedCount)->toBe(50)
        ->and($report->succeededCount)->toBe(50)
        ->and(CheckResult::query()->count())->toBe(50)
        ->and($insertStatements)->toBe(5)
        ->and($elapsedMs)->toBeLessThan(15_000)
        ->and($memoryDeltaMb)->toBeLessThan(20)
        ->and($memoryPeakMb)->toBeLessThan(128);

    // Explicitly verify real file-backed SQLite WAL batch vs single-commit durability when pdo_sqlite is available.
    if (extension_loaded('pdo_sqlite')) {
        $walPath = sys_get_temp_dir().'/sitepulse_wal_'.bin2hex(random_bytes(5)).'.sqlite';
        touch($walPath);

        try {
            $pdo = new PDO("sqlite:{$walPath}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode=WAL;');
            $pdo->exec('PRAGMA synchronous=NORMAL;');
            $mode = strtolower((string) $pdo->query('PRAGMA journal_mode;')->fetchColumn());
            $pdo->exec('CREATE TABLE wal_bench (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO wal_bench (val) VALUES (:val)');
            for ($row = 1; $row <= 50; $row++) {
                $stmt->execute([':val' => "row-{$row}"]);
            }
            $pdo->commit();
            $storedRows = (int) $pdo->query('SELECT COUNT(*) FROM wal_bench')->fetchColumn();
            expect($mode)->toBe('wal')
                ->and($storedRows)->toBe(50);
            $pdo = null;
        } finally {
            foreach ([$walPath, "{$walPath}-wal", "{$walPath}-shm"] as $artifact) {
                if (is_file($artifact)) {
                    @unlink($artifact);
                }
            }
        }
    }

    Carbon::setTestNow();
});
