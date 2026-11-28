<?php

use App\Enums\CheckResultStatus;
use App\Enums\IncidentStatus;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Dashboard\MonitorHistory;
use App\Support\Monitoring\CheckHistoryPruner;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\Contracts\MultiTransportDriverInterface;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function recordHistoryCheck(
    Monitor $monitor,
    CarbonImmutable $checkedAt,
    CheckResultStatus $status = CheckResultStatus::SUCCESS,
    ?int $latency = 42,
): CheckResult {
    return $monitor->checkResults()->create([
        'checked_at' => $checkedAt->utc(),
        'status' => $status,
        'status_code' => $status === CheckResultStatus::SUCCESS ? 418 : 200,
        'latency_ms' => $latency,
        'failure_reason' => $status === CheckResultStatus::FAILURE ? 'tls_error' : null,
    ]);
}

test('uptime reports observed outcomes and distinguishes zero uptime from no data', function (
    array $statuses, int $successes, ?float $percentage,
) {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $asOf = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($asOf);
    foreach ($statuses as $step => $status) {
        recordHistoryCheck($monitor, $asOf->subMinutes($step + 1), $status);
    }

    $uptime = app(MonitorHistory::class)->uptime($monitor->id);
    expect($uptime['asOf']->equalTo($asOf))->toBeTrue()
        ->and(array_keys($uptime['windows']))->toBe(['24h', '7d', '30d']);
    foreach ($uptime['windows'] as $window) {
        expect($window)->toBe([
            'sampleCount' => count($statuses),
            'successfulCount' => $successes,
            'percentage' => $percentage,
        ]);
    }
})->with([
    'all successful' => [[CheckResultStatus::SUCCESS, CheckResultStatus::SUCCESS], 2, 100.0],
    'all failed' => [[CheckResultStatus::FAILURE, CheckResultStatus::FAILURE], 0, 0.0],
    'mixed' => [[CheckResultStatus::SUCCESS, CheckResultStatus::FAILURE], 1, 50.0],
    'no completed checks' => [[], 0, null],
]);

test('one UTC as-of timestamp defines exact rolling boundaries and excludes future records', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $asOf = CarbonImmutable::parse('2026-11-28T13:00:00.123+01:00');
    $time = $asOf->utc()->startOfSecond();
    $this->actingAs($owner);

    recordHistoryCheck($monitor, $time->addSecond());
    recordHistoryCheck($monitor, $time, CheckResultStatus::FAILURE);
    recordHistoryCheck($monitor, $time->subHours(24)->addSecond());
    recordHistoryCheck($monitor, $time->subHours(24), CheckResultStatus::FAILURE);
    recordHistoryCheck($monitor, $time->subHours(24)->subSecond());
    recordHistoryCheck($monitor, $time->subDays(7)->addSecond());
    recordHistoryCheck($monitor, $time->subDays(7), CheckResultStatus::FAILURE);
    recordHistoryCheck($monitor, $time->subDays(7)->subSecond(), CheckResultStatus::FAILURE);
    recordHistoryCheck($monitor, $time->subDays(30)->addSecond());
    recordHistoryCheck($monitor, $time->subDays(30));
    recordHistoryCheck($monitor, $time->subDays(30)->subSecond());

    $uptime = app(MonitorHistory::class)->uptime($monitor->id, $asOf);
    expect($uptime['asOf']->format('Y-m-d H:i:s.u e'))->toBe('2026-11-28 12:00:00.000000 UTC')
        ->and($uptime['windows'])->toBe([
            '24h' => ['sampleCount' => 2, 'successfulCount' => 1, 'percentage' => 50.0],
            '7d' => ['sampleCount' => 5, 'successfulCount' => 3, 'percentage' => 60.0],
            '30d' => ['sampleCount' => 8, 'successfulCount' => 4, 'percentage' => 50.0],
        ]);
});

test('partial, paused and pruned history counts only recorded samples', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->paused()->create();
    $asOf = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner);
    recordHistoryCheck($monitor, $asOf->subDays(31), CheckResultStatus::FAILURE);
    $retained = recordHistoryCheck($monitor, $asOf->subDays(9));
    $prune = app(CheckHistoryPruner::class)->prune(asOf: $asOf);

    $history = app(MonitorHistory::class);
    expect($monitor->fresh()->isPaused())->toBeTrue()
        ->and($prune['retentionDays'])->toBe(30)
        ->and($prune['deletedCount'])->toBe(1)
        ->and($history->checks($monitor->id, asOf: $asOf)->getCollection()->modelKeys())->toBe([$retained->id])
        ->and($history->uptime($monitor->id, $asOf)['windows'])->toBe([
            '24h' => ['sampleCount' => 0, 'successfulCount' => 0, 'percentage' => null],
            '7d' => ['sampleCount' => 0, 'successfulCount' => 0, 'percentage' => null],
            '30d' => ['sampleCount' => 1, 'successfulCount' => 1, 'percentage' => 100.0],
        ]);
});

