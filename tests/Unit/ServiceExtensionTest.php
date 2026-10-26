<?php

namespace Tests\Unit;

use App\Support\ServiceExtension;
use PHPUnit\Framework\TestCase;

// Extending a service in progress to a longer length of the same service.
class ServiceExtensionTest extends TestCase
{
    private function variants(): array
    {
        return [
            ['uuid' => 'v120', 'minutes' => 120, 'price' => 1400.0],
            ['uuid' => 'v60', 'minutes' => 60, 'price' => 800.0],
            ['uuid' => 'v90', 'minutes' => 90, 'price' => 1100.0],
            ['uuid' => 'v30', 'minutes' => 30, 'price' => 450.0],
        ];
    }

    public function test_only_longer_lengths_are_offered_shortest_first(): void
    {
        $options = ServiceExtension::options(60, 800.0, $this->variants());

        $this->assertSame(['v90', 'v120'], array_column($options, 'uuid'));
        $this->assertSame([30, 60], array_column($options, 'extra_minutes'));
        $this->assertSame([300.0, 600.0], array_column($options, 'extra_price'));
        $this->assertSame([1100.0, 1400.0], array_column($options, 'price'));
    }

    public function test_the_extra_is_worked_out_from_what_the_line_is_charged_now(): void
    {
        // The 60-minute line was priced at a branch's own ₱750.
        $options = ServiceExtension::options(60, 750.0, $this->variants());

        $this->assertSame(350.0, $options[0]['extra_price']);
    }

    public function test_the_longest_length_has_nothing_to_extend_to(): void
    {
        $this->assertSame([], ServiceExtension::options(120, 1400.0, $this->variants()));
        $this->assertSame([], ServiceExtension::options(60, 800.0, []));
    }

    public function test_only_a_service_in_progress_can_be_extended(): void
    {
        $this->assertNull(ServiceExtension::problem('In Progress', false, false));
        foreach (['Pending', 'Completed', 'Cancelled'] as $status) {
            $this->assertSame('Only a service that is in progress can be extended.', ServiceExtension::problem($status, false, false));
        }
    }

    public function test_a_billed_visit_is_refused(): void
    {
        $this->assertSame('This visit already has a bill.', ServiceExtension::problem('In Progress', false, true));
        $this->assertSame('This visit already has a bill.', ServiceExtension::problem('In Progress', true, true));
    }

    public function test_a_package_service_can_be_extended_once_there_is_somewhere_to_keep_the_difference(): void
    {
        $this->assertNull(ServiceExtension::problem('In Progress', true, false));
        $this->assertNull(ServiceExtension::problem('In Progress', true, false, 'facility', true));
        $this->assertStringContainsString('package', (string) ServiceExtension::problem('In Progress', true, false, 'time', false));
        // A service on its own never needed it.
        $this->assertNull(ServiceExtension::problem('In Progress', false, false, 'time', false));
    }

    public function test_a_procedure_based_service_has_no_longer_length(): void
    {
        $this->assertSame(
            'This service has one price — there is no longer length to extend it to.',
            ServiceExtension::problem('In Progress', false, false, 'procedure'),
        );
        $this->assertNull(ServiceExtension::problem('In Progress', false, false, 'facility'));
    }

    public function test_a_package_service_note_says_what_goes_on_top(): void
    {
        $this->assertSame(
            'Extended 60 → 90 min by Janine Soriano (+₱300.00 on top of the package)',
            ServiceExtension::note(60, 90, 'Janine Soriano', null, 300.0),
        );
    }

    public function test_the_new_length_must_be_a_longer_one_of_the_same_service_offered_here(): void
    {
        $ok = ['same_service' => true, 'minutes' => 90, 'offered' => true];

        $this->assertNull(ServiceExtension::targetProblem($ok, 60));
        $this->assertSame('Choose another length of the same service.', ServiceExtension::targetProblem(null, 60));
        $this->assertSame('Choose another length of the same service.', ServiceExtension::targetProblem(['same_service' => false] + $ok, 60));
        $this->assertSame('That length is not offered at this branch.', ServiceExtension::targetProblem(['offered' => false] + $ok, 60));
        $this->assertSame('Choose a longer length than the one it is running at.', ServiceExtension::targetProblem($ok, 90));
        $this->assertSame('Choose a longer length than the one it is running at.', ServiceExtension::targetProblem($ok, 120));
    }

