<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceVariant>
 */
class ServiceVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'duration_minutes' => 60,
            'price' => 500,
            'is_active' => true,
        ];
    }
}
