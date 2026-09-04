<?php

namespace Database\Factories;

use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BranchService>
 */
class BranchServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spa_branch_id' => SpaBranch::factory(),
            'service_variant_id' => ServiceVariant::factory(),
            'custom_price' => null,
            'is_available' => true,
        ];
    }
}
