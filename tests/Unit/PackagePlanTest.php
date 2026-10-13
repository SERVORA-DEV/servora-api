<?php

namespace Tests\Unit;

use App\Support\PackagePlan;
use PHPUnit\Framework\TestCase;

// A package names services and gives each its own minutes; the menu's lengths
// and prices are only a guide.
class PackagePlanTest extends TestCase
{
    private array $lengths = [['minutes' => 60], ['minutes' => 90], ['minutes' => 120]];

    public function test_the_menu_length_nearest_the_package_minutes_is_the_guide(): void
    {
        $this->assertSame(0, PackagePlan::closest($this->lengths, 60));
        $this->assertSame(0, PackagePlan::closest($this->lengths, 45));
        $this->assertSame(1, PackagePlan::closest($this->lengths, 80));
        $this->assertSame(2, PackagePlan::closest($this->lengths, 200));
    }

    public function test_halfway_between_two_lengths_takes_the_shorter(): void
    {
        $this->assertSame(0, PackagePlan::closest($this->lengths, 75));
        // Whatever order the menu lists them in.
        $this->assertSame(2, PackagePlan::closest([['minutes' => 120], ['minutes' => 90], ['minutes' => 60]], 75));
    }

    public function test_with_no_minutes_the_shortest_length_is_the_guide(): void
    {
        $this->assertSame(1, PackagePlan::closest([['minutes' => 90], ['minutes' => 30], ['minutes' => 60]], null));
        $this->assertSame(1, PackagePlan::closest([['minutes' => 90], ['minutes' => 30]], 0));
    }

    public function test_a_service_with_no_length_has_no_guide(): void
    {
        $this->assertNull(PackagePlan::closest([], 60));
        $this->assertSame(0, PackagePlan::closest([['minutes' => 45]], 600));
    }

    public function test_the_duration_is_every_service_and_add_on_added_up(): void
    {
        $this->assertSame(0, PackagePlan::totalMinutes([]));
        $this->assertSame(120, PackagePlan::totalMinutes([['minutes' => 60], ['minutes' => 60]]));
        // Two of the same count twice.
        $this->assertSame(150, PackagePlan::totalMinutes([['minutes' => 90, 'quantity' => 1], ['minutes' => 30, 'quantity' => 2]]));
        // Included add-ons add their time; one that takes none adds none.
        $this->assertSame(135, PackagePlan::totalMinutes([['minutes' => 60], ['minutes' => 60]], [15, 0, null]));
    }

    public function test_bought_separately_is_the_guide_prices_added_up(): void
    {
        $this->assertSame(0.0, PackagePlan::estimate([]));
        $this->assertSame(1700.0, PackagePlan::estimate([['price' => 800], ['price' => 900]]));
        $this->assertSame(2100.0, PackagePlan::estimate([['price' => 1300, 'quantity' => 1], ['price' => 400, 'quantity' => 2]]));
        $this->assertSame(1900.0, PackagePlan::estimate([['price' => 800], ['price' => 900]], [200]));
    }
}
