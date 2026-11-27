<?php

namespace App\Support;

use App\Exceptions\OwnerAlreadyExistsException;
use App\Exceptions\OwnerProvisioningException;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Atomically validates and provisions the single SitePulse owner account.
 */
class OwnerProvisioner
{
    public const MIN_PASSWORD_LENGTH = 12;

    public const MAX_PASSWORD_BYTES = 72;

    /**
     * Determine whether any user account already exists in the database.
     */
    public function ownerExists(): bool
    {
        return User::query()->exists();
    }

    /**
     * Return the password validation rules enforced for owner provisioning.
     *
     * @return array<int, mixed>
     */
    public function passwordRules(): array
    {
        $rules = [
            'required',
            'string',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && str_contains($value, "\0")) {
                    $fail('The password must not contain null bytes.');
                }
            },
            Password::min(self::MIN_PASSWORD_LENGTH)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols(),
        ];

        if ((string) config('hashing.driver', 'bcrypt') === 'bcrypt') {
            $rules[] = static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::MAX_PASSWORD_BYTES) {
                    $fail('The password must not exceed '.self::MAX_PASSWORD_BYTES.' bytes when using bcrypt hashing.');
                }
            };
        }

        return $rules;
    }

    /**
     * Validate and normalize owner provisioning inputs without exposing secret values.
     *
     * @return array{name: string, email: string}
     *
     * @throws ValidationException
     */
    public function validateInput(string $name, string $email, #[SensitiveParameter] string $password): array
    {
        $normalizedName = trim($name);
        $normalizedEmail = mb_strtolower(trim($email));

        $validator = Validator::make(
            [
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'password' => $password,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
                'password' => $this->passwordRules(),
            ]
        );

        $validated = $validator->validate();

        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];
    }

    /**
     * Provision the single initial owner inside an atomic database transaction.
     *
     * Enforces single-owner semantics both via row checks and the database-level
     * unique `owner_slot` constraint so concurrent CLI invocations fail safely.
     *
     * @throws OwnerAlreadyExistsException
     * @throws OwnerProvisioningException
     * @throws ValidationException
     */
    public function provision(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        if ($this->ownerExists()) {
            throw new OwnerAlreadyExistsException;
        }

        $validated = $this->validateInput($name, $email, $password);

        try {
            return DB::transaction(function () use ($validated, $password): User {
                if (User::query()->lockForUpdate()->exists()) {
                    throw new OwnerAlreadyExistsException;
                }

                $owner = new User;
                $owner->forceFill([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'email_verified_at' => now(),
                    'password' => $password,
                    'is_owner' => true,
                    'owner_slot' => 1,
                ]);
                $owner->save();

                return $owner;
            }, 5);
        } catch (OwnerAlreadyExistsException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException) {
            throw new OwnerAlreadyExistsException;
        } catch (QueryException) {
            if ($this->ownerExists()) {
                throw new OwnerAlreadyExistsException;
            }

            // Re-throw without SQL query text or parameter bindings so credentials cannot leak.
            throw new OwnerProvisioningException('Owner provisioning failed due to a database error.');
        }
    }
}
