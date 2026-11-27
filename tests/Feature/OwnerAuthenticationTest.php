<?php

use App\Console\Commands\CreateOwnerCommand;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function ownerTestPassword(): string
{
    return 'SitePulse#Owner94!';
}

function buildCleanSubprocessEnv(array $overrides = []): array
{
    $env = [];

    foreach (getenv() as $key => $value) {
        if (! is_string($value)) {
            continue;
        }

        if (in_array($key, [
            'APP_ENV',
            'APP_KEY',
            'APP_DEBUG',
            'APP_URL',
            'APP_CONFIG_CACHE',
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_URL',
            'CACHE_STORE',
            'SESSION_DRIVER',
            'SESSION_SECURE_COOKIE',
            'BCRYPT_ROUNDS',
        ], true)) {
            continue;
        }

        $env[$key] = $value;
    }

    return array_merge($env, $overrides);
}

function prepareOwnerTestWorkspace(string $workspacePath): void
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

function createSubprocessOwnerWorkspace(string $workspacePath): string
{
    prepareOwnerTestWorkspace($workspacePath);
    File::copyDirectory(database_path('migrations'), $workspacePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');

    $dbPath = $workspacePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
    File::put($dbPath, '');

    $envPath = $workspacePath.DIRECTORY_SEPARATOR.'.env';
    File::put($envPath, implode("\n", [
        'APP_NAME=SitePulse',
        'APP_ENV=local',
        'APP_KEY=base64:'.base64_encode(random_bytes(32)),
        'APP_DEBUG=false',
        'APP_URL=http://localhost',
        'BCRYPT_ROUNDS=4',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE='.$dbPath,
        'SESSION_DRIVER=array',
        'CACHE_STORE=array',
        '',
    ]));

    $migrate = proc_open(
        [PHP_BINARY, $workspacePath.DIRECTORY_SEPARATOR.'artisan', 'migrate', '--force', '--no-interaction'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workspacePath,
        buildCleanSubprocessEnv()
    );

    expect(is_resource($migrate))->toBeTrue();
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($migrate);

    expect($exit)->toBe(0, (string) $out.(string) $err);

    return $dbPath;
}

test('create-owner provisions the single initial owner via interactive masked prompts', function () {
    $password = ownerTestPassword();

    $this->artisan('sitepulse:create-owner')
        ->expectsQuestion('Owner name', '  Site Owner  ')
        ->expectsQuestion('Owner email address', '  Owner@Example.COM  ')
        ->expectsQuestion('Owner password (min 12 chars, upper/lower/number/symbol)', $password)
        ->expectsQuestion('Confirm owner password', $password)
        ->expectsOutputToContain('owner@example.com')
        ->doesntExpectOutputToContain($password)
        ->assertExitCode(0);

    $owner = User::query()->firstOrFail();

    expect(User::query()->count())->toBe(1)
        ->and($owner->name)->toBe('Site Owner')
        ->and($owner->email)->toBe('owner@example.com')
        ->and($owner->isOwner())->toBeTrue()
        ->and($owner->owner_slot)->toBe(1)
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($owner->password)->not->toBe($password)
        ->and(Hash::check($password, $owner->password))->toBeTrue();
});

test('create-owner reads password securely via stdin without CLI password option', function () {
    $password = ownerTestPassword();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $password."\n");
    rewind($stream);

    CreateOwnerCommand::useStdinStream($stream);

    try {
        $this->artisan('sitepulse:create-owner', [
            '--name' => 'CLI Owner',
            '--email' => 'cli@example.com',
            '--password-stdin' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('cli@example.com')
            ->doesntExpectOutputToContain($password)
            ->assertExitCode(0);
    } finally {
        CreateOwnerCommand::useStdinStream(null);
        fclose($stream);
    }

    $definition = Artisan::all()['sitepulse:create-owner']->getDefinition();
    expect($definition->hasOption('password'))->toBeFalse();

    $owner = User::query()->sole();
    expect(Hash::check($password, $owner->password))->toBeTrue();
});

test('create-owner rejects password confirmation mismatch and leaves database clean without leaking password', function () {
    $password = ownerTestPassword();
    $mismatch = 'DifferentPass#99!';

    $this->artisan('sitepulse:create-owner', [
        '--name' => 'Owner',
        '--email' => 'owner@example.com',
    ])
        ->expectsQuestion('Owner password (min 12 chars, upper/lower/number/symbol)', $password)
        ->expectsQuestion('Confirm owner password', $mismatch)
        ->doesntExpectOutputToContain($password)
        ->doesntExpectOutputToContain($mismatch)
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('create-owner enforces password strength and hashes with configured bcrypt rounds', function (string $weakPassword) {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $weakPassword."\n");
    rewind($stream);

    CreateOwnerCommand::useStdinStream($stream);

    try {
        $this->artisan('sitepulse:create-owner', [
            '--name' => 'Owner',
            '--email' => 'owner@example.com',
            '--password-stdin' => true,
            '--no-interaction' => true,
        ])
            ->doesntExpectOutputToContain($weakPassword)
            ->assertExitCode(1);
    } finally {
        CreateOwnerCommand::useStdinStream(null);
        fclose($stream);
    }

    expect(User::query()->count())->toBe(0);
})->with([
    'too short' => 'Ab1!short',
    'no uppercase' => 'lowercase1234!',
    'no lowercase' => 'UPPERCASE1234!',
    'no digits' => 'NoNumbersHere!!',
    'no symbols' => 'NoSymbolsHere123',
    'exceeds bcrypt 72 bytes' => 'Ab1!'.str_repeat('x', 69),
]);

test('bcrypt hashes respect configured work factor', function () {
    config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => 12]);

    $password = ownerTestPassword();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $password."\n");
    rewind($stream);

    CreateOwnerCommand::useStdinStream($stream);

    try {
        $this->artisan('sitepulse:create-owner', [
            '--name' => 'Owner',
            '--email' => 'owner@example.com',
            '--password-stdin' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);
    } finally {
        CreateOwnerCommand::useStdinStream(null);
        fclose($stream);
    }

    $owner = User::query()->sole();
    $info = Hash::info($owner->password);

    expect($info['algoName'])->toBe('bcrypt')
        ->and($info['options']['cost'] ?? null)->toBe(12)
        ->and(Hash::check($password, $owner->password))->toBeTrue();
});

test('create-owner refuses duplicate owner creation when an account already exists', function () {
    $existing = User::factory()->owner()->create([
        'email' => 'first@example.com',
    ]);

    $password = ownerTestPassword();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $password."\n");
    rewind($stream);

    CreateOwnerCommand::useStdinStream($stream);

    try {
        $this->artisan('sitepulse:create-owner', [
            '--name' => 'Second Owner',
            '--email' => 'second@example.com',
            '--password-stdin' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('already exists')
            ->doesntExpectOutputToContain($password)
            ->assertExitCode(1);
    } finally {
        CreateOwnerCommand::useStdinStream(null);
        fclose($stream);
    }

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->id)->toBe($existing->id);
});

test('create-owner enforces single-owner invariant atomically under concurrent subprocess invocations', function () {
    $workspacePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_concurrent_owner_'.bin2hex(random_bytes(8));
    $dbPath = createSubprocessOwnerWorkspace($workspacePath);

    try {
        $passwordA = 'ConcurrentPassA#91!';
        $passwordB = 'ConcurrentPassB#92!';

        $launch = static function (string $name, string $email, string $password) use ($workspacePath): array {
            $process = proc_open(
                [
                    PHP_BINARY,
                    $workspacePath.DIRECTORY_SEPARATOR.'artisan',
                    'sitepulse:create-owner',
                    '--name='.$name,
                    '--email='.$email,
                    '--password-stdin',
                    '--no-interaction',
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $workspacePath,
                buildCleanSubprocessEnv()
            );

            expect(is_resource($process))->toBeTrue();
            fwrite($pipes[0], $password."\n");
            fclose($pipes[0]);

            return [$process, $pipes];
        };

        [$procA, $pipesA] = $launch('Owner One', 'one@example.com', $passwordA);
        [$procB, $pipesB] = $launch('Owner Two', 'two@example.com', $passwordB);

        $outA = (string) stream_get_contents($pipesA[1]);
        $errA = (string) stream_get_contents($pipesA[2]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);
        $exitA = proc_close($procA);

        $outB = (string) stream_get_contents($pipesB[1]);
        $errB = (string) stream_get_contents($pipesB[2]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);
        $exitB = proc_close($procB);

        $combined = "A({$exitA}): {$outA}{$errA}\nB({$exitB}): {$outB}{$errB}";

        expect(in_array(0, [$exitA, $exitB], true))->toBeTrue($combined)
            ->and(in_array(1, [$exitA, $exitB], true))->toBeTrue($combined)
            ->and($outA.$errA.$outB.$errB)->not->toContain($passwordA)
            ->and($outA.$errA.$outB.$errB)->not->toContain($passwordB);

        config([
            'database.connections.sqlite_concurrent_owner' => array_merge(
                config('database.connections.sqlite'),
                ['database' => $dbPath]
            ),
        ]);
        DB::purge('sqlite_concurrent_owner');

        $rows = DB::connection('sqlite_concurrent_owner')->table('users')->get();
        DB::disconnect('sqlite_concurrent_owner');

        expect($rows)->toHaveCount(1)
            ->and((bool) $rows[0]->is_owner)->toBeTrue()
            ->and((int) $rows[0]->owner_slot)->toBe(1);
    } finally {
        File::deleteDirectory($workspacePath);
    }
});

test('user model prevents mass assignment of owner privilege flags', function () {
    $user = User::create([
        'name' => 'Injected',
        'email' => 'injected@example.com',
        'password' => ownerTestPassword(),
        'is_owner' => true,
        'owner_slot' => 1,
    ]);

    expect($user->fresh()->isOwner())->toBeFalse()
        ->and($user->fresh()->owner_slot)->toBeNull();
});

test('owner login authenticates valid credentials, regenerates session id, and exposes security headers', function () {
    $password = ownerTestPassword();
    $owner = User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => $password,
    ]);

    $loginPage = $this->get('/login');
    $loginPage->assertOk()
        ->assertSee('Owner Sign In')
        ->assertSee('autocomplete="username"', false)
        ->assertSee('autocomplete="current-password"', false)
        ->assertDontSee('Register')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect((string) $loginPage->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $loginPage->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");

    $previousSessionId = session()->getId();

    $response = $this->post('/login', [
        'email' => '  OWNER@example.com ',
        'password' => $password,
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($owner);

    expect(session()->getId())->not->toBe($previousSessionId);

    $cookies = $response->headers->getCookies();
    $sessionCookie = collect($cookies)->first(
        static fn ($cookie): bool => $cookie->getName() === config('session.cookie')
    );

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->isHttpOnly())->toBeTrue()
        ->and($sessionCookie->getSameSite())->toBe('lax');
});

test('invalid login uses identical generic failure message and never exposes submitted password', function () {
    $password = ownerTestPassword();
    User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => $password,
    ]);

    $wrongPassword = 'WrongSecret#42!';

    $wrongPassResponse = $this->from('/login')->post('/login', [
        'email' => 'owner@example.com',
        'password' => $wrongPassword,
    ]);

    $unknownUserResponse = $this->from('/login')->post('/login', [
        'email' => 'unknown@example.com',
        'password' => $wrongPassword,
    ]);

    $wrongPassResponse->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $unknownUserResponse->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    $this->assertGuest();
    expect(session()->getOldInput('password'))->toBeNull()
        ->and(session()->getOldInput('email'))->toBe('unknown@example.com');
});

test('non-owner database user is rejected at login and blocked from dashboard authorization gate', function () {
    $password = ownerTestPassword();
    $nonOwner = User::factory()->create([
        'email' => 'nonowner@example.com',
        'password' => $password,
        'is_owner' => false,
    ]);

    $loginResponse = $this->from('/login')->post('/login', [
        'email' => 'nonowner@example.com',
        'password' => $password,
    ]);

    $loginResponse->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $this->assertGuest();

    $this->actingAs($nonOwner)->get('/dashboard')->assertForbidden();
});

test('login rate limits to five attempts per minute per IP including subsequent valid credentials', function () {
    $password = ownerTestPassword();
    User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => $password,
    ]);

    $attackerIp = '203.0.113.10';
    RateLimiter::clear('login:ip:'.$attackerIp);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
            ->post('/login', [
                'email' => "guess{$attempt}@example.com",
                'password' => 'WrongPass#99!',
            ])
            ->assertRedirect();
    }

    // Even valid credentials from the same IP must be rejected with 429 during cooldown.
    $blocked = $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
        ->post('/login', [
            'email' => 'owner@example.com',
            'password' => $password,
        ]);

    $blocked->assertTooManyRequests()
        ->assertSee('Too many login attempts');
    expect($blocked->headers->has('Retry-After'))->toBeTrue();
    $this->assertGuest();

    // A separate IP is still permitted to authenticate cleanly.
    $otherIp = '198.51.100.20';
    RateLimiter::clear('login:ip:'.$otherIp);
    $this->withServerVariables(['REMOTE_ADDR' => $otherIp])
        ->post('/login', [
            'email' => 'owner@example.com',
            'password' => $password,
        ])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();
});

