<?php

namespace Tests\Unit;

use App\Service\Business\HomeServiceRules;
use Tests\TestCase;

// The parts of home service that are plain arithmetic: how far a client's
// pin is from the branch, how one booking's fee is shared, and whether a
// booking is long enough to be worth the trip.
//
// No database.
//   php artisan test --filter=HomeServiceRulesTest
class HomeServiceRulesTest extends TestCase
{
    public function test_no_minimum_means_any_length_can_be_a_home_visit(): void
    {
        $this->assertNull(HomeServiceRules::minimumProblem(['home_service_min_minutes' => 0], 15));
        $this->assertNull(HomeServiceRules::minimumProblem([], 15));
    }

    public function test_a_home_visit_at_or_over_the_minimum_is_fine(): void
    {
        $this->assertNull(HomeServiceRules::minimumProblem(['home_service_min_minutes' => 60], 60));
        $this->assertNull(HomeServiceRules::minimumProblem(['home_service_min_minutes' => 60], 90));
    }

    public function test_a_home_visit_under_the_minimum_is_explained_in_plain_time(): void
    {
        $message = HomeServiceRules::minimumProblem(['home_service_min_minutes' => 60], 30);

        $this->assertNotNull($message);
        $this->assertStringContainsString('at least 1 hour of services', $message);
        $this->assertStringContainsString('30 minutes', $message);
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
