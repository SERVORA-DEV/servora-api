<?php

namespace Tests\Unit;

use App\Support\AutoAssignment;
use PHPUnit\Framework\TestCase;

// Who and what a reservation with "any staff" is given: staff and rooms picked from the facts the
// planner supplies (who can do what, who is booked, which rooms fit).
//
// No database.
//   php artisan test --filter=AutoAssignmentTest
class AutoAssignmentTest extends TestCase
{
    private function svc(string $uuid, array $o = []): array
    {
        return $o + ['uuid' => $uuid, 'name' => ucfirst($uuid), 'needs_staff' => true, 'needs_facility' => false, 'has_staff' => false, 'has_facility' => false];
    }

    /** A service that needs a room and no staff (so a test about rooms isn't also about staff). */
    private function roomSvc(string $uuid, array $o = []): array
    {
        return $this->svc($uuid, $o + ['needs_staff' => false, 'needs_facility' => true]);
    }

    private function person(string $uuid, array $can, array $o = []): array
    {
        return $o + ['uuid' => $uuid, 'name' => ucfirst($uuid), 'gender' => null, 'workload' => 0, 'position' => null, 'can' => $can];
    }

    private function room(string $uuid, array $can): array
    {
        return ['uuid' => $uuid, 'name' => strtoupper($uuid), 'can' => $can];
    }

