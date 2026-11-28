<?php

use App\Enums\CheckResultStatus;
use App\Enums\IncidentStatus;
use App\Livewire\MonitorDetail;
use App\Models\Monitor;
use App\Models\User;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\Contracts\MultiTransportDriverInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function monitorDetailActionToken(string $html, string $action): string
{
    preg_match('/data-cursor="([A-Za-z0-9_.-]+)"\s+x-on:click="if \(\$el\.dataset\.cursor\) runHistoryAction\(\''.preg_quote($action, '/').'\', \$el\.dataset\.cursor\)"/', $html, $matches);

    return $matches[1] ?? throw new RuntimeException("Missing $action cursor");
}

function monitorDetailSnapshot(string $html): string
{
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

    return html_entity_decode($matches[1] ?? '', ENT_QUOTES, 'UTF-8');
}

function monitorDetailPayload(string $snapshot): array
{
    return ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => 'refreshSnapshot', 'params' => []]],
    ]]];
}

test('monitor detail gates guests, non-owners and foreign resources on both page and update', function () {
    $owner = User::factory()->owner()->create();
    $outsider = User::factory()->create();
    $monitor = Monitor::factory()->for($owner)->create(['name' => 'Private synthetic monitor']);
    $foreign = Monitor::factory()->for($outsider)->create();

    $this->get(route('monitors.show', $monitor))->assertRedirect(route('login'));
    $this->actingAs($outsider)->get(route('monitors.show', $monitor))->assertForbidden();
    $this->actingAs($owner)->get(route('monitors.show', $foreign))->assertForbidden();
    Livewire::test(MonitorDetail::class, ['monitor' => $foreign])->assertForbidden();

    $html = $this->get(route('monitors.show', $monitor))->assertOk()->getContent();
    $payload = monitorDetailPayload(monitorDetailSnapshot($html));
    $this->actingAs($outsider)->postJson(route('default-livewire.update'), $payload, ['X-Livewire' => 'true'])
        ->assertForbidden()->assertDontSee($monitor->name);

    // A valid snapshot must also lose access when its monitor is assigned to a different owner.
    $this->actingAs($owner);
    DB::table('monitors')->where('id', $monitor->id)->update(['user_id' => $outsider->id]);
    $this->postJson(route('default-livewire.update'), $payload, ['X-Livewire' => 'true'])
        ->assertForbidden()->assertDontSee($monitor->name);
});

test('client updates cannot set protected detail identity, reference, pagination or filters', function (string $property, mixed $value) {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $component = Livewire::actingAs($owner)->test(MonitorDetail::class, ['monitor' => $monitor]);

    expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['monitor', 1234],
    ['monitor.id', 1234],
    ['asOf', '2040-01-01 00:00:00'],
    ['checkCursor', 'arbitrary'],
    ['incidentCursor', 'arbitrary'],
    ['incidentFilter', 'resolved'],
]);

test('detail presents no data, observed zero and distinct UTC uptime windows with sample counts', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($now);

    $component = Livewire::test(MonitorDetail::class, ['monitor' => $monitor])
        ->assertSee('Observed-check uptime')->assertSee('No data')->assertSee('0 completed checks')
        ->assertSee('No check samples yet.')->assertSee('No recorded checks yet.')
        ->assertSee('No incidents match this filter.')->assertDontSee('100%');

    foreach ([
        [$now->subMinute(), CheckResultStatus::FAILURE],
        [$now->subDays(2), CheckResultStatus::SUCCESS],
        [$now->subDays(10), CheckResultStatus::SUCCESS],
        [$now->addSecond(), CheckResultStatus::SUCCESS],
    ] as [$time, $status]) {
        $monitor->checkResults()->create(['checked_at' => $time, 'status' => $status]);
    }

    $component->call('refreshSnapshot')
        ->assertSeeInOrder(['Last 24 hours', '0%', '1 completed check', 'Last 7 days', '50%', '2 completed checks', 'Last 30 days', '66.67%', '3 completed checks'])
        ->assertSee('Nov 28, 2026 12:00:00')->assertSee('UTC');
});