test('all history reads authorize before querying telemetry', function () {
    $owner = User::factory()->owner()->create();
    $foreignUser = User::factory()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $foreign = Monitor::factory()->for($foreignUser)->create();
    $history = app(MonitorHistory::class);

    foreach (['uptime', 'checks', 'latencySamples', 'incidents'] as $read) {
        expect(fn () => $history->$read($monitor->id))->toThrow(AuthorizationException::class);
    }
    DB::enableQueryLog();
    $this->actingAs($foreignUser);
    foreach (['uptime', 'checks', 'latencySamples', 'incidents'] as $read) {
        expect(fn () => $history->$read($foreign->id))->toThrow(AuthorizationException::class);
    }
    $this->actingAs($owner);
    foreach (['uptime', 'checks', 'latencySamples', 'incidents'] as $read) {
        expect(fn () => $history->$read($foreign->id))->toThrow(ModelNotFoundException::class);
    }
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($queries)->toHaveCount(4);
    foreach ($queries as $sql) {
        expect($sql)->toContain('monitors', 'user_id')->not->toContain('check_results', 'incidents');
    }
});

test('check pages use timestamp and ID keysets in both directions and keep foreign rows out', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $other = Monitor::factory()->for($owner)->create();
    $asOf = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner);
    $tiedIds = collect(range(1, 27))->map(fn () => recordHistoryCheck($monitor, $asOf)->id);
    $older = recordHistoryCheck($monitor, $asOf->subSecond());
    recordHistoryCheck($other, $asOf);
    recordHistoryCheck($monitor, $asOf->addSecond());

    $history = app(MonitorHistory::class);
    $first = $history->checks($monitor->id, asOf: $asOf);
    $second = $history->checks($monitor->id, $first->nextCursor(), $asOf);
    $back = $history->checks($monitor->id, $second->previousCursor(), $asOf);

    expect($first->getCollection()->modelKeys())->toBe($tiedIds->reverse()->take(25)->values()->all())
        ->and($first->previousCursor())->toBeNull()
        ->and($second->getCollection()->modelKeys())->toBe([$tiedIds[1], $tiedIds[0], $older->id])
        ->and($second->nextCursor())->toBeNull()
        ->and($back->getCollection()->modelKeys())->toBe($first->getCollection()->modelKeys());
});

test('latency samples keep null gaps and zero milliseconds, with a deterministic limit', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $asOf = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner);
    foreach (range(1, 62) as $minute) {
        recordHistoryCheck($monitor, $asOf->subMinutes($minute));
    }
    recordHistoryCheck($monitor, $asOf, latency: 0);
    recordHistoryCheck($monitor, $asOf, CheckResultStatus::FAILURE, null);
    recordHistoryCheck($monitor, $asOf->addSecond(), latency: 901);

    $samples = app(MonitorHistory::class)->latencySamples($monitor->id, $asOf);
    expect($samples)->toHaveCount(60)
        ->and($samples->take(3)->pluck('latency_ms')->all())->toBe([null, 0, 42])
        ->and($samples->last()->checked_at->equalTo($asOf->subMinutes(58)))->toBeTrue()
        ->and(array_keys($samples->first()->getAttributes()))->toBe(['id', 'checked_at', 'latency_ms']);
});

