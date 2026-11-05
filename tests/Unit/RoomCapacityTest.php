<?php

namespace Tests\Unit;

use App\Support\RoomCapacity;
use PHPUnit\Framework\TestCase;

// How full a facility is at its busiest moment. No database.
//   php artisan test --filter=RoomCapacityTest
class RoomCapacityTest extends TestCase
{
    public function test_nobody_there_is_a_peak_of_zero(): void
    {
        $this->assertSame(0, RoomCapacity::peak([]));
    }

    public function test_visits_at_the_same_time_add_up(): void
    {
        $this->assertSame(2, RoomCapacity::peak([[100, 200], [150, 250]]));
        $this->assertSame(3, RoomCapacity::peak([[100, 200], [100, 200], [100, 200]]));
    }

    public function test_visits_one_after_another_do_not_count_against_each_other(): void
    {
        $this->assertSame(1, RoomCapacity::peak([[100, 200], [200, 300]]));
        $this->assertSame(1, RoomCapacity::peak([[100, 150], [160, 200], [210, 300]]));
    }

    public function test_it_is_the_busiest_moment_not_the_total(): void
    {
        // Three visits, but never more than two at once.
        $this->assertSame(2, RoomCapacity::peak([[100, 200], [150, 300], [250, 400]]));
    }

    public function test_a_window_with_no_length_is_nobody(): void
    {
        $this->assertSame(0, RoomCapacity::peak([[100, 100]]));
    }

    public function test_space_depends_on_the_limit(): void
    {
        $this->assertTrue(RoomCapacity::hasSpace(0, 1));
        $this->assertFalse(RoomCapacity::hasSpace(1, 1));
        $this->assertTrue(RoomCapacity::hasSpace(1, 2));
        $this->assertFalse(RoomCapacity::hasSpace(2, 2));
        // A limit that isn't set still holds one.
        $this->assertTrue(RoomCapacity::hasSpace(0, 0));
        $this->assertFalse(RoomCapacity::hasSpace(1, 0));
    }
}