    public function test_the_note_says_from_what_to_what_and_who_and_keeps_what_was_there(): void
    {
        $this->assertSame('Extended 60 → 90 min by Janine Soriano', ServiceExtension::note(60, 90, 'Janine Soriano'));
        $this->assertSame('light pressure · Extended 60 → 90 min by Janine Soriano', ServiceExtension::note(60, 90, 'Janine Soriano', ' light pressure '));
        $this->assertSame(
            'Extended 60 → 90 min by Janine Soriano · Extended 90 → 120 min by Janine Soriano',
            ServiceExtension::note(90, 120, 'Janine Soriano', ServiceExtension::note(60, 90, 'Janine Soriano')),
        );
    }

    public function test_a_booking_for_the_same_therapist_before_the_new_end_is_a_clash(): void
    {
        // Now 14:50; extending would end at 15:30. Therapist 7, room 3.
        $bookings = [
            ['start' => 15 * 60, 'client' => 'Maria Santos', 'staff' => [7], 'rooms' => []],
            ['start' => 15 * 60 + 30, 'client' => 'Too Late', 'staff' => [7], 'rooms' => [3]],
            ['start' => 15 * 60, 'client' => 'Someone Else', 'staff' => [9], 'rooms' => [4]],
        ];

        $clashes = ServiceExtension::clashes(15 * 60 + 30, 14 * 60 + 50, [7], [3], $bookings);

        $this->assertCount(1, $clashes);
        $this->assertSame(['kind' => 'therapist', 'id' => 7, 'client' => 'Maria Santos', 'start' => 900, 'waiting' => false], $clashes[0]);
    }

    public function test_someone_already_waiting_and_a_room_booking_are_both_named_soonest_first(): void
    {
        $bookings = [
            ['start' => 15 * 60, 'client' => 'Room Booking', 'staff' => [], 'rooms' => [3]],
            ['start' => 14 * 60 + 30, 'client' => 'Waiting Walkin', 'staff' => [7], 'rooms' => []],
        ];

        $clashes = ServiceExtension::clashes(15 * 60 + 30, 14 * 60 + 50, [7], [3], $bookings);

        $this->assertSame(['Waiting Walkin', 'Room Booking'], array_column($clashes, 'client'));
        $this->assertSame([true, false], array_column($clashes, 'waiting'));
        $this->assertSame(['therapist', 'room'], array_column($clashes, 'kind'));
    }

    public function test_extra_time_comes_in_the_owners_steps_up_to_the_longest_extension(): void
    {
        // Foot Reflexology: ₱650 for 60 min, steps of 15, up to 60 in all.
        $steps = ServiceExtension::steps(60, 650 / 60, 15, 60, 0);

        $this->assertSame([15, 30, 45, 60], array_column($steps, 'extra_minutes'));
        $this->assertSame([75, 90, 105, 120], array_column($steps, 'minutes'));
        $this->assertSame([162.5, 325.0, 487.5, 650.0], array_column($steps, 'extra_price'));
    }

    public function test_time_already_added_counts_against_the_longest_extension(): void
    {
        // Already 30 minutes longer: only 15 and 30 more fit under 60.
        $this->assertSame([15, 30], array_column(ServiceExtension::steps(90, 650 / 60, 15, 60, 30), 'extra_minutes'));
        $this->assertSame([], ServiceExtension::steps(120, 650 / 60, 15, 60, 60));
    }

    public function test_with_no_limit_a_few_steps_are_offered_and_nothing_without_a_step_or_a_price(): void
    {
        $this->assertSame([15, 30, 45, 60], array_column(ServiceExtension::steps(60, 10.0, 15, 0, 0), 'extra_minutes'));
        $this->assertSame([], ServiceExtension::steps(60, 10.0, 0, 60, 0));
        $this->assertSame([], ServiceExtension::steps(60, 0.0, 15, 60, 0));
    }

    public function test_extra_time_has_to_be_whole_steps(): void
    {
        $this->assertTrue(ServiceExtension::isStep(30, 15));
        $this->assertFalse(ServiceExtension::isStep(20, 15));
        $this->assertFalse(ServiceExtension::isStep(0, 15));
        $this->assertFalse(ServiceExtension::isStep(15, 0));
        $this->assertSame(162.5, ServiceExtension::timePrice(650 / 60, 15));
    }

    public function test_no_one_else_booked_means_no_clash(): void
    {
        $this->assertSame([], ServiceExtension::clashes(900, 850, [7], [3], []));
        $this->assertSame([], ServiceExtension::clashes(900, 850, [], [], [['start' => 870, 'client' => 'X', 'staff' => [7], 'rooms' => [3]]]));
    }
}
