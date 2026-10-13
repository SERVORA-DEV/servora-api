<?php

namespace Tests\Unit;

use App\Support\ServiceStartRules;
use PHPUnit\Framework\TestCase;

// Starting a second service while another of the same visit is in progress.
class ServiceStartRulesTest extends TestCase
{
    private const LOUNGE = 5;
    private const FACIAL_ROOM = 2;

    private function running(array $over = []): array
    {
        return $over + ['name' => 'Hair Spa Treatment', 'staff' => [10 => 'Aileen Pascual'], 'room' => self::LOUNGE, 'room_name' => 'Nail and Hair Lounge'];
    }

    public function test_nothing_in_progress_means_it_can_start(): void
    {
        $this->assertNull(ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => self::FACIAL_ROOM], []));
        $this->assertNull(ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => null], []));
    }

    public function test_a_different_room_is_refused_and_says_where_the_client_is(): void
    {
        $problem = ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => self::FACIAL_ROOM], [$this->running()]);

        $this->assertSame('This client is still having Hair Spa Treatment in Nail and Hair Lounge. Complete it first, or put this service in the same room to do both together.', $problem);
    }

    // A room is never required, so a service without one is not "somewhere
    // else": a manicure at a chair during a hair treatment in the lounge.
    public function test_no_room_on_this_one_goes_beside_one_that_has_a_room(): void
    {
        $this->assertNull(ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => null], [$this->running()]));
    }

    public function test_a_room_on_this_one_goes_beside_one_that_has_none(): void
    {
        $this->assertNull(ServiceStartRules::problem(
            ['staff' => [11 => 'Jomar Castillo'], 'room' => self::FACIAL_ROOM],
            [$this->running(['room' => null, 'room_name' => null])],
        ));
    }

    public function test_no_room_does_not_get_around_two_different_rooms(): void
    {
        // In the lounge and at an open bed already; a third in the facial room is refused for the lounge.
        $running = [
            $this->running(['name' => 'Foot Spa', 'staff' => [12 => 'Grace Villanueva'], 'room' => null, 'room_name' => null]),
            $this->running(),
        ];

        $problem = ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => self::FACIAL_ROOM], $running);

        $this->assertStringContainsString('still having Hair Spa Treatment in Nail and Hair Lounge', (string) $problem);
    }

    public function test_no_room_does_not_get_around_the_same_therapist(): void
    {
        $problem = ServiceStartRules::problem(['staff' => [10 => 'Aileen Pascual'], 'room' => null], [$this->running()]);

        $this->assertSame('Aileen Pascual is still doing Hair Spa Treatment for this client. Complete it first.', $problem);
    }

    public function test_the_same_room_with_a_different_therapist_can_run_together(): void
    {
        $this->assertNull(ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => self::LOUNGE], [$this->running()]));
        // Ids may arrive as numbers or as text; the same room is the same room.
        $this->assertNull(ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => (string) self::LOUNGE], [$this->running()]));
    }

    public function test_no_room_on_either_with_a_different_therapist_can_run_together(): void
    {
        $this->assertNull(ServiceStartRules::problem(
            ['staff' => [11 => 'Jomar Castillo'], 'room' => null],
            [$this->running(['room' => null, 'room_name' => null])],
        ));
    }

    public function test_the_same_therapist_is_refused_even_in_the_same_room(): void
    {
        $problem = ServiceStartRules::problem(['staff' => [10 => 'Aileen Pascual'], 'room' => self::LOUNGE], [$this->running()]);

        $this->assertSame('Aileen Pascual is still doing Hair Spa Treatment for this client. Complete it first.', $problem);
    }

    public function test_the_same_therapist_is_named_before_the_room(): void
    {
        $problem = ServiceStartRules::problem(['staff' => [10 => 'Aileen Pascual'], 'room' => self::FACIAL_ROOM], [$this->running()]);

        $this->assertStringStartsWith('Aileen Pascual is still doing', (string) $problem);
    }

    public function test_one_shared_therapist_among_several_is_enough_to_refuse(): void
    {
        $problem = ServiceStartRules::problem(
            ['staff' => [10 => 'Aileen Pascual', 11 => 'Jomar Castillo'], 'room' => self::LOUNGE],
            [$this->running(['staff' => [10 => 'Aileen Pascual', 12 => 'Grace Villanueva']])],
        );

        $this->assertSame('Aileen Pascual is still doing Hair Spa Treatment for this client. Complete it first.', $problem);
    }

    public function test_every_service_in_progress_has_to_agree(): void
    {
        $running = [
            $this->running(),
            $this->running(['name' => 'Classic Manicure', 'staff' => [12 => 'Grace Villanueva']]),
        ];

        // Same room as both, a third therapist: fine.
        $this->assertNull(ServiceStartRules::problem(['staff' => [11 => 'Jomar Castillo'], 'room' => self::LOUNGE], $running));
        // The second one's therapist: refused, and it is that service that is named.
        $this->assertSame(
            'Grace Villanueva is still doing Classic Manicure for this client. Complete it first.',
            ServiceStartRules::problem(['staff' => [12 => 'Grace Villanueva'], 'room' => self::LOUNGE], $running),
        );
    }

    public function test_a_room_with_no_name_still_reads_properly(): void
    {
        $problem = ServiceStartRules::problem(
            ['staff' => [11 => 'Jomar Castillo'], 'room' => self::FACIAL_ROOM],
            [$this->running(['room_name' => null])],
        );

        $this->assertStringContainsString('in another room', (string) $problem);
    }

    // ── Service kinds (App\Support\ServiceKind) ──────────────────────────────

    public function test_one_therapist_may_do_two_procedure_based_services_together_in_the_same_place(): void
    {
        $aileen = [10 => 'Aileen Pascual'];

        $this->assertNull(ServiceStartRules::problem(
            ['staff' => $aileen, 'room' => self::LOUNGE, 'kind' => 'procedure'],
            [$this->running(['kind' => 'procedure'])],
        ));
        // No room on either is the same place too.
        $this->assertNull(ServiceStartRules::problem(
            ['staff' => $aileen, 'room' => null, 'kind' => 'procedure'],
            [$this->running(['kind' => 'procedure', 'room' => null, 'room_name' => null])],
        ));
    }

    public function test_two_procedures_with_one_therapist_still_have_to_be_in_one_place(): void
    {
        $problem = ServiceStartRules::problem(
            ['staff' => [10 => 'Aileen Pascual'], 'room' => self::FACIAL_ROOM, 'kind' => 'procedure'],
            [$this->running(['kind' => 'procedure'])],
        );

        $this->assertStringContainsString('in Nail and Hair Lounge', (string) $problem);
    }

    public function test_a_service_measured_in_time_holds_its_therapist(): void
    {
        $aileen = [10 => 'Aileen Pascual'];
        $busy = 'Aileen Pascual is still doing Hair Spa Treatment for this client. Complete it first.';

        // Either one being time-based is enough; no kind given means time-based.
        $this->assertSame($busy, ServiceStartRules::problem(['staff' => $aileen, 'room' => self::LOUNGE, 'kind' => 'procedure'], [$this->running(['kind' => 'time'])]));
        $this->assertSame($busy, ServiceStartRules::problem(['staff' => $aileen, 'room' => self::LOUNGE, 'kind' => 'time'], [$this->running(['kind' => 'procedure'])]));
        $this->assertSame($busy, ServiceStartRules::problem(['staff' => $aileen, 'room' => self::LOUNGE, 'kind' => 'procedure'], [$this->running()]));
    }

    public function test_a_facility_based_service_with_nobody_on_it_only_has_to_be_in_the_same_place(): void
    {
        // The client moves from the lounge to the sauna: one place at a time.
        $problem = ServiceStartRules::problem(['staff' => [], 'room' => self::FACIAL_ROOM, 'kind' => 'facility'], [$this->running()]);
        $this->assertStringContainsString('in Nail and Hair Lounge', (string) $problem);

        $this->assertNull(ServiceStartRules::problem(['staff' => [], 'room' => self::LOUNGE, 'kind' => 'facility'], [$this->running()]));
        // With no room picked for it — nothing and nobody on it — it starts beside anything.
        $this->assertNull(ServiceStartRules::problem(['staff' => [], 'room' => null, 'kind' => 'facility'], [$this->running()]));
    }
}
