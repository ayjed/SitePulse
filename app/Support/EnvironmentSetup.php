<?php

namespace App\Support;

use Dotenv\Dotenv;
use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Throwable;

class EnvironmentSetup
{
    /**
     * Prepare the local environment file, application key, and SQLite database idempotently.
     */
    public static function prepare(string $basePath): int
    {
        $basePath = rtrim($basePath, '\\/');
        $envPath = $basePath.DIRECTORY_SEPARATOR.'.env';
        $examplePath = $basePath.DIRECTORY_SEPARATOR.'.env.example';

        if (! file_exists($envPath)) {
            if (! file_exists($examplePath)) {
                fwrite(STDERR, "Missing .env.example in {$basePath}.\n");

                return 1;
            }

            if (! @copy($examplePath, $envPath)) {
                fwrite(STDERR, "Unable to copy .env.example to .env.\n");

                return 1;
            }

            echo "Created .env from .env.example.\n";
        }

        try {
            $parsedEnv = self::parseEnvFile($envPath);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Invalid .env file: '.$e->getMessage()."\n");

            return 1;
        }

        $cipher = self::resolveCipher($parsedEnv);
        $rawKey = $parsedEnv['APP_KEY'] ?? null;

        if ($rawKey === null || $rawKey === '') {
            if (! self::normalizeEmptyAppKeyLine($envPath)) {
                fwrite(STDERR, "Unable to prepare APP_KEY entry in .env.\n");

                return 1;
            }

            $keyGenExit = self::runArtisanKeyGenerate($basePath);
            if ($keyGenExit !== 0) {
                return $keyGenExit;
            }

            try {
                $refreshedEnv = self::parseEnvFile($envPath);
            } catch (Throwable $e) {
                fwrite(STDERR, 'Invalid .env file after key generation: '.$e->getMessage()."\n");

                return 1;
            }

            $generatedKey = $refreshedEnv['APP_KEY'] ?? null;
            if (! is_string($generatedKey) || ! self::isValidAppKey($generatedKey, $cipher)) {
                fwrite(STDERR, "Setup failed: key generation did not produce a valid APP_KEY.\n");

                return 1;
            }

            if (self::verifyApplicationEncryption($basePath) !== 0) {
                fwrite(STDERR, "Setup failed: Laravel encryption service could not initialize with generated APP_KEY.\n");

                return 1;
            }
        } else {
            if (! self::isValidAppKey($rawKey, $cipher) || self::verifyApplicationEncryption($basePath) !== 0) {
                fwrite(STDERR, "Existing APP_KEY in .env is invalid or cannot initialize Laravel encryption; repair or clear APP_KEY and retry setup.\n");

                return 1;
            }

            echo "Existing APP_KEY detected; preserving current key.\n";
        }

        $sqlitePath = $basePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';

        try {
            self::ensureSqliteDatabase($envPath, $sqlitePath, $basePath, $parsedEnv);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }

        return 0;
    }

    /**
     * Determine whether the given .env file defines a syntactically valid encryption key.
     */
    public static function hasAppKey(string $envPath): bool
    {
        try {
            $parsed = self::parseEnvFile($envPath);
        } catch (Throwable) {
            return false;
        }

        $key = $parsed['APP_KEY'] ?? null;
        if (! is_string($key) || $key === '') {
            return false;
        }

        return self::isValidAppKey($key, self::resolveCipher($parsed));
    }

