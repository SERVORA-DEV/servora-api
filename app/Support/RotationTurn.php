<?php

namespace App\Support;

// Whose turn it is in the staff rotation (TherapistQueueCalculator builds the
// line; this says who at the front of it can take the next client).
//
// Someone can take the next client when they are on the floor and not in the
// middle of a service. Being assigned to a booking that hasn't started — a
// reservation later today, a client still waiting — does NOT take them out:
// they are standing free right now, so they are still next up. Only starting
// a service does, and finishing it sends them to the back of the line.
//
// Pure — tested in tests/Unit/RotationTurnTest; mirrored on the site in
// app/utils/rotationTurn.ts (keep the two in step).
class RotationTurn
{
    public const FREE = 'free';
    public const ASSIGNED = 'assigned';
    public const IN_SERVICE = 'in_service';

    /** Whether someone with this rotation status can take the next client. */
    public static function canTakeNext(bool $inQueue, ?string $rotationStatus): bool
    {
        return $inQueue && $rotationStatus !== self::IN_SERVICE;
    }

    /**
     * The first row, in the order given (the line), that can take the next
     * client — or null when everyone on the floor is mid-service.
     *
     * @param  iterable<array{in_queue?: bool, rotation_status?: ?string}>  $rows
     */
    public static function nextUp(iterable $rows): ?array
    {
        foreach ($rows as $row) {
            if (self::canTakeNext((bool) ($row['in_queue'] ?? false), $row['rotation_status'] ?? self::FREE)) {
                return $row;
            }
        }

        return null;
    }
}
