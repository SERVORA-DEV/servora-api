<?php

namespace Database\Factories;

use App\Models\SpaBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Facility>
 */
class FacilityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spa_branch_id' => SpaBranch::factory(),
            'name' => 'Room ' . fake()->unique()->numberBetween(1, 100000),
            'category' => 'Massage',
            'status' => 'Available',
            'is_available' => true,
        ];
    }
}