    public function test_one_staff_member_takes_the_whole_visit_when_someone_can_do_it_all(): void
    {
        $r = AutoAssignment::decide(
            [$this->svc('a'), $this->svc('b'), $this->svc('c')],
            [$this->person('ana', ['a' => true, 'b' => true, 'c' => false]), $this->person('bea', ['a' => true, 'b' => true, 'c' => true])],
            [],
        );

        $this->assertSame(['a' => 'bea', 'b' => 'bea', 'c' => 'bea'], $r['staff']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_when_nobody_does_it_all_the_one_who_fits_most_takes_those_and_another_the_rest(): void
    {
        $r = AutoAssignment::decide(
            [$this->svc('a'), $this->svc('b'), $this->svc('c')],
            [
                $this->person('ana', ['a' => true, 'b' => true, 'c' => false]),
                $this->person('bea', ['a' => false, 'b' => false, 'c' => true]),
                $this->person('cy', ['a' => true, 'b' => false, 'c' => false]),
            ],
            [],
        );

        $this->assertSame(['a' => 'ana', 'b' => 'ana', 'c' => 'bea'], $r['staff']);
    }

    public function test_a_service_nobody_can_take_is_left_for_the_desk_with_a_warning(): void
    {
        $r = AutoAssignment::decide(
            [$this->svc('a'), $this->svc('b')],
            [$this->person('ana', ['a' => true, 'b' => false])],
            [],
        );

        $this->assertSame(['a' => 'ana'], $r['staff']);
        $this->assertSame(['Nobody is available for B.'], $r['warnings']);
    }

    public function test_among_equals_the_least_booked_goes_first_then_turn_order_then_name(): void
    {
        $can = ['a' => true];
        $services = [$this->svc('a')];

        $busy = $this->person('ana', $can, ['workload' => 3, 'position' => 1]);
        $light = $this->person('bea', $can, ['workload' => 1, 'position' => 5]);
        $this->assertSame(['a' => 'bea'], AutoAssignment::decide($services, [$busy, $light], [])['staff']);

        $first = $this->person('cy', $can, ['workload' => 1, 'position' => 2]);
        $second = $this->person('dee', $can, ['workload' => 1, 'position' => 4]);
        $this->assertSame(['a' => 'cy'], AutoAssignment::decide($services, [$second, $first], [])['staff']);

        // Not on the floor (no turn) comes after someone with one; then by name.
        $off = $this->person('abe', $can, ['workload' => 1, 'position' => null]);
        $this->assertSame(['a' => 'cy'], AutoAssignment::decide($services, [$off, $first], [])['staff']);
        $x = $this->person('zed', $can);
        $y = $this->person('amy', $can);
        $this->assertSame(['a' => 'amy'], AutoAssignment::decide($services, [$x, $y], [])['staff']);
    }

    public function test_someone_running_late_is_chosen_after_those_on_time(): void
    {
        $can = ['a' => true];
        $late = $this->person('ana', $can, ['warning' => true, 'workload' => 0, 'position' => 1]);
        $onTime = $this->person('bea', $can, ['workload' => 4, 'position' => 9]);

        $this->assertSame(['a' => 'bea'], AutoAssignment::decide([$this->svc('a')], [$late, $onTime], [])['staff']);
        // Still used when they are the only one.
        $this->assertSame(['a' => 'ana'], AutoAssignment::decide([$this->svc('a')], [$late], [])['staff']);
    }

    public function test_for_a_client_who_is_here_someone_free_now_comes_before_someone_mid_service(): void
    {
        $can = ['a' => true];
        $busy = $this->person('ana', $can, ['busy' => true, 'workload' => 0, 'position' => 1]);
        $free = $this->person('bea', $can, ['workload' => 5, 'position' => 9]);

        $this->assertSame(['a' => 'bea'], AutoAssignment::decide([$this->svc('a')], [$busy, $free], [])['staff']);
        // Still used when everyone is mid-service: the client queues for the best of them.
        $other = $this->person('cy', $can, ['busy' => true, 'workload' => 2, 'position' => 2]);
        $this->assertSame(['a' => 'ana'], AutoAssignment::decide([$this->svc('a')], [$busy, $other], [])['staff']);
        // A reservation (no busy flag set) ignores it.
        $this->assertSame(['a' => 'ana'], AutoAssignment::decide([$this->svc('a')], [$this->person('ana', $can, ['workload' => 0]), $free], [])['staff']);
    }

    public function test_the_gender_preference_comes_first_and_gives_way_with_a_warning(): void
    {
        $can = ['a' => true];
        $services = [$this->svc('a')];
        $man = $this->person('al', $can, ['gender' => 'Male', 'workload' => 0]);
        $woman = $this->person('bea', $can, ['gender' => 'Female', 'workload' => 5]);

        $r = AutoAssignment::decide($services, [$man, $woman], [], 'Female');
        $this->assertSame(['a' => 'bea'], $r['staff']);
        $this->assertSame([], $r['warnings']);

        $r = AutoAssignment::decide($services, [$man], [], 'Female');
        $this->assertSame(['a' => 'al'], $r['staff']);
        $this->assertStringContainsString('No female staff is available for A', $r['warnings'][0]);

        // No preference: anyone, and staff with no gender on record are fine.
        $this->assertSame(['a' => 'al'], AutoAssignment::decide($services, [$man, $woman], [], null)['staff']);
    }

    public function test_services_that_already_have_staff_or_a_room_are_left_alone(): void
    {
        $r = AutoAssignment::decide(
            [$this->svc('a', ['needs_facility' => true, 'has_staff' => true, 'has_facility' => true]), $this->svc('b', ['needs_facility' => true])],
            [$this->person('ana', ['a' => true, 'b' => true])],
            [$this->room('r1', ['a' => true, 'b' => true])],
        );

        $this->assertSame(['b' => 'ana'], $r['staff']);
        $this->assertSame(['b' => 'r1'], $r['facility']);
    }

    public function test_a_service_that_needs_no_staff_gets_none_but_still_gets_a_room(): void
    {
        $r = AutoAssignment::decide(
            [$this->roomSvc('sauna')],
            [$this->person('ana', ['sauna' => true])],
            [$this->room('s1', ['sauna' => true])],
        );

        $this->assertSame([], $r['staff']);
        $this->assertSame(['sauna' => 's1'], $r['facility']);
    }

    public function test_one_room_for_everything_when_one_fits_else_the_room_that_fits_most(): void
    {
        $services = [$this->roomSvc('a'), $this->roomSvc('b'), $this->roomSvc('c')];

        $r = AutoAssignment::decide($services, [], [
            $this->room('r1', ['a' => true, 'b' => true, 'c' => false]),
            $this->room('r2', ['a' => true, 'b' => true, 'c' => true]),
        ]);
        $this->assertSame(['a' => 'r2', 'b' => 'r2', 'c' => 'r2'], $r['facility']);

        $r = AutoAssignment::decide($services, [], [
            $this->room('r1', ['a' => true, 'b' => true, 'c' => false]),
            $this->room('r3', ['a' => false, 'b' => false, 'c' => true]),
        ]);
        $this->assertSame(['a' => 'r1', 'b' => 'r1', 'c' => 'r3'], $r['facility']);
    }

    public function test_a_service_with_no_free_room_is_left_with_a_warning(): void
    {
        $r = AutoAssignment::decide(
            [$this->roomSvc('a'), $this->roomSvc('b')],
            [],
            [$this->room('r1', ['a' => true, 'b' => false])],
        );

        $this->assertSame(['a' => 'r1'], $r['facility']);
        $this->assertSame(['No facility is free for B.'], $r['warnings']);
    }

    public function test_an_optional_facility_only_rides_along_in_the_shared_room(): void
    {
        $services = [$this->roomSvc('facial'), $this->svc('nails', ['needs_staff' => false])];

        // The room that suits both is chosen, and nails joins it.
        $r = AutoAssignment::decide($services, [], [
            $this->room('r1', ['facial' => true, 'nails' => false]),
            $this->room('r2', ['facial' => true, 'nails' => true]),
        ]);
        $this->assertSame(['facial' => 'r2', 'nails' => 'r2'], $r['facility']);

        // Where nails doesn't fit and no other room does, it is left without one, and that is no warning.
        $r = AutoAssignment::decide($services, [], [$this->room('r1', ['facial' => true, 'nails' => false])]);
        $this->assertSame(['facial' => 'r1'], $r['facility']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_a_service_whose_facility_is_optional_is_given_a_free_room_too(): void
    {
        // Nothing needs a room, but one is free and fits: it is given (a bridal makeup in a room).
        $r = AutoAssignment::decide([$this->svc('makeup', ['needs_staff' => false])], [], [$this->room('r2', ['makeup' => true])]);
        $this->assertSame(['makeup' => 'r2'], $r['facility']);

        // None is free: left as it is, no warning.
        $r = AutoAssignment::decide([$this->svc('makeup', ['needs_staff' => false])], [], [$this->room('r2', ['makeup' => false])]);
        $this->assertSame([], $r['facility']);
        $this->assertSame([], $r['warnings']);

        // Where the required service's room doesn't suit it, another room that does is used.
        $r = AutoAssignment::decide(
            [$this->roomSvc('facial'), $this->svc('makeup', ['needs_staff' => false])],
            [],
            [$this->room('r1', ['facial' => true, 'makeup' => false]), $this->room('r2', ['facial' => false, 'makeup' => true])],
        );
        $this->assertSame(['facial' => 'r1', 'makeup' => 'r2'], $r['facility']);

        // A room the visit already uses is preferred.
        $r = AutoAssignment::decide(
            [$this->roomSvc('facial'), $this->svc('a', ['needs_staff' => false]), $this->svc('b', ['needs_staff' => false])],
            [],
            [
                $this->room('r0', ['facial' => false, 'a' => true, 'b' => true]),
                $this->room('r1', ['facial' => true, 'a' => false, 'b' => true]),
            ],
        );
        $this->assertSame(['facial' => 'r1', 'b' => 'r1', 'a' => 'r0'], $r['facility']);
    }

    public function test_a_home_visit_is_given_no_room(): void
    {
        $r = AutoAssignment::decide(
            [$this->svc('massage', ['needs_staff' => false, 'takes_facility' => false])],
            [],
            [$this->room('r1', ['massage' => true])],
        );

        $this->assertSame([], $r['facility']);
    }

    public function test_nobody_and_no_rooms_is_not_an_error(): void
    {
        $r = AutoAssignment::decide([$this->svc('a', ['needs_facility' => true])], [], []);

        $this->assertSame([], $r['staff']);
        $this->assertSame([], $r['facility']);
        $this->assertCount(2, $r['warnings']);
    }
}
