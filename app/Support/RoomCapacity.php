<?php

namespace App\Support;

// How full a facility is. A room holds `max_clients` clients at once, and a
// client is one visit: one visit's several services in a room count once.
//
// Pure: plain numbers in, tested in tests/Unit/RoomCapacityTest. The web has the
// same idea for what is on screen (app/utils/roomCapacity.ts).
final class RoomCapacity
{
    /**
     * The most of these visits that are there at any one moment, so visits one
     * after another don't count against each other. One ending just as another
     * starts are not together.
     *
     * @param  array<int, array{0: int, 1: int}>  $windows  [from, to] of each visit, as timestamps
     */
    public static function peak(array $windows): int
    {
        $events = [];
        foreach ($windows as [$from, $to]) {
            if ($to > $from) {
                $events[] = [$from, 1];
                $events[] = [$to, -1];
            }
        }
        // At the same moment, whoever is leaving leaves first.
        usort($events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $here = 0;
        $most = 0;
        foreach ($events as [, $change]) {
            $here += $change;
            $most = max($most, $here);
        }

        return $most;
    }

    /** Whether someone else can join, with this many already there. */
    public static function hasSpace(int $here, int $limit): bool
    {
        return $here < max(1, $limit);
    }
}
