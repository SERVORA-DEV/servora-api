<?php

namespace Database\Factories;

use App\Models\SpaBusiness;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Package>
 */
class PackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spa_business_id' => SpaBusiness::factory(),
            'name' => fake()->unique()->words(3, true) . ' Package',
            'default_price' => 1000,
            'is_active' => true,
        ];
    }
}
