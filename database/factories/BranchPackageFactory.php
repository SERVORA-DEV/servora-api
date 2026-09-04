<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\SpaBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BranchPackage>
 */
class BranchPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spa_branch_id' => SpaBranch::factory(),
            'package_id' => Package::factory(),
            'custom_price' => null,
            'is_available' => true,
        ];
    }
}
