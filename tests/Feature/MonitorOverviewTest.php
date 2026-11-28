<?php

use App\Enums\MonitorStatus;
use App\Livewire\MonitorOverview;
use App\Models\Monitor;
use App\Models\User;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\Contracts\MultiTransportDriverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the dashboard redirects guests and rejects authenticated non-owners', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertForbidden();
    Livewire::test(MonitorOverview::class)->assertForbidden();
});

test('the owner sees an empty dashboard with existing monitor navigation', function () {
    $owner = User::factory()->owner()->create();
    $othersMonitor = Monitor::factory()->for(User::factory()->create())->create();

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No monitors configured yet')
        ->assertSee(route('monitors.index'), false)
        ->assertSee(route('monitors.create'), false)
        ->assertDontSee($othersMonitor->name)
        ->assertDontSee('Owner Session Active');
});

test('the dashboard displays each stored status with its approved label and only recorded telemetry', function (
    MonitorStatus $status, string $label
) {
    $owner = User::factory()->owner()->create();
    $unchecked = $status === MonitorStatus::UNKNOWN;
    $monitor = Monitor::factory()->for($owner)->create([
        'name' => '<script>owner-monitor</script>',
        'status' => $status,
        'is_active' => $status !== MonitorStatus::PAUSED,
        'last_checked_at' => $unchecked ? null : '2026-11-28 10:00:00',
        'last_latency_ms' => $unchecked ? null : 120,
    ]);

    $response = $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee($label)
        ->assertSee($monitor->name)
        ->assertDontSee($monitor->name, false)
        ->assertSee(route('monitors.show', $monitor), false);

    if ($unchecked) {
        $response->assertSee('No check recorded')->assertSee('No data')->assertDontSee('120 ms');
    } else {
        $response->assertSee('2026-11-28 10:00:00')->assertSee('120 ms');
    }

    expect($monitor->fresh()->status)->toBe($status);
})->with([
    'unchecked' => [MonitorStatus::UNKNOWN, 'Not checked'],
    'healthy' => [MonitorStatus::OPERATIONAL, 'Operational'],
    'degraded' => [MonitorStatus::PENDING_DOWN, 'Degraded'],
    'down' => [MonitorStatus::DOWN, 'Down'],
    'recovering' => [MonitorStatus::PENDING_RECOVERY, 'Down · recovering'],
    'paused' => [MonitorStatus::PAUSED, 'Paused'],
]);

test('overview keyset pages are bounded and stable and forged cursors cannot escape owner scope', function () {
    $owner = User::factory()->owner()->create();
    $foreign = Monitor::factory()->for(User::factory()->create())->create(['name' => 'Foreign monitor']);
    $monitors = collect(range(1, 17))->map(fn (int $i) => Monitor::factory()->for($owner)->create([
        'name' => sprintf('Overview item %02d', $i),
    ]));
    $queries = [];
    DB::listen(function ($event) use (&$queries): void {
        if (preg_match('/\bfrom\s+[`"]?monitors\b/i', $event->sql)) {
            $queries[] = $event->sql;
        }
    });

    $component = Livewire::actingAs($owner)->test(MonitorOverview::class)
        ->assertSeeInOrder($monitors->take(15)->pluck('name')->all())
        ->assertDontSee('Overview item 16')
        ->assertDontSee($foreign->name);

    $component->call('showPage', (new Cursor(['id' => $monitors[14]->id]))->encode())
        ->assertSeeInOrder(['Overview item 16', 'Overview item 17'])
        ->assertDontSee('Overview item 15')
        ->assertDontSee($foreign->name);

    $component->call('showPage', (new Cursor(['id' => $monitors[15]->id], false))->encode())
        ->assertSeeInOrder($monitors->take(15)->pluck('name')->all())
        ->assertDontSee('Overview item 16');

    $component->call('showPage', (new Cursor(['id' => $foreign->id]))->encode())
        ->assertDontSee($foreign->name)
        ->call('showPage', (new Cursor(['unexpected' => 1]))->encode())
        ->assertSee('Overview item 01');

    expect($queries)->toHaveCount(5);
    foreach ($queries as $sql) {
        expect(strtolower($sql))->toContain('limit 16', 'user_id')->not->toContain('offset');
    }
});