test('logout destroys authentication, invalidates session, rotates CSRF token, and rejects GET logout', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner);
    $oldSessionId = session()->getId();
    $oldCsrfToken = session()->token();

    $this->get('/logout')->assertMethodNotAllowed();

    $response = $this->post('/logout');

    $response->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You have been signed out.');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($oldSessionId)
        ->and(session()->token())->not->toBe($oldCsrfToken);

    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('csrf middleware rejects forged POST requests to login and logout when CSRF enforcement is active', function () {
    $owner = User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => ownerTestPassword(),
    ]);

    $this->app->bind(PreventRequestForgery::class, static function ($app) {
        return new class($app, $app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    });

    $this->post('/login', [
        'email' => 'owner@example.com',
        'password' => ownerTestPassword(),
    ])->assertStatus(419);

    $this->post('/login', [
        '_token' => 'invalid-csrf-token',
        'email' => 'owner@example.com',
        'password' => ownerTestPassword(),
    ])->assertStatus(419);

    $this->get('/login')->assertOk();
    $validToken = session()->token();

    $this->post('/login', [
        '_token' => $validToken,
        'email' => 'owner@example.com',
        'password' => ownerTestPassword(),
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);

    $this->post('/logout', [
        '_token' => 'invalid-csrf-token',
    ])->assertStatus(419);

    $this->assertAuthenticatedAs($owner);

    $this->post('/logout', [
        '_token' => session()->token(),
    ])->assertRedirect(route('login'));

    $this->assertGuest();
});

