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
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';

        // Place a regular file where the database directory would need to be created.
        File::put($tempDir.DIRECTORY_SEPARATOR.'database', 'blocking-file');
        [$blockedDirExit] = runPrepareCapturingOutput($tempDir);
        expect($blockedDirExit)->not->toBe(0)
            ->and(is_file($envPath))->toBeFalse();

        // Replace blocker file with an actual directory, seed .env, then point DB_DATABASE to an existing subdirectory.
        File::delete($tempDir.DIRECTORY_SEPARATOR.'database');
        File::ensureDirectoryExists($tempDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'dir_as_db');
        $seededEnv = File::get($tempDir.DIRECTORY_SEPARATOR.'.env.example')."\nDB_DATABASE=database/dir_as_db\n";
        File::put($envPath, $seededEnv);

        expect(fn () => EnvironmentSetup::ensureSqliteDatabase(
            $envPath,
            $tempDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite',
            $tempDir
        ))->toThrow(RuntimeException::class);

        [$dirDbExit] = runPrepareCapturingOutput($tempDir);
        expect($dirDbExit)->not->toBe(0)
            ->and(File::get($envPath))->toBe($seededEnv);
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

test('prepare leaves empty APP_KEY env and cached config byte-for-byte unchanged when bootstrap/cache/config.php is stale', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-cached-empty-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $originalEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY=\nDB_CONNECTION=sqlite\n";
        file_put_contents($envPath, $originalEnv);

        $cleanEnv = getenv();
        if (! is_array($cleanEnv)) {
            $cleanEnv = [];
        }
        foreach (['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_ENV', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'DB_CONNECTION', 'DB_DATABASE'] as $unsetVar) {
            unset($cleanEnv[$unsetVar]);
        }

        $process = proc_open(
            [PHP_BINARY, $tempDir.DIRECTORY_SEPARATOR.'artisan', 'config:cache'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $tempDir,
            $cleanEnv
        );
        expect($process)->toBeResource();
        fclose($pipes[0]);
        $cacheStdout = stream_get_contents($pipes[1]) ?: '';
        $cacheStderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $cacheStdout.$cacheStderr);

        $cachedConfigPath = $tempDir.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        expect(is_file($cachedConfigPath))->toBeTrue();
        $originalCachedConfig = file_get_contents($cachedConfigPath);

        [$exitCode] = runPrepareSubprocess($tempDir);

        expect($exitCode)->not->toBe(0)
            ->and(file_get_contents($envPath))->toBe($originalEnv)
            ->and(file_get_contents($cachedConfigPath))->toBe($originalCachedConfig)
            ->and(glob($tempDir.DIRECTORY_SEPARATOR.'.env.tmp.*'))->toBe([])
            ->and(glob($tempDir.DIRECTORY_SEPARATOR.'.en*.tmp'))->toBe([]);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('prepare succeeds idempotently when APP_KEY is valid and bootstrap/cache/config.php is cached with that key', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-cached-valid-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $validKey = 'base64:'.base64_encode(random_bytes(32));
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $originalEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$validKey}\nDB_CONNECTION=sqlite\n";
        file_put_contents($envPath, $originalEnv);

        $cleanEnv = getenv();
        if (! is_array($cleanEnv)) {
            $cleanEnv = [];
        }
        foreach (['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_ENV', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'DB_CONNECTION', 'DB_DATABASE'] as $unsetVar) {
            unset($cleanEnv[$unsetVar]);
        }

        $process = proc_open(
            [PHP_BINARY, $tempDir.DIRECTORY_SEPARATOR.'artisan', 'config:cache'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $tempDir,
            $cleanEnv
        );
        expect($process)->toBeResource();
        fclose($pipes[0]);
        $cacheStdout = stream_get_contents($pipes[1]) ?: '';
        $cacheStderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $cacheStdout.$cacheStderr);

        $cachedConfigPath = $tempDir.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        $originalCachedConfig = file_get_contents($cachedConfigPath);

        [$exitCode, $stdout, $stderr] = runPrepareSubprocess($tempDir);

        expect($exitCode)->toBe(0, $stdout.$stderr)
            ->and(file_get_contents($envPath))->toBe($originalEnv)
            ->and(file_get_contents($cachedConfigPath))->toBe($originalCachedConfig);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

function runPrepareWithEnvSubprocess(string $workspacePath, array $envOverrides = [], array $unsetVars = [], ?string $inlineScript = null): array
{
    $script = $inlineScript ?? 'require $argv[1]; exit(\App\Support\EnvironmentSetup::prepare($argv[2]));';
    $env = getenv();
    if (! is_array($env)) {
        $env = [];
    }

    foreach ($unsetVars as $name) {
        unset($env[$name]);
    }
    foreach ($envOverrides as $name => $value) {
        $env[$name] = $value;
    }

    $process = proc_open(
        [PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $workspacePath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workspacePath,
        $env
    );

    expect(is_resource($process))->toBeTrue();

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

test('successful rollback after post-publication failure verifies restored .env bytes without leftover artifacts', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-rollback-ok-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $originalEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nMAIL_PASSWORD=CANARY_ROLLBACK_OK_9184\nAPP_KEY=\nDB_CONNECTION=mysql\n";
        File::put($envPath, $originalEnv);

        $script = <<<'PHP'
require $argv[1];
$publishedKey = null;
$exit = \App\Support\EnvironmentSetup::prepare($argv[2], function (string $base, string $targetEnv) use (&$publishedKey): int {
    $publishedKey = \App\Support\EnvironmentSetup::readEnvValue($targetEnv, 'APP_KEY');
    if (! is_string($publishedKey) || ! str_starts_with($publishedKey, 'base64:')) {
        fwrite(STDERR, "HOOK_UNEXPECTED_STATE\n");
        return 2;
    }
    return 1;
});
fwrite(STDOUT, "PUBLISHED_KEY_PRESENT=" . (is_string($publishedKey) ? 'yes' : 'no') . "\n");
exit($exit);
PHP;

        [$exitCode, $stdout, $stderr] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE'],
            $script
        );

        expect($exitCode)->not->toBe(0)
            ->and($stdout)->toContain('PUBLISHED_KEY_PRESENT=yes')
            ->and($stdout)->not->toContain('CANARY_ROLLBACK_OK_9184')
            ->and($stderr)->not->toContain('CANARY_ROLLBACK_OK_9184')
            ->and($stderr)->toContain('original .env bytes were verified restored')
            ->and(File::get($envPath))->toBe($originalEnv)
            ->and(File::glob($tempDir.DIRECTORY_SEPARATOR.'.env.tmp.*'))->toBe([])
            ->and(File::glob($tempDir.DIRECTORY_SEPARATOR.'.env.recovery.*'))->toBe([]);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('denied rollback after post-publication failure never claims restoration and preserves recovery copy', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-rollback-denied-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);
    $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
    $recoveryPaths = [];

    try {
        $originalEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nMAIL_PASSWORD=CANARY_ROLLBACK_DENIED_7310\nAPP_KEY=\nDB_CONNECTION=mysql\n";
        File::put($envPath, $originalEnv);

        $script = <<<'PHP'
require $argv[1];
$exit = \App\Support\EnvironmentSetup::prepare($argv[2], function (string $base, string $targetEnv): int {
    @chmod($targetEnv, 0444);
    clearstatcache(true, $targetEnv);
    if (is_writable($targetEnv)) {
        return 99;
    }
    return 1;
});
exit($exit);
PHP;

        [$exitCode, $stdout, $stderr] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE'],
            $script
        );

        if ($exitCode === 99 || str_contains($stderr, 'was verified restored')) {
            // Restore writability before check; root/UID=0 containers ignore chmod 0444.
            @chmod($envPath, 0666);
            $this->markTestSkipped('Current OS privilege allows writes to chmod 0444 target file.');
        }

        $recoveryPaths = File::glob($tempDir.DIRECTORY_SEPARATOR.'.env.recovery.*');
        $publishedAfter = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');

        expect($exitCode)->not->toBe(0)
            ->and($stderr)->not->toContain('original .env bytes were verified restored')
            ->and($stderr)->toContain('rollback failed')
            ->and($stderr)->toContain('modified configuration remains on disk')
            ->and($stderr)->toContain('Protected recovery copy')
            ->and($stdout)->not->toContain('CANARY_ROLLBACK_DENIED_7310')
            ->and($stderr)->not->toContain('CANARY_ROLLBACK_DENIED_7310')
            ->and($publishedAfter)->toStartWith('base64:')
            ->and($stdout)->not->toContain((string) $publishedAfter)
            ->and($stderr)->not->toContain((string) $publishedAfter)
            ->and(File::get($envPath))->not->toBe($originalEnv)
            ->and($recoveryPaths)->toHaveCount(1)
            ->and(basename($recoveryPaths[0]))->toMatch('/^\.env\.recovery\.[0-9a-f]{24}$/')
            ->and(File::get($recoveryPaths[0]))->toBe($originalEnv);
    } finally {
        @chmod($envPath, 0666);
        foreach ($recoveryPaths as $recoveryPath) {
            @chmod($recoveryPath, 0666);
        }
        File::deleteDirectory($tempDir);
    }
});

