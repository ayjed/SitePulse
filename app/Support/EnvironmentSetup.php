<?php

namespace App\Support;

use Dotenv\Dotenv;
use Dotenv\Parser\EntryParser;
use Dotenv\Parser\Lines;
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
            $originalContents = self::readRawFile($envPath);
            $parsedEnv = Dotenv::parse($originalContents);
        } catch (Throwable) {
            fwrite(STDERR, "Invalid .env file syntax; aborting setup without modifying .env.\n");

            return 1;
        }

        $cipher = self::resolveCipher($parsedEnv);
        $rawKey = $parsedEnv['APP_KEY'] ?? null;

        if ($rawKey === null || $rawKey === '') {
            $generatedKey = self::generateArtisanAppKey($basePath);
            if ($generatedKey === null || ! self::isValidAppKey($generatedKey, $cipher)) {
                fwrite(STDERR, "Setup failed: unable to generate a valid APP_KEY.\n");

                return 1;
            }

            $proposedContents = self::buildUpdatedEnvWithAppKey($originalContents, $generatedKey);
            if ($proposedContents === null || ! self::verifyProposedEnvPreservesUnrelatedVariables($parsedEnv, $proposedContents, $generatedKey, $cipher)) {
                fwrite(STDERR, "Setup failed: unable to safely update top-level APP_KEY without altering unrelated .env entries.\n");

                return 1;
            }

            if (! self::writeFileAtomically($envPath, $proposedContents)) {
                fwrite(STDERR, "Setup failed: unable to atomically write .env.\n");

                return 1;
            }

            if (self::verifyApplicationEncryption($basePath) !== 0) {
                fwrite(STDERR, "Setup failed: Laravel encryption service could not initialize with generated APP_KEY.\n");

                return 1;
            }

            echo "Application key set successfully.\n";
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
        return Dotenv::parse(self::readRawFile($envPath));
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

        $autoloadPath = self::resolveAutoloadPath($basePath);
        if ($autoloadPath === null) {
            fwrite(STDERR, "Missing Composer autoload.php for encryption verification.\n");

            return 1;
        }

        $probeScript = <<<'PHP'
try {
    require $argv[2];
    $app = require $argv[1] . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $encrypter = $app->make('encrypter');
    $payload = $encrypter->encrypt('sitepulse-key-verification');
    if ($encrypter->decrypt($payload) !== 'sitepulse-key-verification') {
        exit(1);
    }
} catch (\Throwable) {
    exit(1);
}
PHP;

        [$exitCode] = self::runSubprocess(
            [PHP_BINARY, '-r', $probeScript, $basePath, $autoloadPath],
            $basePath
        );

        return $exitCode;
    }

    /**
     * Generate a key string via Laravel's genuine Artisan key:generate --show command without mutating .env.
     */
    protected static function generateArtisanAppKey(string $basePath): ?string
    {
        $artisanPath = $basePath.DIRECTORY_SEPARATOR.'artisan';
        if (! is_file($artisanPath)) {
            fwrite(STDERR, "Missing artisan entrypoint in {$basePath}.\n");

            return null;
        }

        [$exitCode, $stdout] = self::runSubprocess(
            [PHP_BINARY, $artisanPath, 'key:generate', '--show', '--no-ansi'],
            $basePath
        );

        if ($exitCode !== 0) {
            return null;
        }

        $candidate = trim($stdout);

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * Update only top-level APP_KEY assignment chunk(s) (never lines inside multiline values or comments),
     * or append a top-level APP_KEY assignment if none exists.
     */
    private static function buildUpdatedEnvWithAppKey(string $contents, string $newKey): ?string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $hadTrailingNewline = preg_match('/(?:\r\n|\n|\r)\z/', $contents) === 1;

        $lines = preg_split("/\r\n|\n|\r/", $contents);
        if ($lines === false) {
            return null;
        }

        if ($hadTrailingNewline && end($lines) === '') {
            array_pop($lines);
        } elseif (! $hadTrailingNewline && $contents === '') {
            $lines = [];
        }

        $chunks = self::groupDotenvChunks($lines);
        if ($chunks === null) {
            return null;
        }

        $outputLines = [];
        $replacedTopLevel = false;

        foreach ($chunks as $chunk) {
            if ($chunk['type'] === 'entry') {
                $parsedEntry = EntryParser::parse($chunk['raw']);
                if ($parsedEntry->error()->isDefined()) {
                    return null;
                }

                if ($parsedEntry->success()->get()->getName() === 'APP_KEY') {
                    $outputLines[] = 'APP_KEY='.$newKey;
                    $replacedTopLevel = true;

                    continue;
                }
            }

            foreach ($chunk['lines'] as $line) {
                $outputLines[] = $line;
            }
        }

        if (! $replacedTopLevel) {
            $outputLines[] = 'APP_KEY='.$newKey;
        }

        $updated = implode($eol, $outputLines);
        if ($hadTrailingNewline || $updated !== '') {
            $updated .= $eol;
        }

        return $updated;
    }

    /**
     * Group file lines into Dotenv-aware top-level chunks while preserving trivia/comment lines verbatim.
     *
     * @param  list<string>  $lines
     * @return list<array{type: 'entry'|'trivia', lines: list<string>, raw: string}>|null
     */
    private static function groupDotenvChunks(array $lines): ?array
    {
        $chunks = [];
        $inMultiline = false;
        $multilineLines = [];

        foreach ($lines as $line) {
            $startsOnCurrentLine = $inMultiline ? false : self::looksLikeMultilineStart($line);

            if ($startsOnCurrentLine) {
                $inMultiline = true;
                $multilineLines = [$line];

                continue;
            }

            if ($inMultiline) {
                $multilineLines[] = $line;

                if (self::looksLikeMultilineStop($line, false)) {
                    $inMultiline = false;
                    $chunks[] = [
                        'type' => 'entry',
                        'lines' => $multilineLines,
                        'raw' => implode("\n", $multilineLines),
                    ];
                    $multilineLines = [];
                }

                continue;
            }

            if (self::isCommentOrWhitespace($line)) {
                $chunks[] = [
                    'type' => 'trivia',
                    'lines' => [$line],
                    'raw' => $line,
                ];
            } else {
                $chunks[] = [
                    'type' => 'entry',
                    'lines' => [$line],
                    'raw' => $line,
                ];
            }
        }

        if ($inMultiline) {
            return null;
        }

        return $chunks;
    }

    /**
     * Mirror Dotenv\Parser\Lines::looksLikeMultilineStart for top-level boundary parity.
     */
    private static function looksLikeMultilineStart(string $line): bool
    {
        $pos = strpos($line, '="');
        if ($pos === false) {
            return false;
        }

        $hash = strpos($line, '#');
        if ($hash !== false && $hash < $pos) {
            return false;
        }

        return self::looksLikeMultilineStop($line, true) === false;
    }

    /**
     * Mirror Dotenv\Parser\Lines::looksLikeMultilineStop for top-level boundary parity.
     */
    private static function looksLikeMultilineStop(string $line, bool $started): bool
    {
        $sanitized = str_replace('\\\\', '', $line);
        $count = preg_match_all('/(?<!\\\\)"/', $sanitized);
        if ($count === false) {
            return false;
        }

        return $started ? $count > 1 : $count >= 1;
    }

    /**
     * Mirror Dotenv\Parser\Lines::isCommentOrWhitespace.
     */
    private static function isCommentOrWhitespace(string $line): bool
    {
        $trimmed = trim($line, " \n\r\t\0\x0B");

        return $trimmed === '' || $trimmed[0] === '#';
    }

    /**
     * Verify proposed .env content parses cleanly, defines the exact generated APP_KEY,
     * and leaves every unrelated configuration variable identical.
     *
     * @param  array<string, string|null>  $originalParsed
     */
    private static function verifyProposedEnvPreservesUnrelatedVariables(
        array $originalParsed,
        string $proposedContents,
        string $expectedKey,
        string $cipher
    ): bool {
        try {
            $proposedParsed = Dotenv::parse($proposedContents);
        } catch (Throwable) {
            return false;
        }

        $actualKey = $proposedParsed['APP_KEY'] ?? null;
        if (! is_string($actualKey) || $actualKey !== $expectedKey || ! self::isValidAppKey($actualKey, $cipher)) {
            return false;
        }

        unset($originalParsed['APP_KEY'], $proposedParsed['APP_KEY']);

        return $proposedParsed === $originalParsed;
    }

    /**
     * Atomically write content via a temp file in the destination directory, leaving the
     * target file byte-for-byte unchanged on any failure.
     */
    private static function writeFileAtomically(string $targetPath, string $contents): bool
    {
        $directory = dirname($targetPath);
        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        if (is_file($targetPath) && ! is_writable($targetPath)) {
            return false;
        }

        $tempPath = @tempnam($directory, '.env.tmp.');
        if (! is_string($tempPath) || $tempPath === '') {
            return false;
        }

        try {
            $handle = @fopen($tempPath, 'wb');
            if ($handle === false) {
                return false;
            }

            $written = fwrite($handle, $contents);
            $flushed = fflush($handle);
            $closed = fclose($handle);

            if ($written !== strlen($contents) || ! $flushed || ! $closed) {
                return false;
            }

            $existingPerms = @fileperms($targetPath);
            if ($existingPerms !== false) {
                @chmod($tempPath, $existingPerms & 0777);
            }

            if (! @rename($tempPath, $targetPath)) {
                return false;
            }

            $tempPath = '';

            return true;
        } finally {
            if ($tempPath !== '' && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Execute a subprocess via argument array so special path characters (such as spaces and '!')
     * bypass shell interpolation on Windows and Unix hosts without leaking child output.
     *
     * @param  list<string>  $command
     * @return array{int, string}
     */
    private static function runSubprocess(array $command, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, self::cleanChildEnvironment());
        if (! is_resource($process)) {
            return [1, ''];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, is_string($stdout) ? $stdout : ''];
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

    private static function readRawFile(string $envPath): string
    {
        if (! is_file($envPath) || ! is_readable($envPath)) {
            throw new RuntimeException("Unable to read .env file at {$envPath}.");
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read .env file at {$envPath}.");
        }

        return $contents;
    }

    private static function resolveAutoloadPath(string $basePath): ?string
    {
        $localAutoload = $basePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
        if (is_file($localAutoload)) {
            return $localAutoload;
        }

        $repoAutoload = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

        return is_file($repoAutoload) ? $repoAutoload : null;
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
