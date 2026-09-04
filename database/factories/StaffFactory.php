<?php

namespace Database\Factories;

use App\Models\SpaBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Staff>
 */
class StaffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spa_branch_id' => SpaBranch::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'role' => 'therapist',
            'employment_type' => 'Full-Time',
            'status' => 'active',
        ];
    }

    public function frontdesk(): static
    {
        return $this->state(fn () => ['role' => 'frontdesk']);
    }
}