test('prepare rejects .env.testing override mismatch without modifying .env or .env.testing', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-env-testing-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $testingPath = $tempDir.DIRECTORY_SEPARATOR.'.env.testing';
        $validKeyA = 'base64:'.base64_encode(random_bytes(32));
        $validKeyB = 'base64:'.base64_encode(random_bytes(32));

        // Codex scenario: .env has APP_KEY=, .env.testing has a valid key, process has APP_ENV=testing.
        $emptyEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nMAIL_PASSWORD=CANARY_TESTING_4401\nAPP_KEY=\nDB_CONNECTION=mysql\n";
        $testingEnv = "APP_NAME=SitePulse\nAPP_ENV=testing\nAPP_KEY={$validKeyB}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $emptyEnv);
        File::put($testingPath, $testingEnv);

        [$exitEmpty, $outEmpty, $errEmpty] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'testing'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );

        expect($exitEmpty)->not->toBe(0)
            ->and(File::get($envPath))->toBe($emptyEnv)
            ->and(File::get($testingPath))->toBe($testingEnv)
            ->and($outEmpty.$errEmpty)->not->toContain('CANARY_TESTING_4401')
            ->and($outEmpty.$errEmpty)->not->toContain($validKeyB);

        // Existing valid key mismatch: .env has validKeyA while .env.testing overrides with validKeyB under APP_ENV=testing.
        $validEnvA = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$validKeyA}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $validEnvA);

        [$exitMismatch] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'testing'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );
        [$verifyMismatch] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'testing'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE'],
            'require $argv[1]; exit(\App\Support\EnvironmentSetup::verifyApplicationEncryption($argv[2]));'
        );

        expect($exitMismatch)->not->toBe(0)
            ->and($verifyMismatch)->not->toBe(0)
            ->and(File::get($envPath))->toBe($validEnvA)
            ->and(File::get($testingPath))->toBe($testingEnv);

        // Unrelated variant file: under APP_ENV=local (no .env.local exists), .env updates cleanly while .env.testing stays untouched.
        File::put($envPath, $emptyEnv);

        [$exitLocal, $outLocal, $errLocal] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );
        $generated = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');

        expect($exitLocal)->toBe(0, $errLocal)
            ->and($generated)->toStartWith('base64:')
            ->and($generated)->not->toBe($validKeyB)
            ->and(File::get($testingPath))->toBe($testingEnv)
            ->and($outLocal)->toContain('Application key set successfully.');
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('prepare and verify neutralize inherited APP_KEY overrides and bind to target .env', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-inherited-key-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $inheritedKey = 'base64:'.base64_encode(random_bytes(32));
        $fileKey = 'base64:'.base64_encode(random_bytes(32));

        // Invalid .env APP_KEY must fail even when parent process has a valid APP_KEY override.
        $invalidEnv = "APP_NAME=SitePulse\nAPP_KEY=invalid-target-key\nDB_CONNECTION=mysql\n";
        File::put($envPath, $invalidEnv);

        [$badPrep] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local', 'APP_KEY' => $inheritedKey]
        );
        [$badVerify] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local', 'APP_KEY' => $inheritedKey],
            [],
            'require $argv[1]; exit(\App\Support\EnvironmentSetup::verifyApplicationEncryption($argv[2]));'
        );

        expect($badPrep)->not->toBe(0)
            ->and($badVerify)->not->toBe(0)
            ->and(File::get($envPath))->toBe($invalidEnv);

        // Existing valid .env key must be preserved and verified instead of overwritten or masked by inherited APP_KEY.
        $validEnv = "APP_NAME=SitePulse\nAPP_KEY={$fileKey}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $validEnv);

        [$validPrep, , $validErr] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local', 'APP_KEY' => $inheritedKey]
        );
        expect($validPrep)->toBe(0, $validErr)
            ->and(File::get($envPath))->toBe($validEnv);

        // Empty .env must generate a fresh key distinct from inherited APP_KEY and verify against Laravel cleanly.
        File::put($envPath, "APP_NAME=SitePulse\nAPP_KEY=\nDB_CONNECTION=mysql\n");

        [$emptyPrep, , $emptyErr] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local', 'APP_KEY' => $inheritedKey]
        );
        $createdKey = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');

        expect($emptyPrep)->toBe(0, $emptyErr)
            ->and($createdKey)->toStartWith('base64:')
            ->and($createdKey)->not->toBe($inheritedKey);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

