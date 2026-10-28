<?php

namespace Tests\Unit;

use App\Support\BookingBoard;
use App\Support\HourlyCapacity;
use PHPUnit\Framework\TestCase;

// "Bookings per hour": a booking belongs to the clock hour it starts in, and
// the hour is full once it has as many as the spa takes. 0 = no limit.
class HourlyCapacityTest extends TestCase
{
    public function test_a_booking_is_in_the_hour_it_starts_in(): void
    {
        $this->assertSame(20, HourlyCapacity::hourOf('20:00'));
        $this->assertSame(20, HourlyCapacity::hourOf('20:34'));
        $this->assertSame(20, HourlyCapacity::hourOf('20:55:00'));
        $this->assertSame(21, HourlyCapacity::hourOf('21:00'));
        $this->assertSame(0, HourlyCapacity::hourOf('00:05'));
    }

    public function test_an_hour_is_full_at_the_limit_and_not_before(): void
    {
        $this->assertFalse(HourlyCapacity::isFull(2, 0));
        $this->assertFalse(HourlyCapacity::isFull(2, 1));
        $this->assertTrue(HourlyCapacity::isFull(2, 2));
        $this->assertTrue(HourlyCapacity::isFull(2, 3));
    }

    public function test_no_limit_means_the_hour_is_never_full(): void
    {
        $this->assertFalse(HourlyCapacity::isFull(0, 0));
        $this->assertFalse(HourlyCapacity::isFull(0, 40));
        $this->assertNull(HourlyCapacity::problem(0, 40));
    }

    public function test_a_full_hour_says_how_full_and_what_to_do(): void
    {
        $this->assertNull(HourlyCapacity::problem(2, 1));
        $this->assertSame('That hour is fully booked (2 of 2). Please pick another hour.', HourlyCapacity::problem(2, 2));
    }

    public function test_times_are_placed_on_the_five_minute_marks(): void
    {
        foreach (['08:00', '08:05', '20:35', '20:55', '20:35:00'] as $time) {
            $this->assertNull(HourlyCapacity::gridProblem($time), $time);
        }
        foreach (['08:01', '20:34', '20:59'] as $time) {
            $this->assertNotNull(HourlyCapacity::gridProblem($time), $time);
        }
    }

    public function test_the_summary_lists_each_hour_with_its_count_and_whether_it_is_full(): void
    {
        $rows = HourlyCapacity::summary(2, [19 => 1, 20 => 2], [19, 19, 20, 20, 21]);

        $this->assertSame([
            ['hour' => '19:00', 'booked' => 1, 'limit' => 2, 'full' => false],
            ['hour' => '20:00', 'booked' => 2, 'limit' => 2, 'full' => true],
            ['hour' => '21:00', 'booked' => 0, 'limit' => 2, 'full' => false],
        ], $rows);
    }

    public function test_with_no_limit_there_is_nothing_to_list(): void
    {
        $this->assertSame([], HourlyCapacity::summary(0, [20 => 5], [20]));
    }

    // ── on the front desk's board ────────────────────────────────────────

    private function board(int $limit, array $counts, int $step = 5): array
    {
        $day = ['open' => 1140, 'close' => 1320, 'closures' => []];   // 7 PM – 10 PM
        $therapist = ['id' => 1, 'uuid' => 't-1', 'on_leave' => false, 'has_schedule' => true, 'shifts' => [['start' => 0, 'end' => 1440]]];

        return BookingBoard::build($day, [$therapist], [], 30, $step, null, $limit, $counts);
    }

    public function test_the_board_offers_a_start_every_five_minutes(): void
    {
        $times = array_column($this->board(0, [])['slots'], 'time');

        $this->assertSame('19:00', $times[0]);
        $this->assertSame('19:05', $times[1]);
        $this->assertContains('20:35', $times);
        $this->assertCount(36, $times);                          // 3 hours × 12
    }

    public function test_a_full_hour_closes_every_start_in_it_for_anyone_and_for_each_therapist(): void
    {
        $board = $this->board(2, [20 => 2]);
        $any = array_column($board['slots'], 'state', 'time');
        $mine = array_column($board['therapists'][0]['slots'], 'state', 'time');

        foreach (['20:00', '20:35', '20:55'] as $time) {
            $this->assertSame('hour_full', $any[$time], $time);
            $this->assertSame('hour_full', $mine[$time], $time);
        }
        $this->assertSame('free', $any['19:55']);
        $this->assertSame('free', $any['21:00']);
        $this->assertSame('free', $mine['21:00']);
    }

    public function test_an_hour_with_room_left_stays_open(): void
    {
        $board = $this->board(2, [20 => 1]);
        $any = array_column($board['slots'], 'state', 'time');

        $this->assertSame('free', $any['20:35']);
        $row = collect($board['hours'])->firstWhere('hour', '20:00');
        $this->assertSame(1, $row['booked']);
        $this->assertFalse($row['full']);
    }

    public function test_with_no_limit_the_board_has_no_hour_summary_and_nothing_is_hour_full(): void
    {
        $board = $this->board(0, [20 => 9]);

        $this->assertSame([], $board['hours']);
        $this->assertNotContains('hour_full', array_column($board['slots'], 'state'));
    }

    // The notice ("book at least N hours ahead"): starts sooner than it are
    // closed on the desk's board, for anyone and for each therapist.
    public function test_starts_sooner_than_the_notice_are_closed(): void
    {
        $day = ['open' => 1140, 'close' => 1320, 'closures' => []];   // 7 PM – 10 PM
        $board = BookingBoard::build($day, [], [], 30, 5, null, 0, [], 1230);   // notice runs to 8:30 PM
        $any = array_column($board['slots'], null, 'time');

        $this->assertSame('too_soon', $any['20:25']['state']);
        $this->assertSame('free', $any['20:30']['state']);
        $this->assertSame('free', BookingBoard::build($day, [], [], 30, 5)['slots'][0]['state']);

        $therapist = ['id' => 1, 'uuid' => 't-1', 'on_leave' => false, 'has_schedule' => true, 'shifts' => [['start' => 0, 'end' => 1440]]];
        $mine = array_column(BookingBoard::build($day, [$therapist], [], 30, 5, null, 0, [], 1230)['therapists'][0]['slots'], 'state', 'time');
        $this->assertSame('too_soon', $mine['20:25']);
        $this->assertSame('free', $mine['20:30']);
    }

    public function test_a_time_that_has_gone_is_past_not_full(): void
    {
        $day = ['open' => 1140, 'close' => 1320, 'closures' => []];
        $board = BookingBoard::build($day, [], [], 30, 5, 1230, 1, [20 => 1]);   // now 8:30 PM, the 8 PM hour is full
        $any = array_column($board['slots'], 'state', 'time');

        $this->assertSame('past', $any['20:10']);
        $this->assertSame('hour_full', $any['20:35']);
    }
}
