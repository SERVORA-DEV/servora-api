<?php

namespace App\Enums;

/**
 * Where one service (or add-on) on a visit stands: appointment_services.status. A package
 * bought on the visit uses the same four (appointment_packages.status).
 */
enum AppointmentServiceStatus: string
{
    // Waiting to start.
    case Pending = 'Pending';
    case InProgress = 'In Progress';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';
}
