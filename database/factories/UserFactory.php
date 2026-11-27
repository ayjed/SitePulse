<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Generates a random unguessable password so automated tests never rely on
     * or leak a predictable default credential. Tests requiring a known secret
     * should override the password attribute explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(Str::random(32)),
            'is_owner' => false,
            'owner_slot' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Configure the factory user as the single authorized site owner.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_owner' => true,
            'owner_slot' => 1,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
