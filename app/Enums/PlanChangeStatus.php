<?php

namespace App\Enums;

/**
 * The owner's answer to an administrator's change to their plan:
 * subscriptions.plan_change_status (PlanChangeService).
 */
enum PlanChangeStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