test('non-extreme results stay distinct from zero and one hundred percent after rounding', function (
    CheckResultStatus $majority,
    CheckResultStatus $minority,
    string $expected,
    string $misleading,
) {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($now);
    $row = ['monitor_id' => $monitor->id, 'checked_at' => $now, 'status' => $majority->value];
    DB::transaction(function () use ($row, $monitor, $minority): void {
        for ($batch = 0; $batch < 80; $batch++) {
            DB::table('check_results')->insert(array_fill(0, 250, $row));
        }
        $monitor->checkResults()->create(['checked_at' => $row['checked_at'], 'status' => $minority]);
    });

    Livewire::test(MonitorDetail::class, ['monitor' => $monitor])
        ->assertSee($expected)->assertSee('20,001 completed checks')->assertDontSeeHtml($misleading);
})->with([
    'near 100 percent' => [CheckResultStatus::SUCCESS, CheckResultStatus::FAILURE, '>99.99%', '>100%</dd>'],
    'near zero' => [CheckResultStatus::FAILURE, CheckResultStatus::SUCCESS, '<0.01%', '>0%</dd>'],
]);

test('destructive form is behind a closed confirmation that explains its cascade', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create(['name' => 'Synthetic cascade']);
    $check = $monitor->checkResults()->create(['checked_at' => now(), 'status' => CheckResultStatus::SUCCESS]);
    $incident = $monitor->incidents()->create(['started_at' => now(), 'status' => IncidentStatus::OPEN]);

    $html = $this->actingAs($owner)->get(route('monitors.show', $monitor))->assertOk()->getContent();
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($document);
    $disclosure = $xpath->query('//details[summary[contains(., "Delete Monitor")]]')->item(0);

    expect($disclosure)->toBeInstanceOf(DOMElement::class);
    expect($disclosure->hasAttribute('open'))->toBeFalse()
        ->and($xpath->query('.//summary//button[@type="submit"]', $disclosure)->length)->toBe(0)
        ->and($xpath->query('.//form[@method="POST"]/input[@name="_method"][@value="DELETE"]', $disclosure)->length)->toBe(1)
        ->and($xpath->query('.//form/input[@name="_token"]', $disclosure)->length)->toBe(1)
        ->and($xpath->query('.//form/button[@type="submit"][@aria-describedby="monitor-delete-warning"]', $disclosure)->length)->toBe(1)
        ->and($disclosure->textContent)->toContain('Permanently delete Synthetic cascade and its check and incident history.');

    $this->assertDatabaseHas('check_results', ['id' => $check->id]);
    $this->assertDatabaseHas('incidents', ['id' => $incident->id]);
    $this->post(route('monitors.destroy', $monitor), ['_method' => 'DELETE'])->assertRedirect(route('monitors.index'));
    $this->assertDatabaseMissing('monitors', ['id' => $monitor->id]);
    $this->assertDatabaseMissing('check_results', ['id' => $check->id]);
    $this->assertDatabaseMissing('incidents', ['id' => $incident->id]);
});

test('incident resolution after the locked history cutoff is identified as a later record update', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $reference = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($reference);
    $monitor->incidents()->create([
        'started_at' => $reference->subMinutes(5), 'open_monitor_id' => $monitor->id,
        'status' => IncidentStatus::OPEN, 'root_cause' => 'Synthetic incident',
    ]);

    $detail = Livewire::test(MonitorDetail::class, ['monitor' => $monitor])
        ->assertSee('History cutoff')->assertSee('Still open')->assertSee('Ongoing duration')
        ->assertDontSee('Resolved after the cutoff');

    $this->travel(2)->minutes();
    DB::transaction(fn () => $monitor->resolveOpenIncidentSilently($reference->addMinutes(2)));
    $detail->call('filterIncidents', 'resolved')->assertSet('asOf', $reference->toDateTimeString())
        ->assertSee('Status and resolution show the latest saved record.')
        ->assertSeeInOrder(['Nov 28, 2026 12:02:00', 'Resolved after the cutoff', 'Recorded duration', '7m'])
        ->call('refreshSnapshot')->assertSet('asOf', $reference->addMinutes(2)->toDateTimeString())
        ->assertDontSee('Resolved after the cutoff')->assertSee('Recorded duration');
});

