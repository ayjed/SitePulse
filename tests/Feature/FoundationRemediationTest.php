<?php

use App\Models\User;
use App\Support\EnvironmentSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function createDisposableLaravelWorkspace(string $workspacePath): void
{
    File::ensureDirectoryExists($workspacePath);
    File::copy(base_path('composer.json'), $workspacePath.DIRECTORY_SEPARATOR.'composer.json');
    File::copy(base_path('.env.example'), $workspacePath.DIRECTORY_SEPARATOR.'.env.example');

    $autoloadLiteral = var_export(base_path('vendor/autoload.php'), true);
    $artisanContents = str_replace(
        "__DIR__.'/vendor/autoload.php'",
        $autoloadLiteral,
        File::get(base_path('artisan'))
    );
    File::put($workspacePath.DIRECTORY_SEPARATOR.'artisan', $artisanContents);

    foreach (['bootstrap', 'config', 'routes'] as $directory) {
        File::copyDirectory(base_path($directory), $workspacePath.DIRECTORY_SEPARATOR.$directory);
    }

    foreach ([
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
    ] as $storageDir) {
        File::ensureDirectoryExists($workspacePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $storageDir));
    }
}

function runPrepareCapturingOutput(string $workspacePath): array
{
    ob_start();
    try {
        $exitCode = EnvironmentSetup::prepare($workspacePath);
    } finally {
        $stdout = (string) ob_get_clean();
    }

    return [$exitCode, $stdout];
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

test('environment setup generates a genuine usable APP_KEY once and preserves it across retries', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_real_setup_'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        [$firstExit, $firstOutput] = runPrepareCapturingOutput($tempDir);

        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $generatedKey = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');
        $firstEnvSnapshot = File::get($envPath);

        expect($firstExit)->toBe(0)
            ->and($generatedKey)->not->toBeNull()
            ->and($generatedKey)->toStartWith('base64:')
            ->and($firstOutput)->not->toContain($generatedKey)
            ->and(EnvironmentSetup::isValidAppKey((string) $generatedKey))->toBeTrue()
            ->and(EnvironmentSetup::verifyApplicationEncryption($tempDir))->toBe(0)
            ->and(File::exists($tempDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite'))->toBeTrue();

        [$secondExit, $secondOutput] = runPrepareCapturingOutput($tempDir);
        [$thirdExit, $thirdOutput] = runPrepareCapturingOutput($tempDir);

        expect($secondExit)->toBe(0)
            ->and($thirdExit)->toBe(0)
            ->and($secondOutput)->toContain('preserving current key')
            ->and($thirdOutput)->toContain('preserving current key')
            ->and($secondOutput)->not->toContain($generatedKey)
            ->and($thirdOutput)->not->toContain($generatedKey)
            ->and(File::get($envPath))->toBe($firstEnvSnapshot);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('environment setup handles malformed empty APP_KEY variants with genuine Artisan key generation', function (string $envTemplate) {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_empty_variants_'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        File::put($envPath, $envTemplate);

        expect(EnvironmentSetup::hasAppKey($envPath))->toBeFalse();

        [$exitCode, $output] = runPrepareCapturingOutput($tempDir);
        $generatedKey = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');
        $snapshotAfterFirst = File::get($envPath);

        expect($exitCode)->toBe(0)
            ->and($generatedKey)->not->toBeNull()
            ->and($generatedKey)->toStartWith('base64:')
            ->and($generatedKey)->not->toEndWith('"')
            ->and($generatedKey)->not->toEndWith("'")
            ->and($output)->not->toContain($generatedKey)
            ->and(EnvironmentSetup::isValidAppKey((string) $generatedKey))->toBeTrue()
            ->and(EnvironmentSetup::verifyApplicationEncryption($tempDir))->toBe(0);

        [$retryExit] = runPrepareCapturingOutput($tempDir);

        expect($retryExit)->toBe(0)
            ->and(File::get($envPath))->toBe($snapshotAfterFirst);
    } finally {
        File::deleteDirectory($tempDir);
    }
})->with([
    'quoted empty double' => "APP_NAME=SitePulse\nAPP_KEY=\"\"\nDB_CONNECTION=mysql\n",
    'quoted empty single' => "APP_NAME=SitePulse\nAPP_KEY=''\nDB_CONNECTION=mysql\n",
    'exported empty' => "APP_NAME=SitePulse\nexport APP_KEY=\nDB_CONNECTION=mysql\n",
    'spaced equals empty' => "APP_NAME=SitePulse\nAPP_KEY =\nDB_CONNECTION=mysql\n",
    'commented only' => "APP_NAME=SitePulse\n# APP_KEY=commented\nDB_CONNECTION=mysql\n",
    'missing key line' => "APP_NAME=SitePulse\nDB_CONNECTION=mysql\n",
]);

test('environment setup returns nonzero exit code for invalid keys or malformed dotenv files', function (string $malformedEnv) {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_invalid_env_'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        File::put($envPath, $malformedEnv);

        [$exitCode, $output] = runPrepareCapturingOutput($tempDir);

        expect($exitCode)->not->toBe(0)
            ->and($output)->not->toContain('short-invalid-key')
            ->and(File::get($envPath))->toBe($malformedEnv);
    } finally {
        File::deleteDirectory($tempDir);
    }
})->with([
    'unclosed quote syntax' => "APP_NAME=SitePulse\nAPP_KEY=\"unterminated\n",
    'short non-empty key' => "APP_NAME=SitePulse\nAPP_KEY=short-invalid-key\nDB_CONNECTION=mysql\n",
    'corrupted base64 key' => "APP_NAME=SitePulse\nAPP_KEY=base64:!!!invalid!!!\nDB_CONNECTION=mysql\n",
    'wrong byte length base64' => "APP_NAME=SitePulse\nAPP_KEY=base64:".base64_encode('too-short')."\nDB_CONNECTION=mysql\n",
]);

test('environment setup succeeds in project paths containing spaces, exclamation marks, and normal names', function (string $dirSuffix) {
    $rootTemp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_path_suite_'.bin2hex(random_bytes(8));
    $workspacePath = $rootTemp.DIRECTORY_SEPARATOR.$dirSuffix;
    createDisposableLaravelWorkspace($workspacePath);

    try {
        [$exitCode] = runPrepareCapturingOutput($workspacePath);
        $envPath = $workspacePath.DIRECTORY_SEPARATOR.'.env';
        $key = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');

        expect($exitCode)->toBe(0)
            ->and($key)->toStartWith('base64:')
            ->and(EnvironmentSetup::verifyApplicationEncryption($workspacePath))->toBe(0);

        [$retryExit] = runPrepareCapturingOutput($workspacePath);
        expect($retryExit)->toBe(0)
            ->and(EnvironmentSetup::readEnvValue($envPath, 'APP_KEY'))->toBe($key);
    } finally {
        File::deleteDirectory($rootTemp);
    }
})->with([
    'ordinary directory' => 'SitePulseApp',
    'directory with spaces' => 'SitePulse Project Dir',
    'directory with exclamation mark' => 'SitePulse!App!Check',
    'directory with spaces and exclamation' => 'SitePulse ! Path Check',
]);

test('environment setup fails nonzero when sqlite directory or file creation fails', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_sqlite_fail_'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        // Place a regular file where the database directory would need to be created.
        File::put($tempDir.DIRECTORY_SEPARATOR.'database', 'blocking-file');
        [$blockedDirExit] = runPrepareCapturingOutput($tempDir);
        expect($blockedDirExit)->not->toBe(0);

        // Replace blocker file with an actual directory, then point DB_DATABASE to an existing subdirectory.
        File::delete($tempDir.DIRECTORY_SEPARATOR.'database');
        File::ensureDirectoryExists($tempDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'dir_as_db');
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        File::put($envPath, File::get($envPath)."\nDB_DATABASE=database/dir_as_db\n");

        expect(fn () => EnvironmentSetup::ensureSqliteDatabase(
            $envPath,
            $tempDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite',
            $tempDir
        ))->toThrow(RuntimeException::class);

        [$dirDbExit] = runPrepareCapturingOutput($tempDir);
        expect($dirDbExit)->not->toBe(0);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('composer setup scripts guard APP_KEY regeneration, keep real PHP checks, and prefer npm ci', function () {
    $composer = json_decode(File::get(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    $setupScript = implode("\n", $composer['scripts']['setup'] ?? []);
    $postCreateScript = implode("\n", $composer['scripts']['post-create-project-cmd'] ?? []);

    expect($setupScript)->toContain('EnvironmentSetup::prepare')
        ->and($setupScript)->toContain('npm ci')
        ->and($setupScript)->not->toContain('npm install')
        ->and($setupScript)->not->toContain('artisan key:generate')
        ->and($postCreateScript)->toContain('EnvironmentSetup::prepare')
        ->and($postCreateScript)->not->toContain('artisan key:generate')
        ->and($composer['config']['platform']['php'] ?? null)->toBeNull();
});

function runPrepareSubprocess(string $workspacePath): array
{
    $script = 'require $argv[1]; exit(\App\Support\EnvironmentSetup::prepare($argv[2]));';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        [PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $workspacePath],
        $descriptors,
        $pipes,
        $workspacePath
    );

    expect(is_resource($process))->toBeTrue();

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

test('environment setup preserves multiline values containing APP_KEY lines, comments, and quoted values', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_multiline_'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $initialContents = implode("\n", [
            'APP_NAME="SitePulse Monitor"',
            'NOTES="first line',
            'APP_KEY=part-of-note',
            'third line"',
            '# APP_KEY=commented-example',
            'MAIL_PASSWORD="quoted#secret-value"',
            'DB_CONNECTION=mysql',
            '',
        ]);
        File::put($envPath, $initialContents);

        [$exitCode, $stdout, $stderr] = runPrepareSubprocess($tempDir);
        $updatedContents = File::get($envPath);
        $parsed = EnvironmentSetup::parseEnvFile($envPath);

        expect($exitCode)->toBe(0, $stderr)
            ->and($parsed['NOTES'] ?? null)->toBe("first line\nAPP_KEY=part-of-note\nthird line")
            ->and($parsed['APP_NAME'] ?? null)->toBe('SitePulse Monitor')
            ->and($parsed['MAIL_PASSWORD'] ?? null)->toBe('quoted#secret-value')
            ->and($parsed['APP_KEY'] ?? null)->toStartWith('base64:')
            ->and($updatedContents)->toContain("NOTES=\"first line\nAPP_KEY=part-of-note\nthird line\"")
            ->and($updatedContents)->toContain('# APP_KEY=commented-example')
            ->and($stdout)->not->toContain((string) $parsed['APP_KEY'])
            ->and($stderr)->toBe('')
            ->and(EnvironmentSetup::verifyApplicationEncryption($tempDir))->toBe(0);

        // Existing valid APP_KEY plus multiline APP_KEY=part-of-note must remain byte-for-byte identical on retry.
        [$retryExit, $retryStdout, $retryStderr] = runPrepareSubprocess($tempDir);

        expect($retryExit)->toBe(0, $retryStderr)
            ->and($retryStdout)->toContain('preserving current key')
            ->and(File::get($envPath))->toBe($updatedContents);

        // Also test when an empty top-level APP_KEY="" coexists alongside a multiline APP_KEY=part-of-note.
        $coexistingContents = implode("\n", [
            'NOTES="line one',
            'APP_KEY=part-of-note',
            'line three"',
            'APP_KEY=""',
            'DB_CONNECTION=mysql',
            '',
        ]);
        File::put($envPath, $coexistingContents);

        [$secondExit, , $secondStderr] = runPrepareSubprocess($tempDir);
        $secondParsed = EnvironmentSetup::parseEnvFile($envPath);

        expect($secondExit)->toBe(0, $secondStderr)
            ->and($secondParsed['NOTES'] ?? null)->toBe("line one\nAPP_KEY=part-of-note\nline three")
            ->and($secondParsed['APP_KEY'] ?? null)->toStartWith('base64:')
            ->and(EnvironmentSetup::verifyApplicationEncryption($tempDir))->toBe(0);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('environment setup failures leave original env byte-for-byte unchanged and do not leak secret canaries', function (string $malformedTemplate, string $canary, bool $removeArtisan) {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_canary_'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        File::put($envPath, $malformedTemplate);

        if ($removeArtisan) {
            File::delete($tempDir.DIRECTORY_SEPARATOR.'artisan');
        }

        [$exitCode, $stdout, $stderr] = runPrepareSubprocess($tempDir);

        expect($exitCode)->not->toBe(0)
            ->and(File::get($envPath))->toBe($malformedTemplate)
            ->and($stdout)->not->toContain($canary)
            ->and($stderr)->not->toContain($canary);

        $logFiles = File::glob($tempDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'*.log');
        foreach ($logFiles as $logFile) {
            expect(File::get($logFile))->not->toContain($canary);
        }

        // Verify no leftover temp files remain in the workspace directory.
        $tempArtifacts = File::glob($tempDir.DIRECTORY_SEPARATOR.'.env.tmp.*');
        expect($tempArtifacts)->toBe([]);
    } finally {
        File::deleteDirectory($tempDir);
    }
})->with([
    'malformed MAIL_PASSWORD unclosed quote' => [
        "APP_NAME=SitePulse\nMAIL_PASSWORD=CANARY_MAIL_SECRET_8492 \"unterminated\nAPP_KEY=\n",
        'CANARY_MAIL_SECRET_8492',
        false,
    ],
    'malformed MAIL_PASSWORD unexpected whitespace' => [
        "APP_NAME=SitePulse\nMAIL_PASSWORD=CANARY_WS_SECRET_3319 unquoted space\nAPP_KEY=\n",
        'CANARY_WS_SECRET_3319',
        false,
    ],
    'unterminated multiline before MAIL_PASSWORD' => [
        "NOTES=\"unclosed\nMAIL_PASSWORD=CANARY_MULTILINE_7741\nAPP_KEY=\n",
        'CANARY_MULTILINE_7741',
        false,
    ],
    'invalid non-empty APP_KEY with secret MAIL_PASSWORD' => [
        "APP_NAME=SitePulse\nMAIL_PASSWORD=CANARY_BADKEY_9910\nAPP_KEY=short-bad-key\nDB_CONNECTION=mysql\n",
        'CANARY_BADKEY_9910',
        false,
    ],
    'missing artisan keeps .env unchanged without leaking secret' => [
        "APP_NAME=SitePulse\nMAIL_PASSWORD=CANARY_NOARTISAN_5520\nAPP_KEY=\nDB_CONNECTION=mysql\n",
        'CANARY_NOARTISAN_5520',
        true,
    ],
]);

test('environment setup fails nonzero and keeps read-only env untouched without temp file leaks', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_readonly_'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);
    $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
    $original = "APP_NAME=SitePulse\nMAIL_PASSWORD=CANARY_READONLY_6182\nAPP_KEY=\nDB_CONNECTION=mysql\n";
    File::put($envPath, $original);
    @chmod($envPath, 0444);

    try {
        // When chmod did not make file read-only (e.g. root user in some containers), skip assertion safely.
        if (is_writable($envPath)) {
            $this->markTestSkipped('Current process can still write chmod 0444 files.');
        }

        [$exitCode, $stdout, $stderr] = runPrepareSubprocess($tempDir);

        expect($exitCode)->not->toBe(0)
            ->and(File::get($envPath))->toBe($original)
            ->and($stdout)->not->toContain('CANARY_READONLY_6182')
            ->and($stderr)->not->toContain('CANARY_READONLY_6182')
            ->and(File::glob($tempDir.DIRECTORY_SEPARATOR.'.env.tmp.*'))->toBe([]);
    } finally {
        @chmod($envPath, 0666);
        File::deleteDirectory($tempDir);
    }
});

test('sqlite defaults enforce WAL and 5000ms busy timeout and smtp timeout defaults to 5 seconds', function () {
    expect(config('database.connections.sqlite.journal_mode'))->toBe('wal')
        ->and((int) config('database.connections.sqlite.busy_timeout'))->toBe(5000)
        ->and(config('database.connections.sqlite.synchronous'))->toBe('normal')
        ->and(config('mail.mailers.smtp.timeout'))->toBe(5);

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
