<?php

namespace Database\Factories;

use App\Models\SpaBusiness;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spa_business_id' => SpaBusiness::factory(),
            'name' => fake()->unique()->words(3, true) . ' Massage',
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
