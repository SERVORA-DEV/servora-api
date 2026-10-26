<?php

namespace Tests\Unit;

use App\Support\BookingBoard;
use PHPUnit\Framework\TestCase;

// The front desk's booking board: who can take a visit at each start time.
class BookingBoardTest extends TestCase
{
    private const OPEN = 600;    // 10:00
    private const CLOSE = 1320;  // 22:00

    private function day(array $over = []): array
    {
        return $over + ['open' => self::OPEN, 'close' => self::CLOSE, 'closures' => []];
    }

    private function therapist(int $id, array $over = []): array
    {
        return $over + [
            'id' => $id, 'uuid' => "t-{$id}", 'on_leave' => false, 'has_schedule' => true,
            'shifts' => [['start' => 600, 'end' => 1140]],   // 10:00–19:00
        ];
    }

    private function states(array $row): array
    {
        return array_column($row['slots'], 'state', 'time');
    }

    public function test_a_therapist_is_free_inside_their_shift_and_off_outside_it(): void
    {
        $board = BookingBoard::build($this->day(), [$this->therapist(1)], [], 60, 60);
        $states = $this->states($board['therapists'][0]);

        $this->assertSame('free', $states['10:00']);
        $this->assertSame('free', $states['18:00']);     // ends 19:00, the end of the shift
        $this->assertSame('off', $states['19:00']);
        $this->assertSame('off', $states['21:00']);
        $this->assertSame('working', $board['therapists'][0]['day']);
        $this->assertSame('10:00', $board['therapists'][0]['shift_start']);
        $this->assertSame('19:00', $board['therapists'][0]['shift_end']);
    }

    // There are no breaks: a shift is free from its start to its end.
    public function test_a_therapist_is_free_right_through_their_shift(): void
    {
        $states = $this->states(BookingBoard::build($this->day(), [$this->therapist(1)], [], 90, 60)['therapists'][0]);

        foreach (['12:00', '13:00', '14:00', '15:00', '17:00'] as $time) {
            $this->assertSame('free', $states[$time], $time);
        }
        $this->assertSame('off', $states['18:00']);       // 6:00–7:30 runs past the shift
        $this->assertArrayNotHasKey('break_start', BookingBoard::build($this->day(), [$this->therapist(1)], [], 90, 60)['therapists'][0]);
    }

    public function test_their_own_booking_takes_the_times_it_overlaps(): void
    {
        $busy = [['start' => 1020, 'end' => 1080, 'staff' => [1]]];   // 5:00–6:00 PM
        $board = BookingBoard::build($this->day(), [$this->therapist(1), $this->therapist(2)], $busy, 60, 60);

        $this->assertSame('booked', $this->states($board['therapists'][0])['17:00']);
        $this->assertSame('free', $this->states($board['therapists'][0])['16:00']);
        $this->assertSame('free', $this->states($board['therapists'][0])['18:00']);
        $this->assertSame('free', $this->states($board['therapists'][1])['17:00']);
        $this->assertSame(1, $board['therapists'][0]['booked_count']);
        $this->assertSame([['start' => '17:00', 'end' => '18:00']], $board['therapists'][0]['busy']);
        $this->assertSame(0, $board['therapists'][1]['booked_count']);
    }

    public function test_a_longer_visit_is_blocked_by_a_booking_later_in_it(): void
    {
        $busy = [['start' => 1020, 'end' => 1080, 'staff' => [1]]];
        $states = $this->states(BookingBoard::build($this->day(), [$this->therapist(1)], $busy, 120, 60)['therapists'][0]);

        $this->assertSame('booked', $states['16:00']);    // 4:00–6:00 overlaps 5:00
        $this->assertSame('free', $states['15:00']);      // 3:00–5:00 ends as it starts
    }

    public function test_on_leave_and_day_off_are_off_all_day(): void
    {
        $board = BookingBoard::build($this->day(), [
            $this->therapist(1, ['on_leave' => true]),
            $this->therapist(2, ['shifts' => []]),
        ], [], 60, 60);

        $this->assertSame('on_leave', $board['therapists'][0]['day']);
        $this->assertSame('day_off', $board['therapists'][1]['day']);
        $this->assertSame(['off'], array_values(array_unique($this->states($board['therapists'][0]))));
        $this->assertSame(['off'], array_values(array_unique($this->states($board['therapists'][1]))));
        $this->assertNull($board['therapists'][0]['next_free']);
        $this->assertNull($board['therapists'][1]['shift_start']);
    }

