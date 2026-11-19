<?php

namespace App\Enums;

/**
 * A room or bed: facilities.status.
 */
enum FacilityStatus: string
{
    case Available = 'Available';
    // Can't be booked until it is back.
    case Maintenance = 'Maintenance';
}
