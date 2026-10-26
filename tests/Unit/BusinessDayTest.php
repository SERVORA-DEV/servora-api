<?php

namespace Tests\Unit;

use App\Support\BusinessDay;
use Carbon\Carbon;
use Tests\TestCase;

// A branch's working day runs from its opening to its next opening. Staff are not
// timed out at closing or at the end of their shift: the day only ends when the
// branch opens again (AttendanceRollover carries them over at that moment).
//
// No database: the week is handed in.
//   php artisan test --filter=BusinessDayTest
class BusinessDayTest extends TestCase
{
    // Opens 10 AM, except Sunday 2 PM; closed on Monday.
    private function week(): array
    {
        $open = fn (?string $at, bool $closed = false) => ['opening_time' => $at, 'is_closed' => $closed];

        return [
            'Saturday' => $open('10:00:00'),
            'Sunday' => $open('14:00:00'),
            'Monday' => $open(null, true),
            'Tuesday' => $open('10:00:00'),
            'Wednesday' => $open('10:00:00'),
            'Thursday' => $open('10:00:00'),
            'Friday' => $open('10:00:00'),
        ];
    }

    public function test_after_midnight_it_is_still_the_day_before_until_the_branch_opens(): void
    {
        // Saturday Oct 24, 2026 → Sunday Oct 25 opens at 2 PM.
        $this->assertSame('2026-10-24', BusinessDay::current($this->week(), Carbon::parse('2026-10-25 01:00')));
        $this->assertSame('2026-10-24', BusinessDay::current($this->week(), Carbon::parse('2026-10-25 13:59')));
        $this->assertSame('2026-10-25', BusinessDay::current($this->week(), Carbon::parse('2026-10-25 14:00')));
    }

    public function test_the_day_ends_when_the_branch_next_opens(): void
    {
        $this->assertEquals(Carbon::parse('2026-10-25 14:00'), BusinessDay::nextOpening($this->week(), '2026-10-24'));
    }

    public function test_a_closed_day_is_skipped(): void
    {
        // Sunday's day runs through closed Monday until Tuesday opens.
        $this->assertEquals(Carbon::parse('2026-10-27 10:00'), BusinessDay::nextOpening($this->week(), '2026-10-25'));
        $this->assertSame('2026-10-25', BusinessDay::current($this->week(), Carbon::parse('2026-10-26 18:00')));
    }

    public function test_a_branch_with_no_hours_uses_the_calendar_day(): void
    {
        $this->assertSame('2026-10-25', BusinessDay::current([], Carbon::parse('2026-10-25 01:00')));
        $this->assertNull(BusinessDay::nextOpening([], '2026-10-24'));
    }

    // Hours with closing times: Sat 10 AM–10 PM, Fri 10 AM–midnight, Sun 2 PM–2 AM (past midnight).
    private function hours(): array
    {
        return [
            'Friday' => ['opening_time' => '10:00:00', 'closing_time' => '00:00:00', 'is_closed' => false],
            'Saturday' => ['opening_time' => '10:00:00', 'closing_time' => '22:00:00', 'is_closed' => false],
            'Sunday' => ['opening_time' => '14:00:00', 'closing_time' => '02:00:00', 'is_closed' => false],
            'Monday' => ['opening_time' => null, 'closing_time' => null, 'is_closed' => true],
        ];
    }

    public function test_staff_can_be_timed_in_only_while_the_branch_is_open(): void
    {
        $this->assertTrue(BusinessDay::isOpen($this->hours(), Carbon::parse('2026-10-24 12:00')));   // Saturday, open
        $this->assertFalse(BusinessDay::isOpen($this->hours(), Carbon::parse('2026-10-24 23:00')));  // after 10 PM
        $this->assertFalse(BusinessDay::isOpen($this->hours(), Carbon::parse('2026-10-25 01:00')));  // Sunday, before 2 PM
        $this->assertTrue(BusinessDay::isOpen($this->hours(), Carbon::parse('2026-10-26 01:30')));   // Sunday's hours run past midnight
        $this->assertFalse(BusinessDay::isOpen($this->hours(), Carbon::parse('2026-10-26 03:00')));  // closed Monday
        $this->assertTrue(BusinessDay::isOpen($this->hours(), Carbon::parse('2026-10-23 23:59')));   // Friday until midnight
    }

    public function test_it_says_when_the_branch_opens_next(): void
    {
        $this->assertEquals(Carbon::parse('2026-10-25 14:00'), BusinessDay::opensNext($this->hours(), Carbon::parse('2026-10-24 23:00')));
    }
}
