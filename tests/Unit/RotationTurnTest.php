<?php

namespace Tests\Unit;

use App\Support\RotationTurn;
use PHPUnit\Framework\TestCase;

// Whose turn it is in the staff rotation: the first person on the floor who
// is not in the middle of a service.
class RotationTurnTest extends TestCase
{
    private function row(string $name, string $status, bool $inQueue = true): array
    {
        return ['name' => $name, 'in_queue' => $inQueue, 'rotation_status' => $status];
    }

    public function test_someone_free_on_the_floor_can_take_the_next_client(): void
    {
        $this->assertTrue(RotationTurn::canTakeNext(true, 'free'));
    }

    public function test_someone_assigned_but_not_started_can_still_take_the_next_client(): void
    {
        // A reservation later today, or a client who is still waiting: they
        // are standing free right now.
        $this->assertTrue(RotationTurn::canTakeNext(true, 'assigned'));
    }

    public function test_someone_in_the_middle_of_a_service_cannot(): void
    {
        $this->assertFalse(RotationTurn::canTakeNext(true, 'in_service'));
    }

    public function test_nobody_off_the_floor_can_whatever_their_status(): void
    {
        $this->assertFalse(RotationTurn::canTakeNext(false, 'free'));
        $this->assertFalse(RotationTurn::canTakeNext(false, 'assigned'));
    }

    public function test_the_first_in_line_stays_next_up_while_only_assigned(): void
    {
        $next = RotationTurn::nextUp([
            $this->row('Alice', 'assigned'),
            $this->row('Bruno', 'free'),
        ]);

        $this->assertSame('Alice', $next['name']);
    }

    public function test_the_turn_passes_once_their_service_starts(): void
    {
        $next = RotationTurn::nextUp([
            $this->row('Alice', 'in_service'),
            $this->row('Bruno', 'assigned'),
            $this->row('Carla', 'free'),
        ]);

        $this->assertSame('Bruno', $next['name']);
    }

    public function test_people_who_have_not_timed_in_are_passed_over(): void
    {
        $next = RotationTurn::nextUp([
            $this->row('Alice', 'free', false),
            $this->row('Bruno', 'assigned'),
        ]);

        $this->assertSame('Bruno', $next['name']);
    }

    public function test_there_is_nobody_next_when_everyone_is_mid_service_or_away(): void
    {
        $this->assertNull(RotationTurn::nextUp([
            $this->row('Alice', 'in_service'),
            $this->row('Bruno', 'free', false),
        ]));
        $this->assertNull(RotationTurn::nextUp([]));
    }

    public function test_a_row_with_no_status_counts_as_free(): void
    {
        $this->assertSame('Alice', RotationTurn::nextUp([['name' => 'Alice', 'in_queue' => true]])['name']);
    }
}
