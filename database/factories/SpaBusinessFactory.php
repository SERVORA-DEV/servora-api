<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SpaBusiness>
 */
class SpaBusinessFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_name' => fake()->unique()->company(),
            'business_email' => fake()->unique()->companyEmail(),
            'business_phone' => fake()->phoneNumber(),
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
        ];
    }
}
