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
    // Stopped part-way and gave the client to another therapist while the service was paused
    // (App\Support\Visits\ServicePause). Keeps when they worked; earns nothing — the therapist
    // who finishes the service is paid for it (CommissionCalculator pays Completed rows only).
    case HandedOver = 'Handed Over';
}