test('latency graph is chronological, proportional to elapsed time and preserves null and zero', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($now);

    foreach ([[0, 100], [60, 0], [10, 100], [45, null]] as [$offset, $latency]) {
        $monitor->checkResults()->create([
            'checked_at' => $now->subSeconds($offset), 'status' => CheckResultStatus::SUCCESS, 'latency_ms' => $latency,
        ]);
    }

    Livewire::test(MonitorDetail::class, ['monitor' => $monitor])
        ->assertSee('3 of 4 recorded checks reported latency.')
        ->assertSee('Measured range 0–100 ms.')
        ->assertSeeInOrder(['Nov 28, 2026 11:59:00', 'Nov 28, 2026 12:00:00', 'UTC', 'Latency data table', 'Nov 28, 2026 11:59:00', '0 ms', 'Nov 28, 2026 11:59:15', 'No data', 'Nov 28, 2026 11:59:50', '100 ms', 'Nov 28, 2026 12:00:00'])
        ->assertSeeHtml('d="M 8 152 h 0"')->assertSeeHtml('d="M 594.67 8 h 0"')->assertSeeHtml('d="M 712 8 h 0"');
});

test('latency handles single, constant and missing-only measurements', function (array $values, string $message, ?string $mark) {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $time = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($time);
    foreach ($values as $value) {
        $monitor->checkResults()->create(['checked_at' => $time, 'status' => CheckResultStatus::SUCCESS, 'latency_ms' => $value]);
    }
    $component = Livewire::test(MonitorDetail::class, ['monitor' => $monitor])
        ->assertSee($message)->assertSee('Latency data table')->assertSee('100%');

    $mark === null ? $component->assertDontSeeHtml('data-latency-plot') : $component->assertSeeHtml($mark);
})->with([
    'single zero' => [[0], '1 of 1 recorded checks reported latency.', 'd="M 360 152 h 0"'],
    'constant coincident samples' => [[42, 42], 'Measured range 42–42 ms.', 'd="M 360 51.2 h 0"'],
    'missing only' => [[null], 'No latency measurements in this range.', null],
]);

test('detail escapes monitor and incident text and uses recorded and ongoing durations', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->down()->create(['name' => '<script>monitor text</script>']);
    $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($now);
    $monitor->incidents()->create([
        'started_at' => $now->subMinutes(5), 'status' => IncidentStatus::OPEN,
        'open_monitor_id' => $monitor->id, 'root_cause' => '<script>incident text</script>',
    ]);
    $monitor->incidents()->create([
        'started_at' => $now->subHour(), 'resolved_at' => $now->subMinutes(30), 'status' => IncidentStatus::RESOLVED,
        'duration_seconds' => 75,
    ]);
    $monitor->checkResults()->create([
        'checked_at' => $now, 'status' => CheckResultStatus::FAILURE, 'error_message' => 'private-driver-detail',
    ]);

    $this->get(route('monitors.show', $monitor))->assertOk()
        ->assertSee($monitor->name)->assertDontSee($monitor->name, false)
        ->assertSee('<script>incident text</script>')->assertDontSee('<script>incident text</script>', false)
        ->assertSeeInOrder(['Open', 'Still open', 'Ongoing duration', '5m', 'Resolved', 'Cause not recorded', '11:30:00', 'Recorded duration', '1m 15s'])
        ->assertDontSee('private-driver-detail');
});

