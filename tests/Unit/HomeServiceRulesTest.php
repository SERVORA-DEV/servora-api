<?php

namespace Tests\Unit;

use App\Service\Business\HomeServiceRules;
use Tests\TestCase;

// The parts of home service that are plain arithmetic: how far a client's
// pin is from the branch, how one booking's fee is shared, and how long a
// home visit keeps its staff (the travel time each way).
//
// No database.
//   php artisan test --filter=HomeServiceRulesTest
class HomeServiceRulesTest extends TestCase
{
    private function travelTime(int $branchId, int $minutes): void
    {
        // The branch's travel time, remembered for the request (no database).
        app()->scoped('home-service.travel', fn () => new \ArrayObject([$branchId => $minutes]));
    }

    public function test_a_home_visit_keeps_its_staff_for_the_trip_there_and_back(): void
    {
        $this->travelTime(7, 20);
        // Booked at 10:00 PM, an hour of services, 20 minutes each way: 9:40 PM to 11:20 PM.
        [$from, $to] = HomeServiceRules::span(true, 7, \Carbon\Carbon::parse('2026-11-20 22:00'), 60);

        $this->assertSame('21:40', $from->format('H:i'));
        $this->assertSame('23:20', $to->format('H:i'));
    }

    public function test_a_visit_at_the_spa_has_no_travel_time(): void
    {
        $this->travelTime(7, 20);
        [$from, $to] = HomeServiceRules::span(false, 7, \Carbon\Carbon::parse('2026-11-20 22:00'), 60);

        $this->assertSame('22:00', $from->format('H:i'));
        $this->assertSame('23:00', $to->format('H:i'));
    }

    public function test_no_travel_time_set_keeps_just_the_visit(): void
    {
        $this->travelTime(7, 0);
        [$from, $to] = HomeServiceRules::span(true, 7, \Carbon\Carbon::parse('2026-11-20 10:00'), 15);

        $this->assertSame('10:00', $from->format('H:i'));
        $this->assertSame('10:15', $to->format('H:i'));
    }

    public function test_distance_between_two_pins_is_straight_line_kilometres(): void
    {
        // Two points in Davao City roughly 4 to 5 km apart.
        $km = HomeServiceRules::distanceKm(7.0644, 125.6079, 7.0989, 125.6311);

        $this->assertEqualsWithDelta(4.6, $km, 0.6);
    }

    public function test_the_same_pin_is_zero_kilometres_away(): void
    {
        $this->assertEqualsWithDelta(0.0, HomeServiceRules::distanceKm(7.07, 125.61, 7.07, 125.61), 0.0001);
    }

    public function test_one_degree_of_latitude_is_about_111_km(): void
    {
        $this->assertEqualsWithDelta(111.2, HomeServiceRules::distanceKm(7.0, 125.6, 8.0, 125.6), 0.5);
    }

    public function test_one_therapist_gets_the_whole_fee(): void
    {
        $this->assertSame([12 => 300.0], HomeServiceRules::splitFee(300, [12]));
    }

    public function test_two_therapists_split_the_fee_equally(): void
    {
        $this->assertSame([12 => 150.0, 15 => 150.0], HomeServiceRules::splitFee(300, [12, 15, 12]));
    }

    public function test_no_fee_or_no_therapist_means_nothing_to_share(): void
    {
        $this->assertSame([], HomeServiceRules::splitFee(0, [12]));
        $this->assertSame([], HomeServiceRules::splitFee(300, []));
    }

    public function test_a_booking_is_home_service_only_when_it_says_so(): void
    {
        $this->assertTrue(HomeServiceRules::isHome(['service_location' => 'Home Service']));
        $this->assertFalse(HomeServiceRules::isHome(['service_location' => 'In Spa']));
        $this->assertFalse(HomeServiceRules::isHome([]));
    }
}