test('dashboard requires authentication and authenticated users are redirected away from login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));

    $owner = User::factory()->owner()->create([
        'name' => 'Alex Owner',
        'email' => 'alex@example.com',
    ]);

    $dashboard = $this->actingAs($owner)->get('/dashboard');

    $dashboard->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Alex Owner')
        ->assertSee('Sign Out')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect((string) $dashboard->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs($owner)->get('/login')->assertRedirect(route('dashboard'));
});

test('public registration endpoints do not exist and seeding creates zero users', function () {
    $this->seed();

    expect(User::query()->count())->toBe(0)
        ->and(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Attacker',
        'email' => 'attacker@example.com',
        'password' => ownerTestPassword(),
    ])->assertNotFound();
});

test('session cookie configuration sets HttpOnly, SameSite=Lax, and defaults to Secure in HTTPS/production environments', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');

    $workspacePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitepulse_session_cfg_'.bin2hex(random_bytes(8));
    prepareOwnerTestWorkspace($workspacePath);
    File::put($workspacePath.DIRECTORY_SEPARATOR.'.env', "APP_NAME=SitePulse\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n");

    try {
        $resolveSecure = static function (array $env) use ($workspacePath): bool {
            $probe = <<<'PHP'
require __AUTOLOAD__;
$app = require __WORKSPACE__ . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo ($app->make('config')->get('session.secure') ? 'true' : 'false');
PHP;
            $code = str_replace(
                ['__AUTOLOAD__', '__WORKSPACE__'],
                [var_export(base_path('vendor/autoload.php'), true), var_export($workspacePath, true)],
                $probe
            );
            $proc = proc_open(
                [PHP_BINARY, '-r', $code],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $workspacePath,
                buildCleanSubprocessEnv($env)
            );
            expect(is_resource($proc))->toBeTrue();
            fclose($pipes[0]);
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($proc))->toBe(0, $err);

            return $out === 'true';
        };

        expect($resolveSecure(['APP_ENV' => 'production', 'APP_URL' => 'https://monitor.example']))->toBeTrue()
            ->and($resolveSecure(['APP_ENV' => 'local', 'APP_URL' => 'https://monitor.example']))->toBeTrue()
            ->and($resolveSecure(['APP_ENV' => 'local', 'APP_URL' => 'http://localhost', 'SESSION_SECURE_COOKIE' => 'false']))->toBeFalse();
    } finally {
        File::deleteDirectory($workspacePath);
    }
});