function runArtisanSubprocess(string $workspacePath, array $arguments, array $envOverrides = [], array $unsetVars = []): array
{
    $env = getenv();
    if (! is_array($env)) {
        $env = [];
    }

    foreach ($unsetVars as $name) {
        unset($env[$name]);
    }
    foreach ($envOverrides as $name => $value) {
        $env[$name] = $value;
    }

    $command = array_merge([PHP_BINARY, $workspacePath.DIRECTORY_SEPARATOR.'artisan'], $arguments);
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workspacePath,
        $env
    );

    expect(is_resource($process))->toBeTrue();

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

test('prepare rejects cached configuration whose valid key differs from .env key', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-cache-mismatch-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
        $cachedKey = 'base64:'.base64_encode(random_bytes(32));
        $envKey = 'base64:'.base64_encode(random_bytes(32));

        File::put($envPath, "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$cachedKey}\nDB_CONNECTION=sqlite\n");

        [$cacheExit, $cacheOut, $cacheErr] = runArtisanSubprocess(
            $tempDir,
            ['config:cache'],
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'DB_CONNECTION', 'DB_DATABASE']
        );
        expect($cacheExit)->toBe(0, $cacheOut.$cacheErr);

        $cachedConfigPath = $tempDir.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        $originalCachedConfig = File::get($cachedConfigPath);

        // Replace .env with a different valid key so bootstrap/cache/config.php holds a mismatched valid key.
        $mismatchedEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$envKey}\nDB_CONNECTION=sqlite\n";
        File::put($envPath, $mismatchedEnv);

        [$prepExit, $prepOut, $prepErr] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );
        [$verifyExit] = runPrepareWithEnvSubprocess(
            $tempDir,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE'],
            'require $argv[1]; exit(\App\Support\EnvironmentSetup::verifyApplicationEncryption($argv[2]));'
        );

        expect($prepExit)->not->toBe(0)
            ->and($verifyExit)->not->toBe(0)
            ->and($prepErr)->toContain('does not match')
            ->and($prepOut.$prepErr)->not->toContain($cachedKey)
            ->and($prepOut.$prepErr)->not->toContain($envKey)
            ->and(File::get($envPath))->toBe($mismatchedEnv)
            ->and(File::get($cachedConfigPath))->toBe($originalCachedConfig);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('prepare detects custom APP_CONFIG_CACHE declared in .env across blank, matching, mismatched, and uncached states', function () {
    $rootTemp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-custom-dotenv-cache-'.bin2hex(random_bytes(8));
    $workspacePath = $rootTemp.DIRECTORY_SEPARATOR.'SitePulse ! Cache Dir';
    createDisposableLaravelWorkspace($workspacePath);

    try {
        $envPath = $workspacePath.DIRECTORY_SEPARATOR.'.env';
        $customRel = 'bootstrap/cache/audit-custom.php';
        $customCachePath = $workspacePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'audit-custom.php';
        $defaultCachePath = $workspacePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        $keyOne = 'base64:'.base64_encode(random_bytes(32));
        $keyTwo = 'base64:'.base64_encode(random_bytes(32));

        // 1. No active cache on disk yet: blank APP_KEY generates cleanly and preserves APP_CONFIG_CACHE.
        File::put($envPath, "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY=\nAPP_CONFIG_CACHE={$customRel}\nDB_CONNECTION=mysql\n");
        [$freshExit, $freshOut, $freshErr] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );
        $generatedKey = EnvironmentSetup::readEnvValue($envPath, 'APP_KEY');
        expect($freshExit)->toBe(0, $freshOut.$freshErr)
            ->and($generatedKey)->toStartWith('base64:')
            ->and(EnvironmentSetup::readEnvValue($envPath, 'APP_CONFIG_CACHE'))->toBe($customRel)
            ->and(is_file($customCachePath))->toBeFalse();

        // 2. Build custom cache via artisan config:cache using keyOne (confirm default config.php does not exist).
        $cachedEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$keyOne}\nAPP_CONFIG_CACHE={$customRel}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $cachedEnv);
        [$cacheExit, $cacheOut, $cacheErr] = runArtisanSubprocess(
            $workspacePath,
            ['config:cache'],
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'DB_CONNECTION', 'DB_DATABASE']
        );
        expect($cacheExit)->toBe(0, $cacheOut.$cacheErr)
            ->and(is_file($customCachePath))->toBeTrue()
            ->and(is_file($defaultCachePath))->toBeFalse();
        $originalCacheBytes = File::get($customCachePath);

        // 3. Matching valid APP_KEY in .env succeeds idempotently and preserves both files.
        [$matchExit, $matchOut, $matchErr] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );
        expect($matchExit)->toBe(0, $matchOut.$matchErr)
            ->and($matchOut)->toContain('preserving current key')
            ->and(File::get($envPath))->toBe($cachedEnv)
            ->and(File::get($customCachePath))->toBe($originalCacheBytes);

        // 4. Codex reproduction: change .env to blank APP_KEY= while custom cache exists -> must fail closed and preserve both files.
        $blankEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY=\nAPP_CONFIG_CACHE={$customRel}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $blankEnv);
        [$blankExit, $blankOut, $blankErr] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );
        expect($blankExit)->not->toBe(0)
            ->and($blankErr)->toContain('audit-custom.php')
            ->and($blankOut.$blankErr)->not->toContain($keyOne)
            ->and(File::get($envPath))->toBe($blankEnv)
            ->and(File::get($customCachePath))->toBe($originalCacheBytes);

        // 5. Mismatched valid keyTwo in .env while custom cache holds keyOne -> must fail closed and preserve both files.
        $mismatchedEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$keyTwo}\nAPP_CONFIG_CACHE={$customRel}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $mismatchedEnv);
        [$misExit, $misOut, $misErr] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
        );
        [$verifyMisExit] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local'],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE'],
            'require $argv[1]; exit(\App\Support\EnvironmentSetup::verifyApplicationEncryption($argv[2]));'
        );
        expect($misExit)->not->toBe(0)
            ->and($verifyMisExit)->not->toBe(0)
            ->and($misErr)->toContain('does not match')
            ->and($misOut.$misErr)->not->toContain($keyOne)
            ->and($misOut.$misErr)->not->toContain($keyTwo)
            ->and(File::get($envPath))->toBe($mismatchedEnv)
            ->and(File::get($customCachePath))->toBe($originalCacheBytes);
    } finally {
        File::deleteDirectory($rootTemp);
    }
});

