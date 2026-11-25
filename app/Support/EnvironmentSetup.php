<?php

namespace App\Support;

use Dotenv\Dotenv;
use Dotenv\Parser\EntryParser;
use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Throwable;

class EnvironmentSetup
{
    private const TEMP_ENV_PREFIX = '.env.tmp.';

    private const RECOVERY_ENV_PREFIX = '.env.recovery.';

    /**
     * Prepare the local .env file, application encryption key, and SQLite database idempotently.
     *
     * Supported filesystem assumptions:
     * - Target `.env` (and `.env.example` when seeding) must be regular, non-symlink files with link count <= 1.
     * - Atomic same-directory rename requires write permission on the parent directory (and non-readonly target on Windows).
     * - POSIX `0600` mode restrictions apply on Unix-like systems; on Windows `chmod()` only toggles the DOS read-only bit
     *   and directory NTFS ACLs govern read/write access.
     *
     * @param  null|callable(string, string): (int|bool)  $postPublishVerifier  Optional test seam invoked only after `.env` publication.
     */
    public static function prepare(string $basePath, ?callable $postPublishVerifier = null): int
    {
        $basePath = rtrim($basePath, '\\/');
        $envPath = $basePath.DIRECTORY_SEPARATOR.'.env';
        $examplePath = $basePath.DIRECTORY_SEPARATOR.'.env.example';
        $sqlitePath = $basePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';

        if (! self::assertSafeDestinationPath($envPath, '.env')) {
            return 1;
        }

        clearstatcache(true, $envPath);
        $targetExistedInitially = file_exists($envPath);
        $originalContents = null;

        if ($targetExistedInitially) {
            try {
                $originalContents = self::readRawFile($envPath);
                $sourceContents = $originalContents;
            } catch (Throwable) {
                fwrite(STDERR, "Setup failed: unable to read .env without modifying target configuration.\n");

                return 1;
            }
        } else {
            if (! self::assertSafeSourceFile($examplePath, '.env.example')) {
                return 1;
            }

            try {
                $sourceContents = self::readRawFile($examplePath);
            } catch (Throwable) {
                fwrite(STDERR, "Setup failed: unable to read .env.example.\n");

                return 1;
            }
        }

        try {
            $parsedEnv = Dotenv::parse($sourceContents);
        } catch (Throwable) {
            fwrite(STDERR, "Invalid .env file syntax; aborting setup without modifying .env.\n");

            return 1;
        }

        $cipher = self::resolveCipher($parsedEnv);
        $rawKey = $parsedEnv['APP_KEY'] ?? null;
        $needsNewKey = $rawKey === null || $rawKey === '';
        $blockingVariant = self::resolveActiveEnvVariant($basePath);
        $cacheInfo = self::inspectConfigurationCaches($basePath, $parsedEnv, $blockingVariant);

        if ($cacheInfo === null) {
            return 1;
        }

        if (! $needsNewKey) {
            if (! self::isValidAppKey($rawKey, $cipher)) {
                fwrite(STDERR, "Existing APP_KEY in .env is invalid for cipher {$cipher}; .env was left unchanged.\n");

                return 1;
            }

            if ($cacheInfo['processPath'] !== null
                && $cacheInfo['dotenvPath'] !== null
                && $cacheInfo['processPath'] !== $cacheInfo['dotenvPath']
                && $cacheInfo['existingPaths'] !== []) {
                fwrite(
                    STDERR,
                    "Setup failed: conflicting configuration cache targets between process APP_CONFIG_CACHE and .env while cache files exist; .env and cached config were left unchanged.\n"
                );

                return 1;
            }

            if (count($cacheInfo['existingPaths']) > 1) {
                fwrite(
                    STDERR,
                    "Setup failed: multiple configuration cache files exist; clear stale caches with `php artisan config:clear` and retry setup.\n"
                );

                return 1;
            }

            if (self::verifyEffectiveEncryption($basePath, $rawKey, $cipher) !== 0) {
                fwrite(STDERR, "Existing APP_KEY in .env does not match Laravel's effective encryption key (check active .env.<APP_ENV> overrides or run `php artisan config:clear`); .env and unrelated environment files were left unchanged.\n");

                return 1;
            }

            if ($targetExistedInitially) {
                echo "Existing APP_KEY detected; preserving current key.\n";

                return self::runPostPublishSteps(
                    $basePath,
                    $envPath,
                    $sqlitePath,
                    $parsedEnv,
                    false,
                    true,
                    $originalContents,
                    $postPublishVerifier
                );
            }

            if (! self::writeFileAtomically($envPath, $sourceContents)) {
                fwrite(STDERR, "Setup failed: unable to publish initial .env from .env.example.\n");

                return 1;
            }

            $createdCode = self::runPostPublishSteps(
                $basePath,
                $envPath,
                $sqlitePath,
                $parsedEnv,
                true,
                false,
                null,
                $postPublishVerifier
            );

            if ($createdCode === 0) {
                echo "Created .env from .env.example.\n";
            }

            return $createdCode;
        }

        if ($blockingVariant !== null) {
            $activeEnv = self::readProcessEnv('APP_ENV');
            fwrite(
                STDERR,
                "Setup failed: {$blockingVariant} overrides .env while APP_ENV={$activeEnv} is active; .env and {$blockingVariant} were left unchanged.\n"
            );

            return 1;
        }

        if ($cacheInfo['existingPaths'] !== []) {
            $cacheList = implode(', ', $cacheInfo['existingPaths']);
            fwrite(
                STDERR,
                "Setup failed: Laravel configuration cache exists at {$cacheList} and overrides .env. Run `php artisan config:clear` and retry setup; .env and cached config were left unchanged.\n"
            );

            return 1;
        }

        $publishResult = self::stageVerifyAndPublishNewKey(
            $basePath,
            $envPath,
            $sourceContents,
            $parsedEnv,
            $cipher
        );

        if ($publishResult === null) {
            return 1;
        }

        $exitCode = self::runPostPublishSteps(
            $basePath,
            $envPath,
            $sqlitePath,
            $publishResult,
            true,
            $targetExistedInitially,
            $originalContents,
            $postPublishVerifier
        );

        if ($exitCode === 0) {
            if (! $targetExistedInitially) {
                echo "Created .env from .env.example.\n";
            }

            echo "Application key set successfully.\n";
        }

        return $exitCode;
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
        return self::decodeAppKeyMaterial($key, $cipher) !== null;
    }

