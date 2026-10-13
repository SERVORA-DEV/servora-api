<?php

namespace Tests\Unit;

use App\Support\FrequentTherapist;
use PHPUnit\Framework\TestCase;

// A client's preferred therapist, worked out from who their bookings asked for.
class FrequentTherapistTest extends TestCase
{
    private function asked(int $staffId, int $count, string $lastAt = '2026-10-01 10:00:00'): array
    {
        return ['staff_id' => $staffId, 'count' => $count, 'last_at' => $lastAt];
    }

    public function test_no_requests_means_no_preference(): void
    {
        $this->assertNull(FrequentTherapist::pick([]));
    }

    public function test_asking_once_is_not_a_preference_yet(): void
    {
        $this->assertNull(FrequentTherapist::pick([$this->asked(7, 1)]));
        $this->assertNull(FrequentTherapist::pick([$this->asked(7, 1), $this->asked(8, 1)]));
    }

    public function test_the_therapist_asked_for_most_often_is_preferred(): void
    {
        $pick = FrequentTherapist::pick([$this->asked(7, 2), $this->asked(8, 5), $this->asked(9, 1)]);

        $this->assertSame(['staff_id' => 8, 'count' => 5], $pick);
    }

    public function test_a_tie_goes_to_the_one_asked_for_most_recently(): void
    {
        $pick = FrequentTherapist::pick([
            $this->asked(7, 3, '2026-09-01 10:00:00'),
            $this->asked(8, 3, '2026-10-05 10:00:00'),
        ]);

        $this->assertSame(8, $pick['staff_id']);
    }

    public function test_one_asked_for_once_recently_does_not_beat_a_habit(): void
    {
        $pick = FrequentTherapist::pick([
            $this->asked(7, 2, '2026-08-01 10:00:00'),
            $this->asked(8, 1, '2026-10-08 10:00:00'),
        ]);

        $this->assertSame(7, $pick['staff_id']);
    }
}