test('history paginates independently, rejects reuse across scopes and resets both pages on refresh', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $other = Monitor::factory()->for($owner)->create();
    $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($now);
    for ($i = 0; $i < 27; $i++) {
        $monitor->checkResults()->create(['checked_at' => $now, 'status' => CheckResultStatus::SUCCESS, 'latency_ms' => $i]);
        $monitor->incidents()->create([
            'started_at' => $now, 'status' => IncidentStatus::RESOLVED, 'resolved_at' => $now, 'duration_seconds' => $i,
            'root_cause' => "Resolved record $i",
        ]);
    }
    $monitor->incidents()->create(['started_at' => $now, 'status' => IncidentStatus::OPEN, 'open_monitor_id' => $monitor->id]);

    $component = Livewire::test(MonitorDetail::class, ['monitor' => $monitor]);
    $check = monitorDetailActionToken($component->html(), 'showChecks');
    $incident = monitorDetailActionToken($component->html(), 'showIncidents');
    $rawCheck = explode('.', $check)[0];
    $component->call('showChecks', $check)->assertSet('checkCursor', $rawCheck)->assertSet('incidentCursor', '')
        ->assertSee('Previous check page')->assertSee('Next incident page')
        ->call('showIncidents', $incident)->assertSet('incidentCursor', explode('.', $incident)[0])->assertSet('checkCursor', $rawCheck)
        ->assertSee('Resolved record 0')
        ->call('filterIncidents', 'open')->assertSet('incidentCursor', '')->assertSet('checkCursor', $rawCheck)
        ->assertSet('incidentFilter', 'open')->assertDontSee('Resolved record 0')
        ->call('showIncidents', $incident)->assertSet('incidentCursor', '')
        ->call('showIncidents', $check)->assertSet('incidentCursor', '')
        ->call('filterIncidents', ['resolved'])->assertSet('incidentFilter', 'open')
        ->assertSet('asOf', $now->toDateTimeString());

    Livewire::test(MonitorDetail::class, ['monitor' => $other])
        ->call('showChecks', $check)->assertSet('checkCursor', '')->assertSee('No recorded checks yet.');
    $this->travel(1)->minutes();
    $component->call('refreshSnapshot')->assertSet('checkCursor', '')->assertSet('incidentCursor', '')
        ->assertSet('incidentFilter', 'open')->assertSet('asOf', $now->addMinute()->toDateTimeString())
        ->call('showChecks', $check)->assertSet('checkCursor', '');
});

test('each malformed detail cursor yields a safe first page', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($now);
    $time = $now->toDateTimeString();
    $scope = "$monitor->id|$time|checks|all";
    $sign = fn (string $raw): string => $raw.'.'.hash_hmac('sha256', "sitepulse:monitor-detail|$scope|$raw", app('encrypter')->getKey());
    $cursor = new Cursor(['checked_at' => $time, 'id' => 1]);

    $component = Livewire::test(MonitorDetail::class, ['monitor' => $monitor]);
    foreach ([
        ['cursor'], null, 123, $sign('ew'), $sign('MQ'), $sign($cursor->encode().'%'),
        $sign(str_repeat('x', 193)), $cursor->encode().'.'.str_repeat('0', 64),
        $sign((new Cursor(['checked_at' => $time, 'id' => 1, 'extra' => 'unexpected']))->encode()),
        $sign((new Cursor(['checked_at' => '2026-02-30 00:00:00', 'id' => 1]))->encode()),
        $sign((new Cursor(['checked_at' => $now->addSecond()->toDateTimeString(), 'id' => 1]))->encode()),
        $sign((new Cursor(['checked_at' => $time, 'id' => -1]))->encode()),
        $sign((new Cursor(['checked_at' => $time, 'id' => '1']))->encode()),
        $sign((new Cursor(['checked_at' => $time, 'id' => 1], 'true'))->encode()),
        $sign(rtrim(base64_encode(json_encode(['checked_at' => $time, 'id' => 1])), '=')),
    ] as $invalid) {
        $component->call('showChecks', $sign($cursor->encode()))->assertSet('checkCursor', $cursor->encode())
            ->call('showChecks', $invalid)->assertStatus(200)->assertSet('checkCursor', '');
    }
});

