<?php

use App\Enums\CheckResultStatus;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$directory = getenv('SITEPULSE_BROWSER_DIRECTORY');
if ($directory === false || ! is_file($directory.'/browser-fixture')) {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory.'/storage');

if (PHP_SAPI === 'cli-server') {
    $asset = realpath($root.'/public'.(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''));
    if ($asset !== false && str_starts_with($asset, $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR) && is_file($asset)) {
        return false;
    }

    if (isset($_GET['fixture-error'])) {
        $app->booted(static function (): void {
            DB::connection()->beforeExecuting(static function (string $query): void {
                if (str_contains($query, 'check_results')) {
                    throw new QueryException('sqlite', '', [], new RuntimeException('Synthetic browser fixture query failure.'));
                }
            });
        });
    }

    $app->handleRequest(Request::capture());

    return;
}

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if ($argv[1] === 'state') {
    echo Monitor::query()->orderBy('id')->get(['id', 'name', 'status'])->toJson();

    return;
}

if ($argv[1] === 'resolve') {
    $monitor = Monitor::findOrFail(1);
    $monitor->resolveOpenIncidentSilently(now()->addSeconds(2));

    return;
}

if ($argv[1] !== 'setup' || $kernel->call('migrate', ['--force' => true]) !== 0) {
    exit(1);
}

$owner = User::factory()->owner()->create(['email' => 'owner@example.test', 'password' => getenv('BROWSER_PASSWORD')]);
$outsider = User::factory()->create(['email' => 'outsider@example.test', 'password' => getenv('BROWSER_PASSWORD')]);
if (($argv[2] ?? '') === 'empty') {
    return;
}

$primary = Monitor::factory()->operational()->for($owner)->create([
    'name' => 'Payments '.str_repeat('longname', 11),
    'url' => 'https://payments.example.test/'.str_repeat('longpath', 25),
]);
Monitor::factory()->for($owner)->create(['name' => 'Empty monitor']);
foreach (['pendingDown', 'down', 'pendingRecovery', 'paused'] as $status) {
    Monitor::factory()->{$status}()->for($owner)->create(['name' => $status]);
}
Monitor::factory()->for($outsider)->create(['name' => 'Foreign monitor']);

for ($number = 1; $number <= 12; $number++) {
    Monitor::factory()->for($owner)->create(['name' => 'Additional monitor '.$number]);
}

for ($index = 0; $index < 65; $index++) {
    $success = $index % 5 !== 4;
    CheckResult::create([
        'monitor_id' => $primary->id,
        'status' => $success ? CheckResultStatus::SUCCESS : CheckResultStatus::FAILURE,
        'status_code' => $success ? 200 : 503,
        'latency_ms' => match ($index) {
            0 => 0,
            1 => null,
            2 => 1000000,
            default => 120 + $index,
        },
        'failure_reason' => $success ? null : 'unexpected_status',
        'checked_at' => now()->subMinutes($index + 1),
    ]);
}

for ($index = 0; $index < 29; $index++) {
    $startedAt = now()->subHours($index + 1);
    Incident::create([
        'monitor_id' => $primary->id,
        'open_monitor_id' => $index === 0 ? $primary->id : null,
        'status' => $index === 0 ? 'open' : 'resolved',
        'started_at' => $startedAt,
        'resolved_at' => $index === 0 ? null : $startedAt->copy()->addMinutes(5),
        'duration_seconds' => $index === 0 ? null : 300,
        'root_cause' => str_repeat('synthetic-cause-', 9),
    ]);
}
