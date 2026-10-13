<?php

namespace Tests\Unit;

use App\Support\History;
use PHPUnit\Framework\TestCase;

// A record's own copy comes first; the live value is only a fallback.
class HistoryTest extends TestCase
{
    public function test_the_records_own_copy_wins_over_the_live_value(): void
    {
        $this->assertSame('Swedish Massage', History::own('Swedish Massage', 'Relaxing Massage'));
        $this->assertSame(60, History::own(60, 75));
    }

    public function test_a_record_with_no_copy_falls_back_to_the_live_value(): void
    {
        $this->assertSame('Relaxing Massage', History::own(null, 'Relaxing Massage'));
        $this->assertSame('Relaxing Massage', History::own('', 'Relaxing Massage'));
        $this->assertNull(History::own(null, null));
    }

    public function test_zero_and_false_are_copies_not_gaps(): void
    {
        // A therapist who earned nothing, a day with no late minutes.
        $this->assertSame(0.0, History::own(0.0, 320.0));
        $this->assertSame(0, History::own(0, 15));
        $this->assertFalse(History::own(false, true));
    }

    public function test_the_live_value_is_only_looked_up_when_needed(): void
    {
        $asked = 0;
        $live = function () use (&$asked) { $asked++; return 'live'; };

        $this->assertSame('copy', History::own('copy', $live));
        $this->assertSame(0, $asked);
        $this->assertSame('live', History::own(null, $live));
        $this->assertSame(1, $asked);
    }

    public function test_a_persons_name(): void
    {
        $this->assertSame('Lara Mendoza', History::person(' Lara ', 'Mendoza'));
        $this->assertSame('Lara', History::person('Lara', null));
        $this->assertNull(History::person(null, ' '));
    }
}