    /**
     * Create an exclusive `.env.tmp.<24-hex>` file in the given directory (avoiding Windows
     * tempnam() 3-character `.enXXXX.tmp` prefix truncation) so `.gitignore`'s `.env.*` matches it.
     *
     * @return array{0: string, 1: resource}|null
     */
    public static function createExclusiveTempEnvFile(string $directory): ?array
    {
        return self::createExclusivePrefixedFile($directory, self::TEMP_ENV_PREFIX);
    }

    /**
     * Create an exclusive `.env.recovery.<24-hex>` file covered by `.gitignore`'s `.env.*` rule.
     *
     * @return array{0: string, 1: resource}|null
     */
    public static function createExclusiveRecoveryEnvFile(string $directory): ?array
    {
        return self::createExclusivePrefixedFile($directory, self::RECOVERY_ENV_PREFIX);
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

        clearstatcache(true, $targetPath);
        if (is_link($targetPath)) {
            throw new RuntimeException("SQLite database path {$targetPath} must not be a symbolic link.");
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
     * Verify in a fresh subprocess that Laravel boots and initializes encryption using the
     * exact APP_KEY defined in `.env` (neutralizing inherited APP_KEY/APP_CIPHER overrides).
     */
    public static function verifyApplicationEncryption(string $basePath): int
    {
        $basePath = rtrim($basePath, '\\/');
        $envPath = $basePath.DIRECTORY_SEPARATOR.'.env';

        if (! self::assertSafeDestinationPath($envPath, '.env', mustExist: true)) {
            return 1;
        }

        try {
            $parsed = self::parseEnvFile($envPath);
        } catch (Throwable) {
            return 1;
        }

        $key = $parsed['APP_KEY'] ?? null;
        $cipher = self::resolveCipher($parsed);
        if (! is_string($key) || ! self::isValidAppKey($key, $cipher)) {
            return 1;
        }

        $cacheInfo = self::inspectConfigurationCaches($basePath, $parsed, self::resolveActiveEnvVariant($basePath));
        if ($cacheInfo === null || count($cacheInfo['existingPaths']) > 1) {
            return 1;
        }

        if ($cacheInfo['processPath'] !== null
            && $cacheInfo['dotenvPath'] !== null
            && $cacheInfo['processPath'] !== $cacheInfo['dotenvPath']
            && $cacheInfo['existingPaths'] !== []) {
            return 1;
        }

        return self::verifyEffectiveEncryption($basePath, $key, $cipher);
    }

    /**
     * Generate, stage, verify in a bound Laravel subprocess, and atomically publish a new APP_KEY.
     *
     * @param  array<string, string|null>  $parsedSourceEnv
     * @return array<string, string|null>|null
     */
    private static function stageVerifyAndPublishNewKey(
        string $basePath,
        string $envPath,
        string $sourceContents,
        array $parsedSourceEnv,
        string $cipher
    ): ?array {
        $generatedKey = self::generateArtisanAppKey($basePath);
        if ($generatedKey === null || ! self::isValidAppKey($generatedKey, $cipher)) {
            fwrite(STDERR, "Setup failed: unable to generate a valid APP_KEY.\n");

            return null;
        }

        $proposedContents = self::buildUpdatedEnvWithAppKey($sourceContents, $generatedKey);
        $proposedParsed = $proposedContents !== null
            ? self::parseAndVerifyProposedEnv($parsedSourceEnv, $proposedContents, $generatedKey, $cipher)
            : null;

        if ($proposedContents === null || $proposedParsed === null) {
            fwrite(STDERR, "Setup failed: unable to safely update top-level APP_KEY without altering unrelated .env entries.\n");

            return null;
        }

        $directory = dirname($envPath);
        clearstatcache(true, $envPath);
        if (is_file($envPath) && ! is_writable($envPath)) {
            fwrite(STDERR, "Setup failed: .env is not writable.\n");

            return null;
        }

        $tempCreated = self::createExclusiveTempEnvFile($directory);
        if ($tempCreated === null) {
            fwrite(STDERR, "Setup failed: unable to create exclusive temporary .env file.\n");

            return null;
        }

        [$stagedPath, $handle] = $tempCreated;

        try {
            if (! self::writeAndCloseTempHandle($handle, $proposedContents)) {
                fwrite(STDERR, "Setup failed: unable to stage temporary .env file.\n");

                return null;
            }

            if (self::verifyBoundStagedEncryption($basePath, basename($stagedPath), $generatedKey, $cipher) !== 0) {
                fwrite(STDERR, "Setup failed: candidate APP_KEY failed Laravel encryption verification; .env was left unchanged.\n");

                return null;
            }

            if (! self::assertSafeDestinationPath($envPath, '.env')) {
                return null;
            }

            self::applyPublishedPermissions($stagedPath, $envPath);

            if (! @rename($stagedPath, $envPath)) {
                fwrite(STDERR, "Setup failed: unable to atomically publish .env.\n");

                return null;
            }

            $stagedPath = '';

            return $proposedParsed;
        } finally {
            if ($stagedPath !== '' && is_file($stagedPath)) {
                @unlink($stagedPath);
            }
        }
    }

    /**
     * Execute post-publication steps and run verified rollback if `.env` was modified during setup.
     *
     * @param  array<string, string|null>  $targetParsedEnv
     * @param  null|callable(string, string): (int|bool)  $postPublishVerifier
     */
    private static function runPostPublishSteps(
        string $basePath,
        string $envPath,
        string $sqlitePath,
        array $targetParsedEnv,
        bool $envWasPublished,
        bool $targetExistedInitially,
        ?string $originalContents,
        ?callable $postPublishVerifier
    ): int {
        $failureReason = null;

        try {
            self::ensureSqliteDatabase($envPath, $sqlitePath, $basePath, $targetParsedEnv);
        } catch (RuntimeException $e) {
            $failureReason = $e->getMessage();
        }

        if ($failureReason === null && $postPublishVerifier !== null) {
            try {
                $hookResult = $postPublishVerifier($basePath, $envPath);
                if ($hookResult === false || (is_int($hookResult) && $hookResult !== 0)) {
                    $failureReason = 'Post-publication verification check failed.';
                }
            } catch (Throwable) {
                $failureReason = 'Post-publication verification check threw an exception.';
            }
        }

        if ($failureReason === null) {
            return 0;
        }

        if (! $envWasPublished) {
            fwrite(STDERR, $failureReason."\n");

            return 1;
        }

        self::rollbackPublishedTarget($envPath, $targetExistedInitially, $originalContents, $failureReason);

        return 1;
    }

    /**
     * Restore the target `.env` state after a post-publication failure and verify the resulting bytes.
     * Never reports successful restoration unless disk state is actually verified recovered.
     */
    private static function rollbackPublishedTarget(
        string $envPath,
        bool $targetExistedInitially,
        ?string $originalContents,
        string $failureReason
    ): void {
        if (! $targetExistedInitially || $originalContents === null) {
            clearstatcache(true, $envPath);
            if (! is_link($envPath) && file_exists($envPath)) {
                @unlink($envPath);
            }

            clearstatcache(true, $envPath);
            if (! file_exists($envPath) && ! is_link($envPath)) {
                fwrite(STDERR, "Setup failed ({$failureReason}); newly created .env was verified removed.\n");

                return;
            }

            fwrite(
                STDERR,
                "Setup failed ({$failureReason}); rollback failed: newly created .env could not be removed and remains on disk at {$envPath}.\n"
            );

            return;
        }

        $restoredWrite = self::writeFileAtomically($envPath, $originalContents);
        clearstatcache(true, $envPath);
        $currentBytes = self::readRawFileOrNull($envPath);

        if ($restoredWrite && $currentBytes === $originalContents) {
            fwrite(STDERR, "Setup failed ({$failureReason}); original .env bytes were verified restored.\n");

            return;
        }

        $recoveryPath = self::preserveRecoveryCopy(dirname($envPath), $originalContents);
        $remainingState = $currentBytes === null
            ? "target .env at {$envPath} is unreadable or missing after failed rollback"
            : "modified configuration remains on disk at {$envPath}";

        $recoveryDetail = $recoveryPath !== null
            ? " Protected recovery copy of original .env was preserved at {$recoveryPath}."
            : ' Protected recovery copy could not be written.';

        fwrite(
            STDERR,
            "Setup failed ({$failureReason}); rollback failed: {$remainingState}.{$recoveryDetail}\n"
        );
    }

    /**
     * Persist an exclusive recovery file containing the original `.env` bytes when rollback fails.
     */
    private static function preserveRecoveryCopy(string $preferredDirectory, string $originalContents): ?string
    {
        $candidates = [
            [$preferredDirectory, self::RECOVERY_ENV_PREFIX],
            [sys_get_temp_dir(), 'sitepulse-.env-recovery.'],
        ];

        foreach ($candidates as [$directory, $prefix]) {
            $created = self::createExclusivePrefixedFile($directory, $prefix);
            if ($created === null) {
                continue;
            }

            [$recoveryPath, $handle] = $created;
            if (! self::writeAndCloseTempHandle($handle, $originalContents)) {
                @unlink($recoveryPath);

                continue;
            }

            @chmod($recoveryPath, 0600);
            clearstatcache(true, $recoveryPath);
            if (self::readRawFileOrNull($recoveryPath) === $originalContents) {
                return $recoveryPath;
            }

            @unlink($recoveryPath);
        }

        return null;
    }

    private static function verifyBoundStagedEncryption(
        string $basePath,
        string $stagedBasename,
        string $expectedKey,
        string $cipher
    ): int {
        return self::runLaravelKeyVerificationProbe(
            $basePath,
            $expectedKey,
            $cipher,
            loadEnvFile: $stagedBasename,
            expectedEnvFile: $stagedBasename,
            bypassConfigCache: true,
            neutralizeAppEnv: true
        );
    }

    private static function verifyEffectiveEncryption(
        string $basePath,
        string $expectedKey,
        string $cipher
    ): int {
        return self::runLaravelKeyVerificationProbe(
            $basePath,
            $expectedKey,
            $cipher,
            loadEnvFile: null,
            expectedEnvFile: '.env',
            bypassConfigCache: false,
            neutralizeAppEnv: false
        );
    }

    private static function runLaravelKeyVerificationProbe(
        string $basePath,
        string $expectedKey,
        string $cipher,
        ?string $loadEnvFile,
        ?string $expectedEnvFile,
        bool $bypassConfigCache,
        bool $neutralizeAppEnv
    ): int {
        $rawMaterial = self::decodeAppKeyMaterial($expectedKey, $cipher);
        if ($rawMaterial === null) {
            return 1;
        }

        $bootstrapApp = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        $autoloadPath = self::resolveAutoloadPath($basePath);
        if (! is_file($bootstrapApp) || $autoloadPath === null) {
            return 1;
        }

        $probePayload = json_encode([
            'configKeyHash' => hash('sha256', $expectedKey),
            'rawKeyHash' => hash('sha256', $rawMaterial),
            'cipher' => $cipher,
            'loadEnvFile' => $loadEnvFile ?? '',
            'expectedEnvFile' => $expectedEnvFile ?? '',
        ]);

        if (! is_string($probePayload)) {
            return 1;
        }

        $probeScript = <<<'PHP'
try {
    $input = json_decode((string) stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    require $argv[2];
    $app = require $argv[1].DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
    if (($input['loadEnvFile'] ?? '') !== '') {
        $app->loadEnvironmentFrom((string) $input['loadEnvFile']);
    }
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->configurationIsCached()
        && ($input['expectedEnvFile'] ?? '') !== ''
        && $app->environmentFile() !== (string) $input['expectedEnvFile']) {
        exit(11);
    }
    $config = $app->make('config')->get('app');
    if (! is_array($config)
        || strcasecmp((string) ($config['cipher'] ?? ''), (string) $input['cipher']) !== 0
        || ! hash_equals((string) $input['configKeyHash'], hash('sha256', (string) ($config['key'] ?? '')))) {
        exit(12);
    }
    $encrypter = $app->make('encrypter');
    $actualRawKey = method_exists($encrypter, 'getKey') ? $encrypter->getKey() : null;
    if (! is_string($actualRawKey)
        || ! hash_equals((string) $input['rawKeyHash'], hash('sha256', $actualRawKey))) {
        exit(13);
    }
    $payload = $encrypter->encrypt('sitepulse-key-verification');
    if ($encrypter->decrypt($payload) !== 'sitepulse-key-verification') {
        exit(14);
    }
} catch (\Throwable) {
    exit(1);
}
PHP;

        $extraEnv = [];
        if ($bypassConfigCache) {
            $extraEnv['APP_CONFIG_CACHE'] = 'bootstrap/cache/.sitepulse-probe-nocache-'.bin2hex(random_bytes(6)).'.php';
        } elseif (($activeConfigCache = self::readProcessEnv('APP_CONFIG_CACHE')) !== null) {
            $extraEnv['APP_CONFIG_CACHE'] = $activeConfigCache;
        }

        if (! $neutralizeAppEnv && ($activeAppEnv = self::readProcessEnv('APP_ENV')) !== null) {
            $extraEnv['APP_ENV'] = $activeAppEnv;
        }

        [$exitCode] = self::runSubprocess(
            [PHP_BINARY, '-r', $probeScript, $basePath, $autoloadPath],
            $basePath,
            $extraEnv,
            $probePayload
        );

        return $exitCode;
    }

    /**
     * Generate a key string via Laravel's genuine Artisan `key:generate --show` without mutating files.
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
            $basePath,
            ['APP_CONFIG_CACHE' => 'bootstrap/cache/.sitepulse-keygen-nocache-'.bin2hex(random_bytes(6)).'.php']
        );

        if ($exitCode !== 0) {
            return null;
        }

        $candidate = trim($stdout);

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * Decode and validate raw encryption key material matching EncryptionServiceProvider::parseKey().
     */
    private static function decodeAppKeyMaterial(string $key, string $cipher = 'AES-256-CBC'): ?string
    {
        if ($key === '') {
            return null;
        }

        $material = $key;
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                return null;
            }
            $material = $decoded;
        }

        if (! Encrypter::supported($material, $cipher)) {
            return null;
        }

        try {
            new Encrypter($material, $cipher);

            return $material;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: resource}|null
     */
    private static function createExclusivePrefixedFile(string $directory, string $prefix): ?array
    {
        if (! is_dir($directory) || ! is_writable($directory)) {
            return null;
        }

        for ($attempt = 0; $attempt < 16; $attempt++) {
            $candidatePath = $directory.DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(12));

            clearstatcache(true, $candidatePath);
            if (is_link($candidatePath) || file_exists($candidatePath)) {
                continue;
            }

            $handle = @fopen($candidatePath, 'xb');
            if (is_resource($handle)) {
                @chmod($candidatePath, 0600);

                return [$candidatePath, $handle];
            }
        }

        return null;
    }

