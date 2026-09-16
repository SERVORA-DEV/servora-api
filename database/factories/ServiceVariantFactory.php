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
            // No is_active here: the column was dropped (a variant's status is
            // read from its parent Service now) and factories insert with the
            // model unguarded, so a stale attribute is a hard SQL error rather
            // than something $fillable quietly filters out.
        ];
    }
}