test('malformed pagination inputs safely fall back to the owner first page', function (string $case) {
    $owner = User::factory()->owner()->create();
    $foreign = Monitor::factory()->for(User::factory()->create())->create(['name' => 'Foreign private monitor']);
    $first = Monitor::factory()->for($owner)->create(['name' => 'First owned monitor']);
    Monitor::factory()->for($owner)->create(['name' => 'Second owned monitor']);

    $validCursor = (new Cursor(['id' => $first->id]))->encode();
    $cursor = match ($case) {
        'invalid encoding' => $validCursor.'%',
        'malformed JSON' => 'ew',
        'JSON scalar' => 'MQ',
        'missing direction' => rtrim(base64_encode(json_encode(['id' => $first->id])), '='),
        'wrong direction type' => (new Cursor(['id' => $first->id], 'true'))->encode(),
        'wrong ID type' => (new Cursor(['id' => (string) $first->id]))->encode(),
        'unexpected field' => (new Cursor(['id' => $first->id, 'extra' => 'unexpected']))->encode(),
        'oversized token' => (new Cursor(['id' => $first->id, 'extra' => str_repeat('x', 130)]))->encode(),
        'array argument' => ['id' => $first->id],
        'null argument' => null,
    };

    Livewire::actingAs($owner)->test(MonitorOverview::class)
        ->call('showPage', $validCursor)
        ->assertDontSee($first->name)
        ->call('showPage', $cursor)
        ->assertStatus(200)
        ->assertSet('cursor', '')
        ->assertSee($first->name)
        ->assertDontSee($foreign->name);
})->with([
    'invalid encoding',
    'malformed JSON',
    'JSON scalar',
    'missing direction',
    'wrong direction type',
    'wrong ID type',
    'unexpected field',
    'oversized token',
    'array argument',
    'null argument',
]);

test('dashboard responses and authorized Livewire updates are private and perform no outbound work', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->operational()->create(['name' => 'Persisted overview monitor']);
    $this->mock(DnsResolverInterface::class)->shouldNotReceive('resolve');
    $this->mock(HttpTransportInterface::class)->shouldNotReceive('send');
    $this->mock(MultiTransportDriverInterface::class)->shouldNotReceive('run');

    $dashboard = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    expect(preg_match('/wire:snapshot="([^"]+)"/', $dashboard->getContent(), $matches))->toBe(1);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    expect(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data'])->toBe(['cursor' => '']);

    $payload = ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]];

    $monitor->forceFill(['status' => MonitorStatus::DOWN])->save();

    $updated = $this->postJson(route('default-livewire.update'), $payload, ['X-Livewire' => 'true'])
        ->assertOk()
        ->assertJsonStructure(['components' => [['snapshot', 'effects' => ['html']]]]);

    expect($updated->json('components.0.effects.html'))->toContain($monitor->name, 'Down')->not->toContain('Operational');
    foreach ([$dashboard, $updated] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    }

    $this->actingAs(User::factory()->create())
        ->postJson(route('default-livewire.update'), $payload, ['X-Livewire' => 'true'])
        ->assertForbidden()
        ->assertDontSee($monitor->name);
});

test('a transient read error is announced without leaking details and can be retried', function () {
    $owner = User::factory()->owner()->create();
    Monitor::factory()->for($owner)->create(['name' => 'Monitor after recovery']);
    $fail = true;
    DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
        if ($fail && preg_match('/\bfrom\s+[`"]?monitors\b/i', $sql)) {
            $fail = false;

            throw new QueryException('test', $sql, [], new RuntimeException('private driver information'));
        }
    });

    Livewire::actingAs($owner)->test(MonitorOverview::class)
        ->assertSee('Monitor status is temporarily unavailable')
        ->assertDontSee('private driver information')
        ->call('$refresh')
        ->assertSee('Monitor after recovery')
        ->assertDontSee('Monitor status is temporarily unavailable');
});