test('detail reads have bounded statements and payloads, private caching and zero outbound probes', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create(['check_interval_minutes' => 1]);
    $now = CarbonImmutable::parse('2026-11-28 12:00:00', 'UTC');
    $this->actingAs($owner)->travelTo($now);
    $this->mock(DnsResolverInterface::class)->shouldNotReceive('resolve');
    $this->mock(HttpTransportInterface::class)->shouldNotReceive('send');
    $this->mock(MultiTransportDriverInterface::class)->shouldNotReceive('run');
    foreach (range(1, 65) as $index) {
        $monitor->checkResults()->create(['checked_at' => $now->subSeconds($index), 'status' => CheckResultStatus::SUCCESS, 'latency_ms' => $index]);
    }
    foreach (range(1, 27) as $index) {
        $monitor->incidents()->create(['started_at' => $now->subDays($index), 'status' => IncidentStatus::RESOLVED]);
    }
    DB::enableQueryLog();
    $initial = $this->get(route('monitors.show', $monitor))->assertOk();
    $initialLog = DB::getQueryLog();
    DB::flushQueryLog();
    $snapshot = monitorDetailSnapshot($initial->getContent());
    $update = $this->postJson(route('default-livewire.update'), monitorDetailPayload($snapshot), ['X-Livewire' => 'true'])
        ->assertOk()->assertJsonStructure(['components' => [['snapshot', 'effects' => ['html']]]]);
    $updateLog = DB::getQueryLog();
    DB::disableQueryLog();

    foreach ([$initial, $update] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    }
    foreach ([$initialLog, $updateLog] as $log) {
        $sql = implode("\n", array_column($log, 'query'));
        expect($log)->toHaveCount(9)
            ->and(substr_count($sql, 'samples_30d'))->toBe(1)
            ->and(substr_count($sql, 'limit 26'))->toBe(2)
            ->and(substr_count($sql, 'limit 60'))->toBe(1)
            ->and($sql)->not->toContain('offset', 'error_message');
    }
    $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data'];
    expect($data)->toBe(['monitor' => [null, ['class' => Monitor::class, 'key' => $monitor->id, 's' => 'mdl']], 'asOf' => $now->toDateTimeString(), 'checkCursor' => '', 'incidentCursor' => '', 'incidentFilter' => 'all'])
        ->and(strlen($snapshot))->toBeLessThan(1024)
        ->and(strlen($update->getContent()))->toBeLessThan(190000);
    $initial->assertSee('Every 1 minute')->assertSee(route('monitors.edit', $monitor), false)->assertSee(route('monitors.pause', $monitor), false)
        ->assertSee(route('monitors.destroy', $monitor), false);
    $monitor->pause();
    $this->get(route('monitors.show', $monitor))->assertOk()->assertSee(route('monitors.resume', $monitor), false);
});

test('detail shows a generic recoverable error without exposing database details', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();
    $failed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
        if (! $failed && str_contains($sql, 'samples_30d')) {
            $failed = true;
            throw new QueryException('test', $sql, [], new RuntimeException('sensitive-connection-canary'));
        }
    });
    Livewire::actingAs($owner)->test(MonitorDetail::class, ['monitor' => $monitor])
        ->assertSee('Monitor history is temporarily unavailable.')->assertDontSee('sensitive-connection-canary')
        ->call('refreshSnapshot')->assertSee('Observed-check uptime')->assertDontSee('Monitor history is temporarily unavailable.');
});
