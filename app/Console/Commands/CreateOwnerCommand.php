<?php

namespace App\Console\Commands;

use App\Exceptions\OwnerAlreadyExistsException;
use App\Support\OwnerProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

/**
 * CLI-only command to provision the initial single-owner administrator account.
 */
class CreateOwnerCommand extends Command
{
    protected $signature = 'sitepulse:create-owner
        {--name= : Display name for the site owner}
        {--email= : Email address for the site owner}
        {--password-stdin : Read the owner password securely from STDIN without terminal echo}';

    protected $description = 'Provision the initial single-owner SitePulse administrator account';

    /**
     * Optional stream override for isolated tests reading `--password-stdin`.
     *
     * @var resource|null
     */
    private static $stdinStream;

    /**
     * Execute the console command.
     */
    public function handle(OwnerProvisioner $provisioner): int
    {
        if (! $this->getLaravel()->runningInConsole()) {
            return self::FAILURE;
        }

        try {
            if ($provisioner->ownerExists()) {
                $this->components->error('Cannot create owner: an account already exists.');

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            Log::error('Owner provisioning preflight failed.', ['exception' => $e::class]);
            $this->components->error('Unable to verify existing accounts. Ensure database migrations have run.');

            return self::FAILURE;
        }

        try {
            $name = $this->resolveName();
            if ($name === null) {
                return self::FAILURE;
            }

            $email = $this->resolveEmail();
            if ($email === null) {
                return self::FAILURE;
            }

            $password = $this->resolvePassword();
            if ($password === null) {
                return self::FAILURE;
            }

            $owner = $provisioner->provision($name, $email, $password);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        } catch (OwnerAlreadyExistsException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            Log::error('Owner provisioning failed.', ['exception' => $e::class]);
            $this->components->error('Unable to provision the owner account.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Owner account created for %s.', $owner->email));

        return self::SUCCESS;
    }

    /**
     * Bind a custom stream for testing `--password-stdin` without mutating process STDIN.
     *
     * @param  resource|null  $stream
     */
    public static function useStdinStream($stream): void
    {
        self::$stdinStream = $stream;
    }

    private function resolveName(): ?string
    {
        $option = $this->option('name');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        if ($this->option('password-stdin') || ! $this->input->isInteractive()) {
            $this->components->error('The --name option is required when running non-interactively or with --password-stdin.');

            return null;
        }

        $answer = $this->ask('Owner name');
        if (! is_string($answer) || trim($answer) === '') {
            $this->components->error('The name field is required.');

            return null;
        }

        return trim($answer);
    }

    private function resolveEmail(): ?string
    {
        $option = $this->option('email');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        if ($this->option('password-stdin') || ! $this->input->isInteractive()) {
            $this->components->error('The --email option is required when running non-interactively or with --password-stdin.');

            return null;
        }

        $answer = $this->ask('Owner email address');
        if (! is_string($answer) || trim($answer) === '') {
            $this->components->error('The email field is required.');

            return null;
        }

        return trim($answer);
    }

    private function resolvePassword(): ?string
    {
        if ($this->option('password-stdin')) {
            return $this->readPasswordFromStdin();
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Interactive terminal required to prompt securely for password, or pass --password-stdin.');

            return null;
        }

        // Disable echo fallback so terminals that cannot suppress echo never expose passwords.
        try {
            $password = $this->secret('Owner password (min 12 chars, upper/lower/number/symbol)', false);
        } catch (Throwable) {
            $this->components->error('Unable to read password without terminal echo; use an interactive terminal or --password-stdin.');

            return null;
        }

        if (! is_string($password) || $password === '') {
            $this->components->error('The password field is required.');

            return null;
        }

        try {
            $confirmation = $this->secret('Confirm owner password', false);
        } catch (Throwable) {
            $this->components->error('Unable to read password confirmation without terminal echo; use an interactive terminal or --password-stdin.');

            return null;
        }

        if (! is_string($confirmation) || ! $this->passwordsMatch($password, $confirmation)) {
            $this->components->error('The password confirmation does not match.');

            return null;
        }

        return $password;
    }

    private function readPasswordFromStdin(): ?string
    {
        $stream = self::$stdinStream ?? (defined('STDIN') ? STDIN : null);
        if (! is_resource($stream)) {
            $this->components->error('Unable to read password from STDIN.');

            return null;
        }

        $raw = stream_get_contents($stream, 8193);
        if (! is_string($raw)) {
            $this->components->error('Unable to read password from STDIN.');

            return null;
        }

        if (strlen($raw) > 8192) {
            $this->components->error('The password input exceeded the maximum supported length.');

            return null;
        }

        $password = preg_replace("/\r?\n\z/", '', $raw);
        if (! is_string($password) || $password === '') {
            $this->components->error('A non-empty owner password must be provided via STDIN.');

            return null;
        }

        return $password;
    }

    private function passwordsMatch(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): bool
    {
        return hash_equals($password, $confirmation);
    }
}
