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
            'category' => fake()->randomElement(config('service_categories')),
            'is_active' => true,
        ];
    }
}
