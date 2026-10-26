<?php

namespace Tests\Unit;

use App\Support\ReservationHold;
use Carbon\Carbon;
use Tests\TestCase;

// A reservation not here yet holds its staff until its grace period is over,
// and a client arriving in time keeps the booked place in the line.
//
// No database.
//   php artisan test --filter=ReservationHoldTest
class ReservationHoldTest extends TestCase
{
    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-10-25 {$time}");
    }

    public function test_a_service_that_would_run_into_the_booking_is_blocked(): void
    {
        // Booked 1:00 PM, 15 minutes' grace. A 60-minute service at 12:30 would still be running at 1:00.
        $this->assertTrue(ReservationHold::blocks($this->at('13:00'), 15, $this->at('12:30'), 60));
    }

    public function test_a_service_that_ends_before_the_booking_is_allowed(): void
    {
        // 30 minutes from 12:00 ends at 12:30.
        $this->assertFalse(ReservationHold::blocks($this->at('13:00'), 15, $this->at('12:00'), 30));
        // Ending exactly at the booked time is fine too.
        $this->assertFalse(ReservationHold::blocks($this->at('13:00'), 15, $this->at('12:30'), 30));
    }

    public function test_the_hold_lasts_through_the_grace_period(): void
    {
        $this->assertTrue(ReservationHold::blocks($this->at('13:00'), 15, $this->at('13:05'), 30));
        $this->assertTrue(ReservationHold::blocks($this->at('13:00'), 15, $this->at('13:15'), 30));
    }

    public function test_the_hold_ends_after_the_grace_period(): void
    {
        $this->assertFalse(ReservationHold::blocks($this->at('13:00'), 15, $this->at('13:16'), 30));
        // No grace: free the moment the booked time has passed.
        $this->assertFalse(ReservationHold::blocks($this->at('13:00'), 0, $this->at('13:01'), 30));
    }

    public function test_an_early_arrival_takes_their_booked_time(): void
    {
        $this->assertEquals($this->at('13:00'), ReservationHold::queuedAt($this->at('13:00'), 15, $this->at('12:40')));
    }

    public function test_whoever_is_booked_first_is_ahead_whenever_they_arrived(): void
    {
        // A: booked 4:30, here at 4:00. B: booked 4:20, here at 4:10. B is ahead.
        $a = ReservationHold::queuedAt($this->at('16:30'), 15, $this->at('16:00'));
        $b = ReservationHold::queuedAt($this->at('16:20'), 15, $this->at('16:10'));
        $this->assertTrue($b->lt($a));
    }

    public function test_an_on_time_arrival_takes_their_booked_time(): void
    {
        $this->assertEquals($this->at('13:00'), ReservationHold::queuedAt($this->at('13:00'), 15, $this->at('13:05')));
        $this->assertEquals($this->at('13:00'), ReservationHold::queuedAt($this->at('13:00'), 15, $this->at('13:15')));
    }

    public function test_a_late_arrival_goes_to_the_back(): void
    {
        $this->assertEquals($this->at('13:20'), ReservationHold::queuedAt($this->at('13:00'), 15, $this->at('13:20')));
    }

    public function test_arrival_against_a_4_20_booking_with_15_minutes_grace(): void
    {
        $this->assertSame('early', ReservationHold::arrival($this->at('16:20'), 15, $this->at('16:10')));
        $this->assertSame('on_time', ReservationHold::arrival($this->at('16:20'), 15, $this->at('16:20')));
        $this->assertSame('on_time', ReservationHold::arrival($this->at('16:20'), 15, $this->at('16:31')));
        $this->assertSame('on_time', ReservationHold::arrival($this->at('16:20'), 15, $this->at('16:35')));
        $this->assertSame('late', ReservationHold::arrival($this->at('16:20'), 15, $this->at('16:40')));
    }
}
