<?php

namespace App\Enums;

/**
 * A therapist's part in one service: therapist_assignments.assignment_status.
 */
enum AssignmentStatus: string
{
    // Given the service, not started yet.
    case Assigned = 'Assigned';
    case InProgress = 'In Progress';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';
}