    public function test_no_schedule_entered_means_working_whenever_the_branch_is_open(): void
    {
        $board = BookingBoard::build($this->day(), [$this->therapist(1, ['has_schedule' => false, 'shifts' => []])], [], 60, 60);

        $this->assertSame('working', $board['therapists'][0]['day']);
        $this->assertSame(['free'], array_values(array_unique($this->states($board['therapists'][0]))));
        $this->assertNull($board['therapists'][0]['shift_start']);
    }

    public function test_any_therapist_counts_who_is_left_after_bookings_with_nobody_on_them(): void
    {
        $busy = [
            ['start' => 1020, 'end' => 1080, 'staff' => [1]],   // 5 PM, therapist 1
            ['start' => 1020, 'end' => 1080, 'staff' => []],    // 5 PM, nobody yet
        ];
        $slots = array_column(BookingBoard::build($this->day(), [$this->therapist(1), $this->therapist(2)], $busy, 60, 60)['slots'], null, 'time');

        $this->assertSame('free', $slots['16:00']['state']);
        $this->assertSame(2, $slots['16:00']['free_therapists']);
        $this->assertSame('full', $slots['17:00']['state']);    // 2 is free, but the unassigned booking needs them
        $this->assertSame(0, $slots['17:00']['free_therapists']);
        $this->assertSame('full', $slots['20:00']['state']);    // both shifts are over
    }

    public function test_a_branch_with_no_therapists_is_never_full(): void
    {
        $slots = BookingBoard::build($this->day(), [], [], 60, 60)['slots'];

        $this->assertSame(['free'], array_values(array_unique(array_column($slots, 'state'))));
    }

    public function test_times_already_gone_today_are_past_and_next_free_skips_them(): void
    {
        $board = BookingBoard::build($this->day(), [$this->therapist(1)], [], 60, 60, 1000);   // now 4:40 PM

        $this->assertSame('past', $this->states($board['therapists'][0])['16:00']);
        $this->assertSame('free', $this->states($board['therapists'][0])['17:00']);
        $this->assertSame('17:00', $board['therapists'][0]['next_free']);
        $this->assertSame('past', array_column($board['slots'], 'state', 'time')['10:00']);
    }

    public function test_the_branchs_closures_drop_those_start_times(): void
    {
        $board = BookingBoard::build($this->day(['closures' => [[900, 1020]]]), [$this->therapist(1)], [], 60, 60);
        $times = array_column($board['slots'], 'time');

        $this->assertContains('12:00', $times);
        $this->assertContains('13:00', $times);
        $this->assertNotContains('15:00', $times);            // inside the closure
        $this->assertNotContains('16:00', $times);
        $this->assertContains('17:00', $times);
        $this->assertNotContains('14:30', $times);            // not on the hour
        $this->assertSame(count($times), count($board['therapists'][0]['slots']));
    }

    public function test_a_branch_open_past_midnight_lists_the_small_hours(): void
    {
        $board = BookingBoard::build($this->day(['open' => 1320, 'close' => 1560]), [], [], 60, 60);   // 10 PM – 2 AM

        $this->assertSame(['22:00', '23:00', '00:00', '01:00'], array_column($board['slots'], 'time'));
    }

    public function test_clock_and_minutes_round_trip(): void
    {
        $this->assertSame('09:05', BookingBoard::clock(545));
        $this->assertSame('01:00', BookingBoard::clock(1500));
        $this->assertSame(545, BookingBoard::minutes('09:05:00'));
    }

    // Nothing is booked that would still be running when the branch closes.
    public function test_a_time_that_would_run_past_closing_cannot_be_taken(): void
    {
        $therapist = $this->therapist(1, ['has_schedule' => false, 'shifts' => []]);   // works whenever the branch is open
        $board = BookingBoard::build($this->day(), [$therapist], [], 60, 30);
        $any = array_column($board['slots'], 'state', 'time');
        $mine = $this->states($board['therapists'][0]);

        $this->assertSame('free', $any['21:00']);          // ends 22:00, at closing
        $this->assertSame('past_closing', $any['21:30']);  // would end 22:30
        $this->assertSame('past_closing', $mine['21:30']);
    }
}

