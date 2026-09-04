<?php

namespace Database\Factories;

use App\Models\SpaBusiness;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SpaBranch>
 */
class SpaBranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spa_business_id' => SpaBusiness::factory(),
            'branch_name' => fake()->unique()->city() . ' Branch',
            'email' => fake()->unique()->companyEmail(),
            'phone_number' => fake()->phoneNumber(),
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
        ];
    }
}
