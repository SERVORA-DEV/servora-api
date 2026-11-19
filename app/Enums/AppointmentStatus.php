<?php

namespace App\Enums;

/**
 * Where a visit stands (appointments.status).
 *
 * Scheduled → Checked in → In service → Completed, with Cancelled and No show as the only
 * other ways out of Scheduled (Cancelled also from Checked in, for a client who leaves before
 * the service starts). A visit only becomes Completed once it is paid.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case CheckedIn = 'checked_in';
    case InService = 'in_service';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** What this status may become next. Completed, Cancelled and No show are final. */
    public function next(): array
    {
        return match ($this) {
            self::Scheduled => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::InService, self::Cancelled],
            self::InService => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }
}
