<?php

namespace App\Enums;

/**
 * One staff member's day: attendances.status, and attendances.resolved_status once the
 * day has been worked out (AttendanceStatusCalculator).
 */
enum AttendanceStatus: string
{
    case Present = 'Present';
    case Late = 'Late';
    case Absent = 'Absent';
    case HalfDay = 'Half Day';
    case OnLeave = 'On Leave';
    case Holiday = 'Holiday';
    // Covering for someone else that day.
    case FillIn = 'Fill In';
}