test('prepare detects custom APP_CONFIG_CACHE supplied via process environment across blank, matching, and mismatched states', function () {
    $rootTemp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-custom-proc-cache-'.bin2hex(random_bytes(8));
    $workspacePath = $rootTemp.DIRECTORY_SEPARATOR.'SitePulse ! Proc Cache';
    createDisposableLaravelWorkspace($workspacePath);

    try {
        $envPath = $workspacePath.DIRECTORY_SEPARATOR.'.env';
        $procRel = 'bootstrap/cache/proc-custom.php';
        $procCachePath = $workspacePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'proc-custom.php';
        $keyOne = 'base64:'.base64_encode(random_bytes(32));
        $keyTwo = 'base64:'.base64_encode(random_bytes(32));

        $validEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$keyOne}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $validEnv);

        [$cacheExit, $cacheOut, $cacheErr] = runArtisanSubprocess(
            $workspacePath,
            ['config:cache'],
            ['APP_ENV' => 'local', 'APP_CONFIG_CACHE' => $procRel],
            ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'DB_CONNECTION', 'DB_DATABASE']
        );
        expect($cacheExit)->toBe(0, $cacheOut.$cacheErr)
            ->and(is_file($procCachePath))->toBeTrue();
        $originalCacheBytes = File::get($procCachePath);

        // Matching process cache succeeds and preserves both files.
        [$matchExit, $matchOut, $matchErr] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local', 'APP_CONFIG_CACHE' => $procRel],
            ['APP_KEY', 'APP_PREVIOUS_KEYS']
        );
        expect($matchExit)->toBe(0, $matchOut.$matchErr)
            ->and(File::get($envPath))->toBe($validEnv)
            ->and(File::get($procCachePath))->toBe($originalCacheBytes);

        // Blank APP_KEY= with existing process cache fails closed and preserves both files.
        $blankEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY=\nDB_CONNECTION=mysql\n";
        File::put($envPath, $blankEnv);
        [$blankExit, , $blankErr] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local', 'APP_CONFIG_CACHE' => $procRel],
            ['APP_KEY', 'APP_PREVIOUS_KEYS']
        );
        expect($blankExit)->not->toBe(0)
            ->and($blankErr)->toContain('proc-custom.php')
            ->and(File::get($envPath))->toBe($blankEnv)
            ->and(File::get($procCachePath))->toBe($originalCacheBytes);

        // Mismatched valid key in .env vs process cache fails closed and preserves both files.
        $mismatchEnv = "APP_NAME=SitePulse\nAPP_ENV=local\nAPP_KEY={$keyTwo}\nDB_CONNECTION=mysql\n";
        File::put($envPath, $mismatchEnv);
        [$misExit] = runPrepareWithEnvSubprocess(
            $workspacePath,
            ['APP_ENV' => 'local', 'APP_CONFIG_CACHE' => $procRel],
            ['APP_KEY', 'APP_PREVIOUS_KEYS']
        );
        expect($misExit)->not->toBe(0)
            ->and(File::get($envPath))->toBe($mismatchEnv)
            ->and(File::get($procCachePath))->toBe($originalCacheBytes);
    } finally {
        File::deleteDirectory($rootTemp);
    }
});

