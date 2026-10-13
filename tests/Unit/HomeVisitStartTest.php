<?php

namespace Tests\Unit;

use App\Support\HomeVisitStart;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// Starting a home visit by scanning the client's QR: the code, what a scan
// (or typing) is read as, and when a booking can't be started that way.
class HomeVisitStartTest extends TestCase
{
    public function test_a_code_is_eight_characters_with_none_that_look_alike(): void
    {
        foreach (range(1, 200) as $_) {
            $code = HomeVisitStart::newCode();
            $this->assertSame(8, strlen($code));
            $this->assertSame(8, strspn($code, HomeVisitStart::ALPHABET));
        }
        foreach (['I', 'L', 'O', '0', '1'] as $confusing) {
            $this->assertStringNotContainsString($confusing, HomeVisitStart::ALPHABET);
        }
    }

    public function test_codes_are_not_repeated_in_practice(): void
    {
        $codes = array_map(fn () => HomeVisitStart::newCode(), range(1, 500));
        $this->assertCount(500, array_unique($codes));
    }

    public function test_the_qr_holds_the_code_behind_a_prefix_and_reads_back(): void
    {
        $this->assertSame('SERVORA-HV:ABCD2345', HomeVisitStart::qrText('ABCD2345'));
        $this->assertSame('ABCD2345', HomeVisitStart::read('SERVORA-HV:ABCD2345'));
    }

    public function test_a_typed_code_is_read_whatever_its_case_dash_or_spaces(): void
    {
        $this->assertSame('ABCD2345', HomeVisitStart::read('ABCD2345'));
        $this->assertSame('ABCD2345', HomeVisitStart::read(' abcd-2345 '));
        $this->assertSame('ABCD2345', HomeVisitStart::read('abcd 2345'));
        $this->assertSame('ABCD2345', HomeVisitStart::read('servora-hv:abcd-2345'));
    }

    public function test_anything_else_is_not_a_code(): void
    {
        $this->assertNull(HomeVisitStart::read('https://example.com/menu'));
        $this->assertNull(HomeVisitStart::read('ABCD234'));       // too short
        $this->assertNull(HomeVisitStart::read('ABCD23456'));     // too long
        $this->assertNull(HomeVisitStart::read('ABCD234O'));      // a letter no code has
        $this->assertNull(HomeVisitStart::read('ABCD234!'));
        $this->assertNull(HomeVisitStart::read(''));
        $this->assertNull(HomeVisitStart::read(null));
        $this->assertNull(HomeVisitStart::read(12345678));
    }

    public function test_it_is_written_in_two_halves(): void
    {
        $this->assertSame('ABCD-2345', HomeVisitStart::display('ABCD2345'));
        $this->assertSame('', HomeVisitStart::display(null));
    }

    public function test_a_booking_for_today_can_be_started(): void
    {
        $today = Carbon::parse('2026-10-20 14:05');
        $this->assertNull(HomeVisitStart::problem('scheduled', Carbon::parse('2026-10-20'), $today));
        // The front desk already checked it in: still fine.
        $this->assertNull(HomeVisitStart::problem('checked_in', Carbon::parse('2026-10-20'), $today));
    }

    public function test_a_booking_for_another_day_says_which(): void
    {
        $today = Carbon::parse('2026-10-20 14:05');
        $this->assertSame('This booking is for Wed, Oct 21, not today.', HomeVisitStart::problem('scheduled', Carbon::parse('2026-10-21'), $today));
        $this->assertSame('This booking is for Mon, Oct 19, not today.', HomeVisitStart::problem('scheduled', Carbon::parse('2026-10-19'), $today));
    }

    public function test_a_booking_that_is_over_or_running_says_so(): void
    {
        $day = Carbon::parse('2026-10-20');
        $this->assertSame('This booking was cancelled.', HomeVisitStart::problem('cancelled', $day, $day));
        $this->assertSame('This booking was marked as a no-show. Call the front desk.', HomeVisitStart::problem('no_show', $day, $day));
        $this->assertSame('This visit is already finished.', HomeVisitStart::problem('completed', $day, $day));
        $this->assertSame('This visit has already started.', HomeVisitStart::problem('in_service', $day, $day));
        $this->assertSame('This booking can\'t be started. Call the front desk.', HomeVisitStart::problem('something_new', $day, $day));
    }

    public function test_the_state_of_the_booking_is_said_before_the_day(): void
    {
        // A cancelled booking of another day is "cancelled", not "another day".
        $this->assertSame('This booking was cancelled.', HomeVisitStart::problem('cancelled', Carbon::parse('2026-10-21'), Carbon::parse('2026-10-20')));
    }
}
