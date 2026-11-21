<?php

namespace Tests\Unit;

use App\Models\BranchSchedule;
use App\Support\Branches\BranchHours;
use PHPUnit\Framework\TestCase;

// The hours a staff shift or a recorded time must keep inside (BranchHours::windowOf). A branch
// open round the clock (12:00 AM – 12:00 AM) has no limit, so a night shift fits. No database.
class BranchHoursWindowTest extends TestCase
{
    private function day(?string $open, ?string $close, bool $closed = false): BranchSchedule
    {
        return (new BranchSchedule())->forceFill(['opening_time' => $open, 'closing_time' => $close, 'is_closed' => $closed]);
    }

    public function test_open_round_the_clock_has_no_limit(): void
    {
        $this->assertNull(BranchHours::windowOf($this->day('00:00', '00:00')));
        $this->assertNull(BranchHours::windowOf($this->day('09:00:00', '09:00:00')));
    }

    public function test_closing_at_midnight_is_the_end_of_that_day(): void
    {
        $this->assertSame(['open' => 540, 'close' => 1440, 'label' => '9:00 AM to 12:00 AM'], BranchHours::windowOf($this->day('09:00', '00:00')));
    }

    public function test_ordinary_overnight_and_closed_days(): void
    {
        $this->assertSame(['open' => 540, 'close' => 1260, 'label' => '9:00 AM to 9:00 PM'], BranchHours::windowOf($this->day('09:00', '21:00')));
        $this->assertNull(BranchHours::windowOf($this->day('22:00', '02:00')));
        $this->assertSame(['closed' => true], BranchHours::windowOf($this->day(null, null, true)));
    }
}
