<?php

namespace Tests\Unit;

use App\Models\BranchSchedule;
use App\Support\Visits\BookingBoard;
use App\Support\Branches\BranchOpenWindows;
use PHPUnit\Framework\TestCase;

// A branch open past midnight is open on two dates: tonight's hours run to midnight and the rest
// (12 AM – 3 AM) is the first stretch of the NEXT date, even when the branch is closed that day.
// No database.
//   php artisan test --filter=BranchOpenWindowsTest
class BranchOpenWindowsTest extends TestCase
{
    private function day(?string $open, ?string $close, bool $closed = false): BranchSchedule
    {
        return (new BranchSchedule())->forceFill(['opening_time' => $open, 'closing_time' => $close, 'is_closed' => $closed]);
    }

    public function test_tonights_hours_stop_at_midnight_and_the_rest_is_tomorrows(): void
    {
        $friday = $this->day('11:00', '03:00');

        $tonight = BranchOpenWindows::for($friday, null);
        $this->assertSame([['from' => 660, 'to' => 1440, 'close' => 1620]], $tonight['windows']);

        // Saturday, closed: still takes 12:00 – 3:00 AM from Friday night.
        $saturday = BranchOpenWindows::for($this->day(null, null, true), $friday);
        $this->assertFalse($saturday['closed']);
        $this->assertSame([['from' => 0, 'to' => 180, 'close' => 180]], $saturday['windows']);
    }

    public function test_a_date_has_last_nights_tail_then_its_own_hours(): void
    {
        $set = BranchOpenWindows::for($this->day('11:00', '03:00'), $this->day('11:00', '03:00'));

        $this->assertSame([
            ['from' => 0, 'to' => 180, 'close' => 180],
            ['from' => 660, 'to' => 1440, 'close' => 1620],
        ], $set['windows']);
    }

    public function test_hours_ending_at_midnight_leave_nothing_for_the_next_date(): void
    {
        $this->assertSame(0, BranchOpenWindows::tail($this->day('09:00', '00:00')));
        $this->assertSame(0, BranchOpenWindows::tail($this->day('09:00', '21:00')));
        $this->assertSame(0, BranchOpenWindows::tail($this->day('00:00', '00:00')));
        $this->assertSame(0, BranchOpenWindows::tail($this->day(null, null, true)));
        $this->assertSame(0, BranchOpenWindows::tail(null));
        $this->assertSame(120, BranchOpenWindows::tail($this->day('22:00', '02:00')));
    }

    public function test_a_closed_date_with_no_late_night_before_it_is_closed(): void
    {
        $set = BranchOpenWindows::for($this->day(null, null, true), $this->day('09:00', '21:00'));

        $this->assertTrue($set['closed']);
        $this->assertSame([], $set['windows']);
    }

    public function test_no_hours_entered_uses_the_fallback_or_means_no_limit(): void
    {
        $this->assertTrue(BranchOpenWindows::for(null, null)['unlimited']);
        $this->assertSame([['from' => 540, 'to' => 1260, 'close' => 1260]], BranchOpenWindows::for(null, null, [540, 1260])['windows']);
    }

    // ── Open 24 hours (saved as 12:00 AM – 12:00 AM) ──────────────────────

    public function test_a_day_open_round_the_clock_runs_on_into_a_next_day_open_from_midnight(): void
    {
        $allDay = $this->day('00:00', '00:00');

        // Tomorrow also round the clock: a visit can run on past midnight.
        $this->assertSame([['from' => 0, 'to' => 1440, 'close' => 2880]], BranchOpenWindows::for($allDay, $allDay, null, $allDay)['windows']);
        // Tomorrow opens at midnight and closes at 10 PM: up to 10 PM tomorrow.
        $this->assertSame(2760, BranchOpenWindows::for($allDay, null, null, $this->day('00:00', '22:00'))['windows'][0]['close']);
        // Tomorrow opens later, is closed, or isn't known: midnight is the end.
        $this->assertSame(1440, BranchOpenWindows::for($allDay, null, null, $this->day('09:00', '21:00'))['windows'][0]['close']);
        $this->assertSame(1440, BranchOpenWindows::for($allDay, null, null, $this->day(null, null, true))['windows'][0]['close']);
        $this->assertSame(1440, BranchOpenWindows::for($allDay, null)['windows'][0]['close']);
        // Equal times other than midnight are round the clock too.
        $this->assertSame([['from' => 0, 'to' => 1440, 'close' => 1440]], BranchOpenWindows::for($this->day('09:00', '09:00'), null)['windows']);
    }

    public function test_at_a_24_hour_branch_a_late_visit_crosses_midnight_on_the_board(): void
    {
        $allDay = $this->day('00:00', '00:00');
        $windows = BranchOpenWindows::for($allDay, $allDay, null, $allDay)['windows'];

        $board = BookingBoard::build(['open' => 0, 'close' => 2880, 'closures' => [], 'windows' => $windows], [], [], 90, 60);

        $last = end($board['slots']);
        $this->assertSame('23:00', $last['time']);
        $this->assertSame('free', $last['state'], 'an 11 PM visit of 90 minutes ends at 12:30 AM, inside tomorrow\'s hours');
    }

    public function test_the_board_lists_the_tail_on_the_next_date_not_the_night_it_opened(): void
    {
        $tonight = BranchOpenWindows::for($this->day('11:00', '03:00'), null)['windows'];
        $board = BookingBoard::build(['open' => 660, 'close' => 1620, 'closures' => [], 'windows' => $tonight], [], [], 60, 60);
        $times = array_column($board['slots'], 'time');
        $this->assertSame('11:00', $times[0]);
        $this->assertSame('23:00', end($times));
        $this->assertNotContains('00:00', $times);
        // The visit has to be over by the real closing time, past midnight.
        $this->assertSame('free', end($board['slots'])['state']);

        $tomorrow = BranchOpenWindows::for($this->day(null, null, true), $this->day('11:00', '03:00'))['windows'];
        $board = BookingBoard::build(['open' => 0, 'close' => 180, 'closures' => [], 'windows' => $tomorrow], [], [], 60, 60);
        $this->assertSame(['00:00', '01:00', '02:00'], array_column($board['slots'], 'time'));
        // 2 AM + 1 hour runs to 3 AM exactly: fine; a 90-minute visit would run past closing.
        $long = BookingBoard::build(['open' => 0, 'close' => 180, 'closures' => [], 'windows' => $tomorrow], [], [], 90, 60);
        $this->assertSame('past_closing', end($long['slots'])['state']);
    }
}