test('incident pages show stored cause and duration, with filters and deterministic ties', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $other = Monitor::factory()->for($owner)->create();
    $asOf = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $startedAt = $asOf->subSeconds(150);
    $this->actingAs($owner);
    $incident = $monitor->incidents()->create([
        'open_monitor_id' => $monitor->id,
        'started_at' => $startedAt,
        'status' => IncidentStatus::OPEN,
        'root_cause' => 'HTTP 503',
    ]);
    $other->incidents()->create(['started_at' => $startedAt, 'status' => IncidentStatus::OPEN]);

    $history = app(MonitorHistory::class);
    $open = $history->incidents($monitor->id, IncidentStatus::OPEN, asOf: $asOf)->getCollection()->sole();
    expect($open->id)->toBe($incident->id)
        ->and($open->started_at->equalTo($startedAt))->toBeTrue()
        ->and($open->resolved_at)->toBeNull()
        ->and($open->duration_seconds)->toBeNull()
        ->and($open->root_cause)->toBe('HTTP 503');

    DB::transaction(fn () => $monitor->resolveOpenIncidentSilently($asOf));
    $resolved = $history->incidents($monitor->id, IncidentStatus::RESOLVED, asOf: $asOf)->getCollection()->sole();
    expect($resolved->id)->toBe($incident->id)
        ->and($resolved->status)->toBe(IncidentStatus::RESOLVED)
        ->and($resolved->resolved_at->equalTo($asOf))->toBeTrue()
        ->and($resolved->duration_seconds)->toBe(150)
        ->and($resolved->root_cause)->toBe('HTTP 503')
        ->and($history->incidents($monitor->id, IncidentStatus::OPEN, asOf: $asOf)->isEmpty())->toBeTrue();

    $olderIds = collect(range(1, 26))->map(fn () => $monitor->incidents()->create([
        'started_at' => $asOf->subDays(45),
        'resolved_at' => $asOf->subDays(45)->addMinute(),
        'duration_seconds' => 60,
        'status' => IncidentStatus::RESOLVED,
    ])->id);
    $monitor->incidents()->create(['started_at' => $asOf->addSecond(), 'status' => IncidentStatus::OPEN]);
    $first = $history->incidents($monitor->id, asOf: $asOf);
    $second = $history->incidents($monitor->id, cursor: $first->nextCursor(), asOf: $asOf);
    $back = $history->incidents($monitor->id, cursor: $second->previousCursor(), asOf: $asOf);
    expect($first->getCollection()->modelKeys())->toBe($olderIds->reverse()->take(24)->prepend($incident->id)->values()->all())
        ->and($second->getCollection()->modelKeys())->toBe([$olderIds[1], $olderIds[0]])
        ->and($second->nextCursor())->toBeNull()
        ->and($back->getCollection()->modelKeys())->toBe($first->getCollection()->modelKeys());
});

test('editing the target URL preserves check and incident history by monitor ID', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->operational()->create();
    $asOf = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($asOf);
    $check = recordHistoryCheck($monitor, $asOf->subMinute());
    $incident = $monitor->incidents()->create([
        'started_at' => $asOf->subDays(2),
        'resolved_at' => $asOf->subDays(2)->addMinute(),
        'duration_seconds' => 60,
        'status' => IncidentStatus::RESOLVED,
    ]);

    $this->put(route('monitors.update', $monitor), [
        ...$monitor->only(['name', 'check_interval_minutes', 'timeout_seconds', 'expected_status_code_min', 'expected_status_code_max', 'is_active']),
        'url' => 'https://new-target.example.test/health',
    ])->assertRedirect(route('monitors.show', $monitor));

    $history = app(MonitorHistory::class);
    expect($monitor->fresh()->url)->toBe('https://new-target.example.test/health')
        ->and($monitor->fresh()->last_checked_at)->toBeNull()
        ->and($history->checks($monitor->id)->getCollection()->modelKeys())->toBe([$check->id])
        ->and($history->uptime($monitor->id)['windows']['24h']['sampleCount'])->toBe(1)
        ->and($history->incidents($monitor->id)->getCollection()->modelKeys())->toBe([$incident->id]);
});

test('history reads issue only bounded selective queries and never initiate network activity', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $this->actingAs($owner);
    $this->mock(DnsResolverInterface::class)->shouldNotReceive('resolve');
    $this->mock(HttpTransportInterface::class)->shouldNotReceive('send');
    $this->mock(MultiTransportDriverInterface::class)->shouldNotReceive('run');

    DB::enableQueryLog();
    $history = app(MonitorHistory::class);
    $history->uptime($monitor->id);
    $history->checks($monitor->id);
    $history->latencySamples($monitor->id);
    $history->incidents($monitor->id);
    $queries = array_map(fn (array $query) => str_replace(['"', '`'], '', strtolower($query['query'])), DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toHaveCount(8);
    foreach ([0, 2, 4, 6] as $position) {
        expect($queries[$position])->toStartWith('select id, user_id from monitors')->toContain('where user_id = ?');
    }
    foreach ([1, 3, 5, 7] as $position) {
        expect($queries[$position])->toStartWith('select ')->toContain('monitor_id = ?')->not->toContain('select *', 'offset');
    }
    expect($queries[1])->toContain('count(case', 'checked_at > ?', 'checked_at <= ?')
        ->and($queries[3])->toContain('limit 26')->not->toContain('error_message')
        ->and($queries[5])->toStartWith('select id, checked_at, latency_ms')->toContain('limit 60')
        ->and($queries[7])->toContain('limit 26')->not->toContain('open_monitor_id');
});