    /**
     * Verify destination `.env` path is not a symlink, non-regular file, or multi-hardlinked inode.
     */
    private static function assertSafeDestinationPath(string $path, string $label, bool $mustExist = false): bool
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            fwrite(STDERR, "Setup failed: destination {$label} must not be a symbolic link.\n");

            return false;
        }

        if (! file_exists($path)) {
            if ($mustExist) {
                fwrite(STDERR, "Setup failed: {$label} does not exist.\n");

                return false;
            }

            return true;
        }

        $stat = @lstat($path);
        if (! is_array($stat)) {
            fwrite(STDERR, "Setup failed: unable to inspect {$label} metadata.\n");

            return false;
        }

        if ((($stat['mode'] ?? 0) & 0170000) !== 0100000) {
            fwrite(STDERR, "Setup failed: destination {$label} is not a regular file.\n");

            return false;
        }

        if (($stat['nlink'] ?? 1) > 1) {
            fwrite(STDERR, "Setup failed: destination {$label} has multiple hard links, which is unsupported.\n");

            return false;
        }

        return true;
    }

    private static function assertSafeSourceFile(string $path, string $label): bool
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            fwrite(STDERR, "Setup failed: source {$label} must not be a symbolic link.\n");

            return false;
        }

        if (! is_file($path) || ! is_readable($path)) {
            fwrite(STDERR, "Missing or unreadable {$label}.\n");

            return false;
        }

        $stat = @lstat($path);
        if (! is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
            fwrite(STDERR, "Setup failed: source {$label} is not a regular file.\n");

            return false;
        }

        return true;
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
     * Verify proposed .env content parses cleanly, sets the exact candidate APP_KEY,
     * and leaves every unrelated variable identical.
     *
     * @param  array<string, string|null>  $originalParsed
     * @return array<string, string|null>|null
     */
    private static function parseAndVerifyProposedEnv(
        array $originalParsed,
        string $proposedContents,
        string $expectedKey,
        string $cipher
    ): ?array {
        try {
            $proposedParsed = Dotenv::parse($proposedContents);
        } catch (Throwable) {
            return null;
        }

        $actualKey = $proposedParsed['APP_KEY'] ?? null;
        if (! is_string($actualKey) || $actualKey !== $expectedKey || ! self::isValidAppKey($actualKey, $cipher)) {
            return null;
        }

        $origWithoutKey = $originalParsed;
        $propWithoutKey = $proposedParsed;
        unset($origWithoutKey['APP_KEY'], $propWithoutKey['APP_KEY']);

        return $propWithoutKey === $origWithoutKey ? $proposedParsed : null;
    }

    /**
     * Atomically write content via an exclusive `.env.tmp.*` file in the destination directory,
     * leaving the target file byte-for-byte unchanged on any failure.
     */
    private static function writeFileAtomically(string $targetPath, string $contents): bool
    {
        if (! self::assertSafeDestinationPath($targetPath, basename($targetPath))) {
            return false;
        }

        $directory = dirname($targetPath);
        clearstatcache(true, $targetPath);
        if (is_file($targetPath) && ! is_writable($targetPath)) {
            return false;
        }

        $created = self::createExclusiveTempEnvFile($directory);
        if ($created === null) {
            return false;
        }

        [$tempPath, $handle] = $created;

        try {
            if (! self::writeAndCloseTempHandle($handle, $contents)) {
                return false;
            }

            self::applyPublishedPermissions($tempPath, $targetPath);

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
     * @param  resource  $handle
     */
    private static function writeAndCloseTempHandle($handle, string $contents): bool
    {
        $written = @fwrite($handle, $contents);
        $flushed = @fflush($handle);
        $closed = @fclose($handle);

        return $written === strlen($contents) && $flushed && $closed;
    }

    private static function applyPublishedPermissions(string $tempPath, string $targetPath): void
    {
        if (is_file($targetPath)) {
            $existingPerms = @fileperms($targetPath);
            if ($existingPerms !== false) {
                @chmod($tempPath, ($existingPerms & 0666) ?: 0600);

                return;
            }
        }

        @chmod($tempPath, 0600);
    }

    /**
     * Detect an active Laravel environment-specific variant file (such as `.env.testing` or `.env.local`)
     * selected by process APP_ENV in LoadEnvironmentVariables::checkForSpecificEnvironmentFile().
     */
    private static function resolveActiveEnvVariant(string $basePath): ?string
    {
        $activeEnv = self::readProcessEnv('APP_ENV');
        if ($activeEnv === null || str_contains($activeEnv, '/') || str_contains($activeEnv, '\\') || $activeEnv === 'example') {
            return null;
        }

        $variantName = '.env.'.$activeEnv;
        $variantPath = $basePath.DIRECTORY_SEPARATOR.$variantName;

        return is_file($variantPath) ? $variantName : null;
    }

    /**
     * Validate and resolve all active configuration cache paths (default, process APP_CONFIG_CACHE,
     * `.env` APP_CONFIG_CACHE, and active variant APP_CONFIG_CACHE). Fails closed on unsafe or ambiguous paths.
     *
     * @param  array<string, string|null>  $parsedEnv
     * @return array{existingPaths: list<string>, processPath: ?string, dotenvPath: ?string}|null
     */
    private static function inspectConfigurationCaches(string $basePath, array $parsedEnv, ?string $variantName = null): ?array
    {
        $defaultPath = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        if (! self::assertSafeCacheFileCandidate($defaultPath, 'bootstrap/cache/config.php')) {
            return null;
        }

        $processRaw = self::readProcessEnvIncludingEmpty('APP_CONFIG_CACHE');
        $processPath = null;
        if ($processRaw !== null) {
            $processPath = self::validateAndResolveCachePath($basePath, $processRaw, 'process APP_CONFIG_CACHE');
            if ($processPath === null) {
                return null;
            }
        }

        $dotenvPath = self::resolveEnvFileCachePath($basePath, $parsedEnv, '.env');
        if ($dotenvPath === false) {
            return null;
        }

        $variantPath = null;
        if ($variantName !== null) {
            try {
                $variantParsed = self::parseEnvFile($basePath.DIRECTORY_SEPARATOR.$variantName);
            } catch (Throwable) {
                fwrite(STDERR, "Setup failed: unable to parse active {$variantName} file.\n");

                return null;
            }

            $variantPath = self::resolveEnvFileCachePath($basePath, $variantParsed, $variantName);
            if ($variantPath === false) {
                return null;
            }
        }

        $candidates = [];
        if ($processPath === null || $processPath === $defaultPath) {
            $candidates[] = $defaultPath;
        }

        foreach ([$processPath, $dotenvPath, $variantPath] as $candidate) {
            if (is_string($candidate) && ! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        $existingPaths = [];
        foreach ($candidates as $candidate) {
            clearstatcache(true, $candidate);
            if (is_file($candidate)) {
                $existingPaths[] = $candidate;
            }
        }

        return [
            'existingPaths' => $existingPaths,
            'processPath' => $processPath,
            'dotenvPath' => $dotenvPath,
        ];
    }

    /**
     * @param  array<string, string|null>  $parsed
     * @return string|false|null False indicates an invalid/unsafe cache declaration; null indicates unset.
     */
    private static function resolveEnvFileCachePath(string $basePath, array $parsed, string $sourceLabel): string|false|null
    {
        if (! array_key_exists('APP_CONFIG_CACHE', $parsed)) {
            return null;
        }

        $normalized = self::normalizeDotenvScalar($parsed['APP_CONFIG_CACHE']);
        if ($normalized === null) {
            return null;
        }

        if (! is_string($normalized)) {
            fwrite(STDERR, "Setup failed: APP_CONFIG_CACHE in {$sourceLabel} has an invalid non-string value.\n");

            return false;
        }

        $resolved = self::validateAndResolveCachePath($basePath, $normalized, "{$sourceLabel} APP_CONFIG_CACHE");

        return $resolved ?? false;
    }

    private static function normalizeDotenvScalar(?string $value): string|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            'true', '(true)' => true,
            'false', '(false)' => false,
            default => $value,
        };
    }

    /**
     * Validate a configured APP_CONFIG_CACHE value against Laravel's path resolution rules
     * and reject traversal, stream wrappers, symlinks, UNC paths, or non-regular cache targets.
     */
    private static function validateAndResolveCachePath(string $basePath, string $rawPath, string $label): ?string
    {
        if (trim($rawPath) === '') {
            fwrite(STDERR, "Setup failed: {$label} must not be empty.\n");

            return null;
        }

        if (str_contains($rawPath, "\0") || preg_match('/[\r\n]/', $rawPath) === 1) {
            fwrite(STDERR, "Setup failed: {$label} contains invalid control characters.\n");

            return null;
        }

        if (str_starts_with($rawPath, '\\\\') || str_starts_with($rawPath, '//')) {
            fwrite(STDERR, "Setup failed: {$label} must not target a UNC network path.\n");

            return null;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $rawPath) === 1) {
            fwrite(STDERR, "Setup failed: {$label} contains an unsupported scheme or drive prefix.\n");

            return null;
        }

        $segments = preg_split('/[\\\\\\/]/', $rawPath);
        if ($segments === false || in_array('..', $segments, true)) {
            fwrite(STDERR, "Setup failed: {$label} must not contain parent-directory traversal.\n");

            return null;
        }

        $isLaravelAbsolute = str_starts_with($rawPath, '/') || str_starts_with($rawPath, '\\');
        $resolved = $isLaravelAbsolute ? $rawPath : self::resolvePath($rawPath, $basePath);

        if (! $isLaravelAbsolute) {
            $baseReal = realpath($basePath);
            $parentDir = dirname($resolved);
            if ($baseReal !== false && is_dir($parentDir)) {
                $parentReal = realpath($parentDir);
                if ($parentReal === false || ($parentReal !== $baseReal && ! str_starts_with($parentReal.DIRECTORY_SEPARATOR, $baseReal.DIRECTORY_SEPARATOR))) {
                    fwrite(STDERR, "Setup failed: relative {$label} resolves outside the application base directory.\n");

                    return null;
                }
            }
        }

        return self::assertSafeCacheFileCandidate($resolved, $label) ? $resolved : null;
    }

    private static function assertSafeCacheFileCandidate(string $path, string $label): bool
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            fwrite(STDERR, "Setup failed: configuration cache {$label} must not be a symbolic link.\n");

            return false;
        }

        if (! file_exists($path)) {
            return true;
        }

        $stat = @lstat($path);
        if (! is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
            fwrite(STDERR, "Setup failed: configuration cache {$label} is not a regular file.\n");

            return false;
        }

        return true;
    }

    private static function readProcessEnvIncludingEmpty(string $name): ?string
    {
        if (array_key_exists($name, $_ENV) && is_string($_ENV[$name])) {
            return $_ENV[$name];
        }

        if (array_key_exists($name, $_SERVER) && is_string($_SERVER[$name])) {
            return $_SERVER[$name];
        }

        $value = getenv($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Execute a subprocess via argument array so special path characters (such as spaces and '!')
     * bypass shell interpolation cleanly without leaking child output.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $extraEnv
     * @return array{int, string}
     */
    private static function runSubprocess(
        array $command,
        string $cwd,
        array $extraEnv = [],
        string $stdin = ''
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, self::cleanChildEnvironment($extraEnv));
        if (! is_resource($process)) {
            return [1, ''];
        }

        if ($stdin !== '') {
            @fwrite($pipes[0], $stdin);
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
     * Neutralize inherited encryption/environment overrides (APP_KEY, APP_PREVIOUS_KEYS, APP_CIPHER,
     * APP_ENV, APP_CONFIG_CACHE) so child verification probes cannot read stale parent secrets or config.
     *
     * @param  array<string, string>  $extraEnv
     * @return array<string, string>
     */
    private static function cleanChildEnvironment(array $extraEnv = []): array
    {
        $stripUpper = [
            'APP_KEY' => true,
            'APP_PREVIOUS_KEYS' => true,
            'APP_CIPHER' => true,
            'APP_ENV' => true,
            'APP_CONFIG_CACHE' => true,
        ];

        $env = getenv();
        if (! is_array($env)) {
            return $extraEnv;
        }

        $filtered = [];
        foreach ($env as $name => $value) {
            if (! is_string($name) || ! is_string($value) || str_starts_with($name, '=')) {
                continue;
            }

            $upper = strtoupper($name);
            if (isset($stripUpper[$upper])) {
                continue;
            }

            $filtered[$name] = $value;
        }

        return array_merge($filtered, $extraEnv);
    }

    private static function readProcessEnv(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
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

    private static function readRawFileOrNull(string $envPath): ?string
    {
        if (! is_file($envPath) || ! is_readable($envPath)) {
            return null;
        }

        $contents = @file_get_contents($envPath);

        return is_string($contents) ? $contents : null;
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

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '\\/'));

        return rtrim($basePath, '\\/').DIRECTORY_SEPARATOR.$normalized;
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
