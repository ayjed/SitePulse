<?php

namespace App\Support;

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

            if (! copy($examplePath, $envPath)) {
                fwrite(STDERR, "Unable to copy .env.example to .env.\n");

                return 1;
            }

            echo "Created .env from .env.example.\n";
        }

        if (self::hasAppKey($envPath)) {
            echo "Existing APP_KEY detected; preserving current key.\n";
        } else {
            self::ensureAppKeyPlaceholder($envPath);

            $exitCode = self::runArtisanKeyGenerate($basePath);
            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        $sqlitePath = $basePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
        self::ensureSqliteDatabase($envPath, $sqlitePath, $basePath);

        return 0;
    }

    /**
     * Determine whether the given .env file already defines a non-empty APP_KEY.
     */
    public static function hasAppKey(string $envPath): bool
    {
        $value = self::readEnvValue($envPath, 'APP_KEY');

        return $value !== null && $value !== '';
    }

    /**
     * Ensure an APP_KEY= entry exists in .env so Artisan key:generate can populate it cleanly.
     */
    public static function ensureAppKeyPlaceholder(string $envPath): void
    {
        if (! is_file($envPath)) {
            return;
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return;
        }

        if (preg_match('/^\s*(?:export\s+)?APP_KEY\s*=/m', $contents) === 1) {
            return;
        }

        $separator = ($contents === '' || str_ends_with($contents, "\n")) ? '' : PHP_EOL;
        file_put_contents($envPath, $contents.$separator.'APP_KEY='.PHP_EOL);
    }

    /**
     * Create the SQLite database file if the environment uses file-backed SQLite.
     */
    public static function ensureSqliteDatabase(string $envPath, string $defaultSqlitePath, ?string $basePath = null): bool
    {
        $connection = self::readEnvValue($envPath, 'DB_CONNECTION') ?? 'sqlite';
        if (strtolower($connection) !== 'sqlite') {
            return false;
        }

        $configuredDatabase = self::readEnvValue($envPath, 'DB_DATABASE');
        if ($configuredDatabase === ':memory:') {
            return false;
        }

        $targetPath = $defaultSqlitePath;
        if ($configuredDatabase !== null && $configuredDatabase !== '') {
            $targetPath = self::resolvePath($configuredDatabase, $basePath);
        }

        $directory = dirname($targetPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (! file_exists($targetPath)) {
            touch($targetPath);
            echo "Created SQLite database file at {$targetPath}.\n";

            return true;
        }

        return false;
    }

    /**
     * Read an unexpanded variable value from a .env file.
     */
    public static function readEnvValue(string $envPath, string $key): ?string
    {
        if (! is_file($envPath)) {
            return null;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $quotedKey = preg_quote($key, '/');
        $resolved = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?'.$quotedKey.'\s*=\s*(.*)$/', $trimmed, $matches) !== 1) {
                continue;
            }

            $rawValue = trim($matches[1]);
            $resolved = self::unquoteValue($rawValue);
        }

        return $resolved;
    }

    /**
     * Execute Laravel's key:generate command non-interactively in the target base directory.
     */
    protected static function runArtisanKeyGenerate(string $basePath): int
    {
        $command = sprintf(
            '%s %s key:generate --force --ansi',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($basePath.DIRECTORY_SEPARATOR.'artisan')
        );

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptors, $pipes, $basePath);
        if (! is_resource($process)) {
            fwrite(STDERR, "Failed to start key:generate subprocess.\n");

            return 1;
        }

        return proc_close($process);
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

    /**
     * Strip surrounding quotes or trailing inline comments from a .env value.
     */
    private static function unquoteValue(string $rawValue): string
    {
        if ($rawValue === '') {
            return '';
        }

        $firstChar = $rawValue[0];
        if ($firstChar === '"' || $firstChar === "'") {
            $closingPos = strpos($rawValue, $firstChar, 1);
            if ($closingPos !== false) {
                return substr($rawValue, 1, $closingPos - 1);
            }
        }

        $commentPos = strpos($rawValue, ' #');
        if ($commentPos !== false) {
            $rawValue = substr($rawValue, 0, $commentPos);
        }

        return trim($rawValue);
    }
}
