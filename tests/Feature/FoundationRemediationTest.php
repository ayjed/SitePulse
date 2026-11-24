<?php

use App\Models\User;
use App\Support\EnvironmentSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function writeArtisanKeyStub(string $directory): void
{
    $artisanStub = <<<'PHP'
<?php
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'artisan_calls.log';
file_put_contents($logFile, implode(' ', array_slice($argv, 1)) . PHP_EOL, FILE_APPEND);
$envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
$contents = file_get_contents($envPath);
$newKey = 'base64:' . base64_encode(random_bytes(32));
$updated = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $newKey, $contents);
file_put_contents($envPath, $updated);
PHP;

    File::put($directory.DIRECTORY_SEPARATOR.'artisan', $artisanStub);
}

test('database seeder does not create any default or predictable user accounts', function () {
    $this->seed();

    expect(User::count())->toBe(0);
});

test('user factory remains usable for automated tests without a predictable password', function () {
    $user = User::factory()->create();

    expect($user->exists)->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeFalse();

    $explicitUser = User::factory()->create([
        'password' => 'custom-test-secret',
    ]);

    expect(Hash::check('custom-test-secret', $explicitUser->password))->toBeTrue();
});

test('environment setup generates APP_KEY once and preserves it across retries', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_setup_test_'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($tempDir);

    try {
        File::put($tempDir.DIRECTORY_SEPARATOR.'.env.example', implode("\n", [
            'APP_NAME=SitePulse',
            'APP_ENV=local',
            'APP_KEY=',
            'DB_CONNECTION=sqlite',
            '',
        ]));
        writeArtisanKeyStub($tempDir);

        // First setup run creates .env, sets APP_KEY, and creates database/database.sqlite.
        expect(EnvironmentSetup::prepare($tempDir))->toBe(0);

        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $generatedKey = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');
        $firstEnvSnapshot = File::get($envPath);
        $callsAfterFirst = file($tempDir.DIRECTORY_SEPARATOR.'artisan_calls.log', FILE_IGNORE_NEW_LINES);

        expect($generatedKey)->not->toBeNull()
            ->and($generatedKey)->toStartWith('base64:')
            ->and(File::exists($tempDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite'))->toBeTrue()
            ->and($callsAfterFirst)->toHaveCount(1)
            ->and($callsAfterFirst[0])->toContain('key:generate --force');

        // Retrying setup must preserve the exact .env and never re-invoke key:generate.
        expect(EnvironmentSetup::prepare($tempDir))->toBe(0);
        expect(EnvironmentSetup::prepare($tempDir))->toBe(0);

        expect(File::get($envPath))->toBe($firstEnvSnapshot)
            ->and(EnvironmentSetup::readEnvValue($envPath, 'APP_KEY'))->toBe($generatedKey)
            ->and(file($tempDir.DIRECTORY_SEPARATOR.'artisan_calls.log', FILE_IGNORE_NEW_LINES))->toHaveCount(1);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('environment setup handles missing or empty quoted APP_KEY and skips sqlite file for mysql', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_setup_edge_'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($tempDir);

    try {
        File::put($tempDir.DIRECTORY_SEPARATOR.'.env', implode("\n", [
            'APP_NAME=SitePulse',
            'APP_KEY=""',
            'DB_CONNECTION=mysql',
            '',
        ]));
        writeArtisanKeyStub($tempDir);

        expect(EnvironmentSetup::hasAppKey($tempDir.DIRECTORY_SEPARATOR.'.env'))->toBeFalse();
        expect(EnvironmentSetup::prepare($tempDir))->toBe(0);

        $firstKey = EnvironmentSetup::readEnvValue($tempDir.DIRECTORY_SEPARATOR.'.env', 'APP_KEY');
        expect($firstKey)->toStartWith('base64:')
            ->and(File::exists($tempDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite'))->toBeFalse()
            ->and(file($tempDir.DIRECTORY_SEPARATOR.'artisan_calls.log', FILE_IGNORE_NEW_LINES))->toHaveCount(1);

        // Existing non-empty APP_KEY must be preserved on subsequent runs.
        expect(EnvironmentSetup::prepare($tempDir))->toBe(0);
        expect(EnvironmentSetup::readEnvValue($tempDir.DIRECTORY_SEPARATOR.'.env', 'APP_KEY'))->toBe($firstKey)
            ->and(file($tempDir.DIRECTORY_SEPARATOR.'artisan_calls.log', FILE_IGNORE_NEW_LINES))->toHaveCount(1);

        // Missing APP_KEY line (with only a commented placeholder) must append and populate once.
        File::put($tempDir.DIRECTORY_SEPARATOR.'.env', implode("\n", [
            'APP_NAME=SitePulse',
            '# APP_KEY=commented_only',
            'DB_CONNECTION=mysql',
            '',
        ]));
        expect(EnvironmentSetup::hasAppKey($tempDir.DIRECTORY_SEPARATOR.'.env'))->toBeFalse();
        expect(EnvironmentSetup::prepare($tempDir))->toBe(0);

        $appendedKey = EnvironmentSetup::readEnvValue($tempDir.DIRECTORY_SEPARATOR.'.env', 'APP_KEY');
        expect($appendedKey)->toStartWith('base64:')
            ->and(file($tempDir.DIRECTORY_SEPARATOR.'artisan_calls.log', FILE_IGNORE_NEW_LINES))->toHaveCount(2);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('composer setup scripts guard APP_KEY regeneration and prefer npm ci', function () {
    $composer = json_decode(File::get(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    $setupScript = implode("\n", $composer['scripts']['setup'] ?? []);
    $postCreateScript = implode("\n", $composer['scripts']['post-create-project-cmd'] ?? []);

    expect($setupScript)->toContain('EnvironmentSetup::prepare')
        ->and($setupScript)->toContain('npm ci')
        ->and($setupScript)->not->toContain('npm install')
        ->and($setupScript)->not->toContain('artisan key:generate')
        ->and($postCreateScript)->toContain('EnvironmentSetup::prepare')
        ->and($postCreateScript)->not->toContain('artisan key:generate');
});

test('sqlite connection defaults enforce WAL mode and 5000ms busy timeout on file databases', function () {
    expect(config('database.connections.sqlite.journal_mode'))->toBe('wal')
        ->and((int) config('database.connections.sqlite.busy_timeout'))->toBe(5000)
        ->and(config('database.connections.sqlite.synchronous'))->toBe('normal');

    $tempDb = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_wal_test_'.bin2hex(random_bytes(8)).'.sqlite';
    touch($tempDb);

    try {
        config([
            'database.connections.sqlite_wal_probe' => array_merge(
                config('database.connections.sqlite'),
                ['database' => $tempDb]
            ),
        ]);

        DB::purge('sqlite_wal_probe');
        $connection = DB::connection('sqlite_wal_probe');

        $journalMode = strtolower((string) $connection->scalar('PRAGMA journal_mode;'));
        $busyTimeout = (int) $connection->scalar('PRAGMA busy_timeout;');

        expect($journalMode)->toBe('wal')
            ->and($busyTimeout)->toBe(5000);
    } finally {
        DB::disconnect('sqlite_wal_probe');
        @unlink($tempDb);
        @unlink($tempDb.'-wal');
        @unlink($tempDb.'-shm');
    }
});
