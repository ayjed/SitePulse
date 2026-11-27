<?php

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use App\Policies\MonitorPolicy;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function bindNoNetworkSpies(): object
{
    $counters = (object) ['dnsCalls' => 0, 'httpCalls' => 0];

    app()->instance(DnsResolverInterface::class, new class($counters) implements DnsResolverInterface
    {
        public function __construct(private object $counters) {}

        public function resolve(string $hostname): array
        {
            $this->counters->dnsCalls++;
            throw new RuntimeException("Unexpected DNS lookup for [{$hostname}] during monitor CRUD.");
        }
    });

    app()->instance(HttpTransportInterface::class, new class($counters) implements HttpTransportInterface
    {
        public function __construct(private object $counters) {}

        public function send(TransportRequest $request): TransportResponse
        {
            $this->counters->httpCalls++;
            throw new RuntimeException("Unexpected outbound HTTP call to [{$request->url->url}] during monitor CRUD.");
        }
    });

    return $counters;
}

function validMonitorPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Production Marketing Site',
        'url' => 'https://marketing.example.test/health',
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 299,
        'is_active' => '1',
    ], $overrides);
}

test('monitors schema defines required columns, composite indexes, foreign keys, and rolls back cleanly', function () {
    expect(Schema::hasTable('monitors'))->toBeTrue();
    expect(Schema::hasColumns('monitors', [
        'id',
        'user_id',
        'name',
        'url',
        'check_interval_minutes',
        'timeout_seconds',
        'expected_status_code_min',
        'expected_status_code_max',
        'is_active',
        'status',
        'consecutive_failures',
        'consecutive_successes',
        'last_checked_at',
        'next_check_at',
        'last_status_code',
        'last_latency_ms',
        'tls_expires_at',
        'tls_last_checked_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $indexNames = collect(Schema::getIndexes('monitors'))->pluck('name')->all();
    expect($indexNames)->toContain(
        'monitors_user_active_idx',
        'monitors_sched_last_checked_idx',
        'monitors_sched_next_check_idx',
    );

    $owner = User::factory()->owner()->create();
    Monitor::factory()->for($owner)->create();
    expect(Monitor::query()->count())->toBe(1);

    // Deleting owner must cascade-delete associated monitors cleanly on both SQLite and MySQL.
    $owner->delete();
    expect(Monitor::query()->count())->toBe(0);

    // Verify migration rollback & re-apply roundtrip in FK-safe order (check_results -> monitors_default -> monitors).
    Artisan::call('migrate:rollback', ['--step' => 3, '--no-interaction' => true]);
    expect(Schema::hasTable('check_results'))->toBeFalse()
        ->and(Schema::hasTable('monitors'))->toBeFalse();

    Artisan::call('migrate', ['--no-interaction' => true]);
    expect(Schema::hasTable('monitors'))->toBeTrue()
        ->and(Schema::hasTable('check_results'))->toBeTrue();
});

test('owner can create, list, view, edit, pause, resume, and delete monitors with zero outbound network calls', function () {
    $counters = bindNoNetworkSpies();
    $owner = User::factory()->owner()->create([
        'name' => 'Site Owner',
        'email' => 'owner@example.test',
    ]);

    // Empty index + security cache headers
    $indexEmpty = $this->actingAs($owner)->get(route('monitors.index'));
    $indexEmpty->assertOk()
        ->assertSee('No monitors configured yet')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $indexEmpty->headers->get('Cache-Control'))->toContain('no-store');

    // Dashboard links cleanly to Monitor CRUD
    $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Manage Monitors')
        ->assertSee(route('monitors.index'), false);

    // Create page renders accessible form controls
    $this->actingAs($owner)->get(route('monitors.create'))
        ->assertOk()
        ->assertSee('Add Monitor')
        ->assertSee('Target URL');

    // Create monitor canonicalizes uppercase host/default port and associates owner
    $createResponse = $this->actingAs($owner)->post(route('monitors.store'), validMonitorPayload([
        'name' => '  API Endpoint <script>alert(1)</script>  ',
        'url' => '  https://API.Example.Test:443/healthz#fragment  ',
        'check_interval_minutes' => 15,
        'timeout_seconds' => 12,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 204,
    ]));

    $monitor = Monitor::query()->first();
    expect($monitor)->not->toBeNull()
        ->and($monitor->user_id)->toBe($owner->id)
        ->and($monitor->name)->toBe('API Endpoint <script>alert(1)</script>')
        ->and($monitor->url)->toBe('https://api.example.test/healthz')
        ->and($monitor->check_interval_minutes)->toBe(15)
        ->and($monitor->timeout_seconds)->toBe(12)
        ->and($monitor->expected_status_code_min)->toBe(200)
        ->and($monitor->expected_status_code_max)->toBe(204)
        ->and($monitor->is_active)->toBeTrue()
        ->and($monitor->status)->toBe(MonitorStatus::UNKNOWN)
        ->and($monitor->last_checked_at)->toBeNull();

    $createResponse->assertRedirect(route('monitors.show', $monitor))
        ->assertSessionHas('status', 'Monitor created.');

    // View monitor renders escaped name and configuration
    $showPage = $this->actingAs($owner)->get(route('monitors.show', $monitor));
    $showPage->assertOk()
        ->assertSee('https://api.example.test/healthz')
        ->assertSee('Every 15 minutes')
        ->assertSee('12 seconds')
        ->assertSee('200–204')
        ->assertSee('Never checked yet')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

    // List monitors displays created monitor
    $this->actingAs($owner)->get(route('monitors.index'))
        ->assertOk()
        ->assertSee('https://api.example.test/healthz')
        ->assertSee('Unknown');

    // Edit page + update configuration
    $this->actingAs($owner)->get(route('monitors.edit', $monitor))
        ->assertOk()
        ->assertSee('Edit Monitor');

    $updateResponse = $this->actingAs($owner)->put(route('monitors.update', $monitor), validMonitorPayload([
        'name' => 'Updated API Endpoint',
        'url' => 'http://api.example.test:8080/ready',
        'check_interval_minutes' => 30,
        'timeout_seconds' => 8,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 302,
    ]));

    $updateResponse->assertRedirect(route('monitors.show', $monitor))
        ->assertSessionHas('status', 'Monitor updated.');

    $monitor->refresh();
    expect($monitor->name)->toBe('Updated API Endpoint')
        ->and($monitor->url)->toBe('http://api.example.test:8080/ready')
        ->and($monitor->check_interval_minutes)->toBe(30)
        ->and($monitor->timeout_seconds)->toBe(8)
        ->and($monitor->expected_status_code_min)->toBe(200)
        ->and($monitor->expected_status_code_max)->toBe(302);

    // Pause and resume
    $this->actingAs($owner)->from(route('monitors.show', $monitor))
        ->post(route('monitors.pause', $monitor))
        ->assertRedirect(route('monitors.show', $monitor))
        ->assertSessionHas('status', 'Monitor paused.');

    $monitor->refresh();
    expect($monitor->is_active)->toBeFalse()
        ->and($monitor->status)->toBe(MonitorStatus::PAUSED);

    $this->actingAs($owner)->from(route('monitors.show', $monitor))
        ->post(route('monitors.resume', $monitor))
        ->assertRedirect(route('monitors.show', $monitor))
        ->assertSessionHas('status', 'Monitor resumed.');

    $monitor->refresh();
    expect($monitor->is_active)->toBeTrue()
        ->and($monitor->status)->toBe(MonitorStatus::UNKNOWN);

    // Delete
    $this->actingAs($owner)->delete(route('monitors.destroy', $monitor))
        ->assertRedirect(route('monitors.index'))
        ->assertSessionHas('status', 'Monitor deleted.');

    expect(Monitor::query()->count())->toBe(0);
    expect($counters->dnsCalls)->toBe(0);
    expect($counters->httpCalls)->toBe(0);
});

test('guests and non-owners are denied access across all monitor routes and policy checks', function () {
    $owner = User::factory()->owner()->create();
    $nonOwner = User::factory()->create(['is_owner' => false, 'owner_slot' => null]);
    $monitor = Monitor::factory()->for($owner)->create();

    // Unauthenticated guest redirects cleanly to /login on every route.
    $this->get(route('monitors.index'))->assertRedirect(route('login'));
    $this->get(route('monitors.create'))->assertRedirect(route('login'));
    $this->post(route('monitors.store'), validMonitorPayload())->assertRedirect(route('login'));
    $this->get(route('monitors.show', $monitor))->assertRedirect(route('login'));
    $this->get(route('monitors.edit', $monitor))->assertRedirect(route('login'));
    $this->put(route('monitors.update', $monitor), validMonitorPayload())->assertRedirect(route('login'));
    $this->post(route('monitors.pause', $monitor))->assertRedirect(route('login'));
    $this->post(route('monitors.resume', $monitor))->assertRedirect(route('login'));
    $this->delete(route('monitors.destroy', $monitor))->assertRedirect(route('login'));

    // Non-owner user receives 403 Forbidden on every route.
    $this->actingAs($nonOwner)->get(route('monitors.index'))->assertForbidden();
    $this->actingAs($nonOwner)->get(route('monitors.create'))->assertForbidden();
    $this->actingAs($nonOwner)->post(route('monitors.store'), validMonitorPayload())->assertForbidden();
    $this->actingAs($nonOwner)->get(route('monitors.show', $monitor))->assertForbidden();
    $this->actingAs($nonOwner)->get(route('monitors.edit', $monitor))->assertForbidden();
    $this->actingAs($nonOwner)->put(route('monitors.update', $monitor), validMonitorPayload())->assertForbidden();
    $this->actingAs($nonOwner)->post(route('monitors.pause', $monitor))->assertForbidden();
    $this->actingAs($nonOwner)->post(route('monitors.resume', $monitor))->assertForbidden();
    $this->actingAs($nonOwner)->delete(route('monitors.destroy', $monitor))->assertForbidden();

    // Policy rejects mismatched user_id even when user instance claims is_owner.
    $policy = new MonitorPolicy;
    $spoofedUser = User::factory()->make(['id' => $owner->id + 999, 'is_owner' => true]);
    expect($policy->view($spoofedUser, $monitor))->toBeFalse()
        ->and($policy->update($spoofedUser, $monitor))->toBeFalse()
        ->and($policy->delete($spoofedUser, $monitor))->toBeFalse()
        ->and($policy->pause($spoofedUser, $monitor))->toBeFalse()
        ->and($policy->resume($spoofedUser, $monitor))->toBeFalse();

    expect(Monitor::query()->count())->toBe(1);
});

test('csrf middleware protects all state-changing monitor routes against forged requests', function () {
    $owner = User::factory()->owner()->create();
    $monitor = Monitor::factory()->for($owner)->create();

    $this->app->bind(PreventRequestForgery::class, static function ($app) {
        return new class($app, $app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    });

    $this->actingAs($owner)->post(route('monitors.store'), validMonitorPayload())->assertStatus(419);
    $this->actingAs($owner)->put(route('monitors.update', $monitor), validMonitorPayload())->assertStatus(419);
    $this->actingAs($owner)->post(route('monitors.pause', $monitor))->assertStatus(419);
    $this->actingAs($owner)->post(route('monitors.resume', $monitor))->assertStatus(419);
    $this->actingAs($owner)->delete(route('monitors.destroy', $monitor))->assertStatus(419);
});

test('prevents mass assignment of owner association and internal monitoring state', function () {
    $owner = User::factory()->owner()->create();
    $otherUser = User::factory()->create(['is_owner' => false, 'owner_slot' => null]);

    // Direct Eloquent mass assignment cannot hijack user_id, is_active, status, or telemetry counters.
    $direct = new Monitor([
        'user_id' => $otherUser->id,
        'name' => 'Mass Assign Check',
        'url' => 'https://safe.example.test/',
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'expected_status_code_min' => 200,
        'expected_status_code_max' => 299,
        'is_active' => false,
        'status' => MonitorStatus::DOWN->value,
        'consecutive_failures' => 99,
        'consecutive_successes' => 88,
        'last_status_code' => 500,
        'last_latency_ms' => 1234,
        'last_checked_at' => now(),
    ]);

    expect($direct->user_id)->toBeNull()
        ->and($direct->is_active)->toBeNull()
        ->and($direct->status)->toBeNull()
        ->and($direct->consecutive_failures)->toBeNull()
        ->and($direct->last_checked_at)->toBeNull();

    // HTTP POST / PUT payloads attempting to override user_id and internal monitoring state are ignored.
    $this->actingAs($owner)->post(route('monitors.store'), validMonitorPayload([
        'user_id' => $otherUser->id,
        'status' => MonitorStatus::DOWN->value,
        'consecutive_failures' => 42,
        'consecutive_successes' => 17,
        'last_status_code' => 503,
        'last_latency_ms' => 9999,
        'last_checked_at' => '2026-01-01 00:00:00',
    ]))->assertRedirect();

    $monitor = Monitor::query()->firstOrFail();
    expect($monitor->user_id)->toBe($owner->id)
        ->and($monitor->status)->toBe(MonitorStatus::UNKNOWN)
        ->and($monitor->consecutive_failures)->toBe(0)
        ->and($monitor->consecutive_successes)->toBe(0)
        ->and($monitor->last_status_code)->toBeNull()
        ->and($monitor->last_latency_ms)->toBeNull()
        ->and($monitor->last_checked_at)->toBeNull();

    $this->actingAs($owner)->put(route('monitors.update', $monitor), validMonitorPayload([
        'user_id' => $otherUser->id,
        'status' => MonitorStatus::DOWN->value,
        'consecutive_failures' => 10,
        'last_checked_at' => '2026-01-01 00:00:00',
    ]))->assertRedirect();

    $monitor->refresh();
    expect($monitor->user_id)->toBe($owner->id)
        ->and($monitor->status)->toBe(MonitorStatus::UNKNOWN)
        ->and($monitor->consecutive_failures)->toBe(0)
        ->and($monitor->last_checked_at)->toBeNull();
});

test('rejects unsafe SSRF destination URLs during create and update without performing DNS or HTTP probes', function (string $unsafeUrl) {
    $counters = bindNoNetworkSpies();
    $owner = User::factory()->owner()->create();
    $existing = Monitor::factory()->for($owner)->create([
        'url' => 'https://safe.example.test/health',
    ]);

    $createResponse = $this->actingAs($owner)
        ->from(route('monitors.create'))
        ->post(route('monitors.store'), validMonitorPayload(['url' => $unsafeUrl]));

    $createResponse->assertRedirect(route('monitors.create'))
        ->assertSessionHasErrors('url');

    expect(Monitor::query()->count())->toBe(1);

    $updateResponse = $this->actingAs($owner)
        ->from(route('monitors.edit', $existing))
        ->put(route('monitors.update', $existing), validMonitorPayload(['url' => $unsafeUrl]));

    $updateResponse->assertRedirect(route('monitors.edit', $existing))
        ->assertSessionHasErrors('url');

    expect($existing->fresh()->url)->toBe('https://safe.example.test/health');
    expect($counters->dnsCalls)->toBe(0);
    expect($counters->httpCalls)->toBe(0);
})->with([
    'ipv4 loopback' => 'http://127.0.0.1/',
    'ipv6 loopback' => 'http://[::1]/',
    'rfc1918' => 'http://10.0.0.1/status',
    'aws metadata ip' => 'http://169.254.169.254/latest/meta-data/',
    'gcp metadata host' => 'http://metadata.google.internal/computeMetadata/v1/',
    'localhost' => 'http://localhost/health',
    'dword ip' => 'http://2130706433/',
    'hex ip' => 'http://0x7f000001/',
    'leading zero ip' => 'http://0127.0.0.1/',
    'ssh port 22' => 'http://example.com:22/',
    'redis port 6379' => 'http://example.com:6379/',
    'uppercase scheme' => 'HTTP://example.com/',
    'credentials' => 'https://alice:TopSecret123@example.com/',
    'file scheme' => 'file:///etc/passwd',
    'gopher scheme' => 'gopher://127.0.0.1:6379/',
    'backslash differential' => 'http://evil.com\\@127.0.0.1/',
]);

test('rejects invalid intervals, timeouts, status code bounds, names, array payloads, and never leaks embedded URL credentials', function () {
    $owner = User::factory()->owner()->create();

    $invalidCases = [
        'invalid interval 2' => validMonitorPayload(['check_interval_minutes' => 2]),
        'invalid interval 120' => validMonitorPayload(['check_interval_minutes' => 120]),
        'boolean json interval' => validMonitorPayload(['check_interval_minutes' => true]),
        'float interval' => validMonitorPayload(['check_interval_minutes' => '5.5']),
        'timeout too low' => validMonitorPayload(['timeout_seconds' => 1]),
        'timeout too high' => validMonitorPayload(['timeout_seconds' => 16]),
        'status min below 100' => validMonitorPayload(['expected_status_code_min' => 99]),
        'status max above 599' => validMonitorPayload(['expected_status_code_max' => 600]),
        'status min greater than max' => validMonitorPayload(['expected_status_code_min' => 300, 'expected_status_code_max' => 200]),
        'empty whitespace name' => validMonitorPayload(['name' => '   ']),
        'control char in name' => validMonitorPayload(['name' => "My\nSite"]),
    ];

    foreach ($invalidCases as $label => $payload) {
        $this->actingAs($owner)
            ->postJson(route('monitors.store'), $payload)
            ->assertUnprocessable();
    }

    // Array payloads on web form must return clean validation errors rather than HTTP 500 Blade old() crashes.
    $arraySubmission = $this->actingAs($owner)
        ->from(route('monitors.create'))
        ->followingRedirects()
        ->post(route('monitors.store'), [
            'name' => ['ArrayName'],
            'url' => ['https://safe.example.test/'],
            'check_interval_minutes' => ['5'],
            'timeout_seconds' => ['10'],
            'expected_status_code_min' => ['200'],
            'expected_status_code_max' => ['299'],
        ]);
    $arraySubmission->assertOk();

    // Submitted URL password must never appear in flashed old input, error messages, or rendered HTML.
    $credAttempt = $this->actingAs($owner)
        ->from(route('monitors.create'))
        ->followingRedirects()
        ->post(route('monitors.store'), validMonitorPayload([
            'url' => 'https://alice:MyUltraSecret999@example.com/',
        ]));
    $credAttempt->assertOk()
        ->assertDontSee('MyUltraSecret999');
    expect((string) session()->getOldInput('url'))->not->toContain('MyUltraSecret999');

    // Minimal payload defaults interval=5, timeout=10, status=200..299, active=true.
    $this->actingAs($owner)->post(route('monitors.store'), [
        'name' => 'Minimal Config',
        'url' => 'https://minimal.example.test/ping',
    ])->assertRedirect();

    $minimal = Monitor::query()->where('name', 'Minimal Config')->firstOrFail();
    expect($minimal->check_interval_minutes)->toBe(5)
        ->and($minimal->timeout_seconds)->toBe(10)
        ->and($minimal->expected_status_code_min)->toBe(200)
        ->and($minimal->expected_status_code_max)->toBe(299)
        ->and($minimal->is_active)->toBeTrue()
        ->and($minimal->status)->toBe(MonitorStatus::UNKNOWN);

    expect(Monitor::query()->count())->toBe(1);
});

test('pause and resume enforce scheduling eligibility, preserve telemetry cleanly, and never trigger probes', function () {
    $counters = bindNoNetworkSpies();
    $owner = User::factory()->owner()->create();

    $checkedAt = Carbon::parse('2026-11-27 12:00:00', 'UTC');
    $tlsExpiry = Carbon::parse('2027-01-15 00:00:00', 'UTC');

    $monitor = Monitor::factory()->for($owner)->create([
        'name' => 'Telemetry Monitor',
        'url' => 'https://telemetry.example.test/status',
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'is_active' => true,
        'status' => MonitorStatus::PENDING_DOWN,
        'consecutive_failures' => 1,
        'consecutive_successes' => 0,
        'last_checked_at' => $checkedAt,
        'next_check_at' => $checkedAt->copy()->addMinutes(5),
        'last_status_code' => 502,
        'last_latency_ms' => 420,
        'tls_expires_at' => $tlsExpiry,
        'tls_last_checked_at' => $checkedAt,
    ]);

    // Non-URL edit preserves operational state and telemetry, and updates next_check_at on interval change.
    $this->actingAs($owner)->put(route('monitors.update', $monitor), validMonitorPayload([
        'name' => 'Telemetry Monitor Renamed',
        'url' => 'https://telemetry.example.test/status',
        'check_interval_minutes' => 15,
        'timeout_seconds' => 14,
        'is_active' => '1',
    ]))->assertRedirect();

    $monitor->refresh();
    expect($monitor->status)->toBe(MonitorStatus::PENDING_DOWN)
        ->and($monitor->consecutive_failures)->toBe(1)
        ->and($monitor->last_status_code)->toBe(502)
        ->and($monitor->last_latency_ms)->toBe(420)
        ->and($monitor->next_check_at?->equalTo($checkedAt->copy()->addMinutes(15)))->toBeTrue();

    // Pause excludes monitor from eligible/due scopes while preserving historical telemetry.
    $this->actingAs($owner)->post(route('monitors.pause', $monitor))->assertRedirect();

    $monitor->refresh();
    expect($monitor->is_active)->toBeFalse()
        ->and($monitor->status)->toBe(MonitorStatus::PAUSED)
        ->and($monitor->consecutive_failures)->toBe(0)
        ->and($monitor->next_check_at)->toBeNull()
        ->and($monitor->last_checked_at?->equalTo($checkedAt))->toBeTrue()
        ->and($monitor->last_status_code)->toBe(502)
        ->and($monitor->tls_expires_at?->equalTo($tlsExpiry))->toBeTrue();

    expect(Monitor::query()->eligibleForCheck()->pluck('id')->all())->not->toContain($monitor->id);
    expect(Monitor::query()->dueForCheck($checkedAt->copy()->addHour())->pluck('id')->all())->not->toContain($monitor->id);

    // Even a corrupt active row with status=paused is excluded by eligibleForCheck().
    DB::table('monitors')->where('id', $monitor->id)->update(['is_active' => true, 'status' => MonitorStatus::PAUSED->value]);
    expect(Monitor::query()->eligibleForCheck()->pluck('id')->all())->not->toContain($monitor->id);

    // Resume restores eligibility without changing history or making network calls.
    $this->actingAs($owner)->post(route('monitors.resume', $monitor))->assertRedirect();
    $monitor->refresh();

    expect($monitor->is_active)->toBeTrue()
        ->and($monitor->status)->toBe(MonitorStatus::UNKNOWN)
        ->and($monitor->consecutive_failures)->toBe(0)
        ->and($monitor->last_checked_at?->equalTo($checkedAt))->toBeTrue()
        ->and($monitor->last_status_code)->toBe(502)
        ->and($monitor->tls_expires_at?->equalTo($tlsExpiry))->toBeTrue()
        ->and(Monitor::query()->eligibleForCheck()->pluck('id')->all())->toContain($monitor->id)
        ->and(Monitor::query()->dueForCheck()->pluck('id')->all())->toContain($monitor->id);

    // Changing URL resets stale probe & TLS state cleanly.
    $this->actingAs($owner)->put(route('monitors.update', $monitor), validMonitorPayload([
        'name' => 'New Target Monitor',
        'url' => 'https://brand-new.example.test/health',
    ]))->assertRedirect();

    $monitor->refresh();
    expect($monitor->url)->toBe('https://brand-new.example.test/health')
        ->and($monitor->last_checked_at)->toBeNull()
        ->and($monitor->last_status_code)->toBeNull()
        ->and($monitor->last_latency_ms)->toBeNull()
        ->and($monitor->tls_expires_at)->toBeNull()
        ->and($monitor->tls_last_checked_at)->toBeNull();

    // Portable scheduler priority: never-checked (NULL) first, then oldest last_checked_at, excluding future next_check_at.
    $older = Monitor::factory()->for($owner)->create([
        'last_checked_at' => Carbon::parse('2026-11-27 11:00:00', 'UTC'),
        'next_check_at' => Carbon::parse('2026-11-27 11:05:00', 'UTC'),
    ]);
    $futureNotDue = Monitor::factory()->for($owner)->create([
        'last_checked_at' => Carbon::parse('2026-11-27 12:00:00', 'UTC'),
        'next_check_at' => Carbon::parse('2026-11-27 12:30:00', 'UTC'),
    ]);

    $dueIds = Monitor::query()
        ->dueForCheck(Carbon::parse('2026-11-27 12:10:00', 'UTC'))
        ->pluck('id')
        ->all();

    expect($dueIds)->toBe([$monitor->id, $older->id])
        ->and($dueIds)->not->toContain($futureNotDue->id);

    expect($counters->dnsCalls)->toBe(0);
    expect($counters->httpCalls)->toBe(0);
});