test('prepare fails closed on unsafe or ambiguous APP_CONFIG_CACHE paths', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-unsafe-cache-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';

        foreach ([
            'parent traversal' => "APP_NAME=SitePulse\nAPP_KEY=\nAPP_CONFIG_CACHE=../escape.php\n",
            'stream wrapper' => "APP_NAME=SitePulse\nAPP_KEY=\nAPP_CONFIG_CACHE=phar://cache.phar\n",
            'empty explicit path' => "APP_NAME=SitePulse\nAPP_KEY=\nAPP_CONFIG_CACHE=\"\"\n",
        ] as $caseName => $template) {
            File::put($envPath, $template);
            [$exitCode] = runPrepareWithEnvSubprocess(
                $tempDir,
                ['APP_ENV' => 'local'],
                ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
            );
            expect($exitCode)->not->toBe(0, $caseName)
                ->and(File::get($envPath))->toBe($template);
        }

        // Unsafe process-supplied cache values must fail closed across Windows and Unix.
        $cleanEnv = "APP_NAME=SitePulse\nAPP_KEY=\nDB_CONNECTION=mysql\n";
        File::put($envPath, $cleanEnv);
        foreach (['whitespace only' => '   ', 'parent traversal' => '../proc-escape.php', 'stream wrapper' => 'phar://proc-cache.phar'] as $caseName => $badProcPath) {
            [$procBadExit] = runPrepareWithEnvSubprocess(
                $tempDir,
                ['APP_ENV' => 'local', 'APP_CONFIG_CACHE' => $badProcPath],
                ['APP_KEY', 'APP_PREVIOUS_KEYS']
            );
            expect($procBadExit)->not->toBe(0, $caseName)
                ->and(File::get($envPath))->toBe($cleanEnv);
        }

        // Symlinked cache file must fail closed when host OS allows symlink creation.
        $cacheDir = $tempDir.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
        File::ensureDirectoryExists($cacheDir);
        $realTarget = $tempDir.DIRECTORY_SEPARATOR.'outside-cache.php';
        File::put($realTarget, "<?php return ['app' => ['key' => '']];\n");
        $symCache = $cacheDir.DIRECTORY_SEPARATOR.'symlinked.php';
        if (@symlink($realTarget, $symCache)) {
            $symEnv = "APP_NAME=SitePulse\nAPP_KEY=\nAPP_CONFIG_CACHE=bootstrap/cache/symlinked.php\n";
            File::put($envPath, $symEnv);
            [$symExit] = runPrepareWithEnvSubprocess(
                $tempDir,
                ['APP_ENV' => 'local'],
                ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_CONFIG_CACHE']
            );
            expect($symExit)->not->toBe(0)
                ->and(File::get($envPath))->toBe($symEnv);
        }
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('prepare rejects hard-linked, directory, and symlinked .env destinations safely', function () {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-dest-safety-'.bin2hex(random_bytes(8));
    createDisposableLaravelWorkspace($tempDir);

    try {
        $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';

        // Directory at .env path.
        File::ensureDirectoryExists($envPath);
        [$dirExit] = runPrepareCapturingOutput($tempDir);
        expect($dirExit)->not->toBe(0);
        File::deleteDirectory($envPath);

        // Hard-linked .env path.
        $originalEnv = "APP_NAME=SitePulse\nAPP_KEY=\nDB_CONNECTION=mysql\n";
        $hardLinkPath = $tempDir.DIRECTORY_SEPARATOR.'env.hardlink';
        File::put($envPath, $originalEnv);
        if (@link($envPath, $hardLinkPath)) {
            [$hardExit] = runPrepareCapturingOutput($tempDir);
            expect($hardExit)->not->toBe(0)
                ->and(File::get($envPath))->toBe($originalEnv)
                ->and(File::get($hardLinkPath))->toBe($originalEnv);
            @unlink($hardLinkPath);
        }
        @unlink($envPath);

        // Symlinked .env path (when host OS permits symlink creation).
        $outsideTarget = $tempDir.DIRECTORY_SEPARATOR.'outside.secret';
        File::put($outsideTarget, "APP_KEY=\n");
        if (@symlink($outsideTarget, $envPath)) {
            [$symExit] = runPrepareCapturingOutput($tempDir);
            expect($symExit)->not->toBe(0)
                ->and(is_link($envPath))->toBeTrue()
                ->and(File::get($outsideTarget))->toBe("APP_KEY=\n");
        }
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('createExclusiveTempEnvFile and createExclusiveRecoveryEnvFile create git-ignored files excluded from git add dry-run', function () {
    $gitDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse-gitignore-'.bin2hex(random_bytes(8));
    expect(@mkdir($gitDir, 0755, true))->toBeTrue();
    $createdPaths = [];

    try {
        expect(@copy(base_path('.gitignore'), $gitDir.DIRECTORY_SEPARATOR.'.gitignore'))->toBeTrue();

        $runGit = static function (string $cwd, array $args): array {
            $process = proc_open(
                array_merge(['git'], $args),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $cwd
            );
            expect($process)->toBeResource();
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $stdout, $stderr];
        };

        [$initExit, $initOut, $initErr] = $runGit($gitDir, ['init', '-q']);
        expect($initExit)->toBe(0, $initOut.$initErr);

        $cases = [
            ['factory' => fn () => EnvironmentSetup::createExclusiveTempEnvFile($gitDir), 'pattern' => '/^\.env\.tmp\.[0-9a-f]{24}$/'],
            ['factory' => fn () => EnvironmentSetup::createExclusiveRecoveryEnvFile($gitDir), 'pattern' => '/^\.env\.recovery\.[0-9a-f]{24}$/'],
        ];

        $basenames = [];
        foreach ($cases as $case) {
            $created = $case['factory']();
            expect($created)->toBeArray()->toHaveCount(2);

            [$path, $handle] = $created;
            $createdPaths[] = $path;
            expect(is_resource($handle))->toBeTrue()
                ->and(dirname($path))->toBe($gitDir);

            $basename = basename($path);
            $basenames[] = $basename;
            expect($basename)->toMatch($case['pattern'])
                ->and($basename)->not->toMatch('/^\.en[0-9a-f]/i');

            expect(fwrite($handle, "APP_KEY=base64:TEMP_CANARY_9182\n"))->toBeInt();
            expect(fclose($handle))->toBeTrue();

            [$ignoreExit, $ignoreOut, $ignoreErr] = $runGit($gitDir, ['check-ignore', '-v', $basename]);
            expect($ignoreExit)->toBe(0, $ignoreOut.$ignoreErr)
                ->and($ignoreOut)->toContain('.env.*')
                ->and($ignoreOut)->toContain($basename);

            if (is_dir(base_path('.git'))) {
                [$repoIgnoreExit, $repoIgnoreOut, $repoIgnoreErr] = $runGit(base_path(), ['check-ignore', '-v', '--no-index', $basename]);
                expect($repoIgnoreExit)->toBe(0, $repoIgnoreOut.$repoIgnoreErr)
                    ->and($repoIgnoreOut)->toContain('.env.*');
            }
        }

        [$addDryExit, $addDryOut, $addDryErr] = $runGit($gitDir, ['add', '-n', '.']);
        expect($addDryExit)->toBe(0, $addDryOut.$addDryErr)
            ->and($addDryOut)->toContain('.gitignore');
        foreach ($basenames as $basename) {
            expect($addDryOut)->not->toContain($basename);
        }
        expect($addDryOut)->not->toContain('.env.tmp.')
            ->and($addDryOut)->not->toContain('.env.recovery.');
    } finally {
        foreach ($createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        File::deleteDirectory($gitDir);
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
