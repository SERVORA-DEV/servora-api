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

    // A home visit as the booking board and the overlap checks see it (no database).
    private function visit(string $time, array $attributes = [], ?array $services = null): \App\Models\Appointment
    {
        $visit = (new \App\Models\Appointment())->forceFill($attributes + [
            'spa_branch_id' => 7,
            'service_location' => 'Home Service',
            'appointment_date' => '2026-11-20',
            'appointment_time' => $time,
        ]);
        if ($services !== null) {
            $visit->setRelation('services', collect($services)->map(function (array $line) {
                $item = (new \App\Models\AppointmentServiceItem())->forceFill(['status' => $line[0]]);
                $item->setRelation('therapistAssignments', collect([
                    (new \App\Models\TherapistAssignment())->forceFill(['assignment_status' => $line[0], 'completed_at' => $line[1] ?? null]),
                ]));

                return $item;
            }));
        }

        return $visit;
    }

    public function test_a_two_forty_visit_of_thirty_minutes_keeps_its_staff_until_three_thirty(): void
    {
        // The owner's example: booked 2:40 PM, 30 minutes, 20 minutes' travel. Free at 3:30, not 3:10.
        $this->travelTime(7, 20);
        [$from, $to] = HomeServiceRules::blockedWindow($this->visit('14:40'), 30);

        $this->assertSame('14:20', $from->format('H:i'));
        $this->assertSame('15:30', $to->format('H:i'));
    }

    public function test_a_visit_that_started_late_is_counted_from_the_arrival(): void
    {
        $this->travelTime(7, 20);
        // Arrived (checked in) at 2:55 PM; the 30 minutes run from then. An extension is in the minutes.
        [, $to] = HomeServiceRules::blockedWindow($this->visit('14:40', ['check_in_at' => '2026-11-20 14:55:00']), 45);

        $this->assertSame('16:00', $to->format('H:i'));
    }

    public function test_an_early_arrival_still_counts_from_the_booked_time(): void
    {
        $this->travelTime(7, 20);
        [, $to] = HomeServiceRules::blockedWindow($this->visit('14:40', ['check_in_at' => '2026-11-20 14:30:00']), 30);

        $this->assertSame('15:30', $to->format('H:i'));
    }

    public function test_a_finished_visit_keeps_its_staff_for_the_trip_back_from_when_it_ended(): void
    {
        $this->travelTime(7, 20);
        // Done at 3:20 PM (it ran over): back at the spa at 3:40 PM.
        $visit = $this->visit('14:40', [], [['Completed', '2026-11-20 15:20:00'], ['Cancelled']]);
        [, $to] = HomeServiceRules::blockedWindow($visit, 30);

        $this->assertSame('15:40', $to->format('H:i'));
    }

    public function test_a_visit_still_running_is_judged_by_its_length_not_a_finished_part(): void
    {
        $this->travelTime(7, 20);
        // One service done at 3:00 PM, another still going: the booked length still holds.
        $visit = $this->visit('14:40', [], [['Completed', '2026-11-20 15:00:00'], ['In Progress']]);
        [, $to] = HomeServiceRules::blockedWindow($visit, 60);

        $this->assertSame('16:00', $to->format('H:i'));
    }
}
