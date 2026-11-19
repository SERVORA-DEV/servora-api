<?php

namespace App\Enums;

/**
 * A manager's change to a staff schedule, waiting for the owner:
 * staff_schedule_change_requests.status (StaffScheduleService).
 */
enum ScheduleChangeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    // Replaced by a newer request, or overtaken by the owner's own edit.
    case Superseded = 'superseded';
}
