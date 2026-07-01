<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),

            // Make sure a Role record exists before using the factory
            //'role_id' => Role::factory(),

            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'suffix' => fake()->optional()->randomElement(['Jr.', 'Sr.', 'III', null]),

            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),

            'password' => static::$password ??= Hash::make('password'),

            'phone_number' => fake()->phoneNumber(),
            'avatar' => null,

            'account_status' => 'Active',
            'last_login_at' => now(),

            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}