    /**
     * Validate a raw APP_KEY string against Laravel's Encrypter rules without exposing the secret.
     */
    public static function isValidAppKey(string $key, string $cipher = 'AES-256-CBC'): bool
    {
        if ($key === '') {
            return false;
        }

        $material = $key;
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                return false;
            }
            $material = $decoded;
        }

        if (! Encrypter::supported($material, $cipher)) {
            return false;
        }

        try {
            new Encrypter($material, $cipher);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalize missing or empty APP_KEY placeholders (e.g. APP_KEY="", export APP_KEY=, APP_KEY =)
     * so Laravel's key:generate command replaces them cleanly without leaving malformed suffixes.
     */
    public static function normalizeEmptyAppKeyLine(string $envPath): bool
    {
        if (! is_file($envPath) || ! is_writable($envPath)) {
            return false;
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return false;
        }

        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $hadTrailingNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");
        $lines = preg_split("/\r\n|\n|\r/", $contents);

        if ($lines === false) {
            return false;
        }

        if ($hadTrailingNewline && end($lines) === '') {
            array_pop($lines);
        }

        $replaced = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:export\s+)?APP_KEY\s*=/', $line) === 1) {
                $lines[$index] = 'APP_KEY=';
                $replaced = true;
            }
        }

        if (! $replaced) {
            $lines[] = 'APP_KEY=';
        }

        $updated = implode($eol, $lines);
        if ($hadTrailingNewline || $updated !== '') {
            $updated .= $eol;
        }

        return @file_put_contents($envPath, $updated) !== false;
    }

    /**
     * Create the SQLite database file if the environment uses file-backed SQLite.
     *
     * @param  array<string, string|null>|null  $parsedEnv
     *
     * @throws RuntimeException When the directory or database file cannot be created or written.
     */
    public static function ensureSqliteDatabase(
        string $envPath,
        string $defaultSqlitePath,
        ?string $basePath = null,
        ?array $parsedEnv = null
    ): bool {
        $parsed = $parsedEnv ?? self::parseEnvFile($envPath);

        $connection = $parsed['DB_CONNECTION'] ?? 'sqlite';
        if (! is_string($connection) || $connection === '') {
            $connection = 'sqlite';
        }

        if (strtolower($connection) !== 'sqlite') {
            return false;
        }

        $configuredDatabase = $parsed['DB_DATABASE'] ?? null;
        if ($configuredDatabase === ':memory:') {
            return false;
        }

        $targetPath = $defaultSqlitePath;
        if (is_string($configuredDatabase) && $configuredDatabase !== '') {
            $targetPath = self::resolvePath($configuredDatabase, $basePath);
        }

        $directory = dirname($targetPath);
        if (! is_dir($directory)) {
            if (file_exists($directory)) {
                throw new RuntimeException("Cannot create SQLite directory: {$directory} exists and is not a directory.");
            }

            if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create SQLite directory at {$directory}.");
            }
        }

        if (file_exists($targetPath)) {
            if (is_dir($targetPath)) {
                throw new RuntimeException("SQLite database path {$targetPath} is a directory.");
            }

            if (! is_writable($targetPath)) {
                throw new RuntimeException("SQLite database file {$targetPath} is not writable.");
            }

            return false;
        }

        if (! @touch($targetPath) || ! is_file($targetPath)) {
            throw new RuntimeException("Unable to create SQLite database file at {$targetPath}.");
        }

        echo "Created SQLite database file at {$targetPath}.\n";

        return true;
    }

    /**
     * Read a variable value from a .env file using Laravel's Dotenv parser.
     */
    public static function readEnvValue(string $envPath, string $key): ?string
    {
        try {
            $parsed = self::parseEnvFile($envPath);
        } catch (Throwable) {
            return null;
        }

        return $parsed[$key] ?? null;
    }

    /**
     * Parse a .env file strictly via vlucas/phpdotenv without mutating global environment state.
     *
     * @return array<string, string|null>
     */
    public static function parseEnvFile(string $envPath): array
    {
        if (! is_file($envPath) || ! is_readable($envPath)) {
            throw new RuntimeException("Unable to read .env file at {$envPath}.");
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read .env file at {$envPath}.");
        }

        return Dotenv::parse($contents);
    }

    /**
     * Verify in a fresh subprocess that Laravel's real encryption service initializes cleanly.
     */
    public static function verifyApplicationEncryption(string $basePath): int
    {
        $bootstrapApp = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        if (! is_file($bootstrapApp)) {
            fwrite(STDERR, "Missing bootstrap/app.php in {$basePath}.\n");

            return 1;
        }

        $autoloadPath = $basePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
        if (! is_file($autoloadPath)) {
            $autoloadPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
        }

        if (! is_file($autoloadPath)) {
            fwrite(STDERR, "Missing Composer autoload.php for encryption verification.\n");

            return 1;
        }

        $probeScript = <<<'PHP'
require $argv[2];
$app = require $argv[1] . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$encrypter = $app->make('encrypter');
$payload = $encrypter->encrypt('sitepulse-key-verification');
if ($encrypter->decrypt($payload) !== 'sitepulse-key-verification') {
    exit(1);
}
PHP;

        return self::runSubprocess(
            [PHP_BINARY, '-r', $probeScript, $basePath, $autoloadPath],
            $basePath,
            false
        );
    }

    /**
     * Execute Laravel's key:generate command using an argument array (shell-free) subprocess.
     */
    protected static function runArtisanKeyGenerate(string $basePath): int
    {
        $artisanPath = $basePath.DIRECTORY_SEPARATOR.'artisan';
        if (! is_file($artisanPath)) {
            fwrite(STDERR, "Missing artisan entrypoint in {$basePath}.\n");

            return 1;
        }

        return self::runSubprocess(
            [PHP_BINARY, $artisanPath, 'key:generate', '--force', '--ansi'],
            $basePath,
            true
        );
    }

    /**
     * Execute a subprocess via argument array so special path characters (such as spaces and '!')
     * bypass shell interpolation on Windows and Unix hosts.
     *
     * @param  list<string>  $command
     */
    private static function runSubprocess(array $command, string $cwd, bool $forwardStdout): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, self::cleanChildEnvironment());
        if (! is_resource($process)) {
            fwrite(STDERR, "Failed to start subprocess.\n");

            return 1;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($forwardStdout && is_string($stdout) && $stdout !== '') {
            echo $stdout;
        }

        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $stderr);
        }

        return $exitCode;
    }

    /**
     * Return inherited environment variables with APP_KEY/APP_PREVIOUS_KEYS stripped so
     * target project .env files are evaluated directly rather than masked by parent test env.
     *
     * @return array<string, string>
     */
    private static function cleanChildEnvironment(): array
    {
        $env = getenv();
        if (! is_array($env)) {
            return [];
        }

        $filtered = [];
        foreach ($env as $name => $value) {
            if (! is_string($name) || ! is_string($value) || str_starts_with($name, '=')) {
                continue;
            }

            $upper = strtoupper($name);
            if ($upper === 'APP_KEY' || $upper === 'APP_PREVIOUS_KEYS') {
                continue;
            }

            $filtered[$name] = $value;
        }

        return $filtered;
    }

    /**
     * @param  array<string, string|null>  $parsed
     */
    private static function resolveCipher(array $parsed): string
    {
        $cipher = $parsed['APP_CIPHER'] ?? null;

        return (is_string($cipher) && $cipher !== '') ? $cipher : 'AES-256-CBC';
    }

    /**
     * Resolve relative configured database paths against the target base path when provided.
     */
    private static function resolvePath(string $path, ?string $basePath): string
    {
        if ($basePath === null || self::isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($basePath, '\\/').DIRECTORY_SEPARATOR.ltrim($path, '\\/');
    }

    /**
     * Determine whether a filesystem path is absolute on Unix or Windows.
     */
    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
