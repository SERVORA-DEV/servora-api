<?php

namespace App\Enums;

/**
 * A checked-in client's place in the front desk's queue: queues.queue_status.
 */
enum QueueStatus: string
{
    case Waiting = 'Waiting';
    // Called to the desk, not yet being served.
    case Called = 'Called';
    case Serving = 'Serving';
    case Completed = 'Completed';
    // Called but didn't come; can be called back.
    case Skipped = 'Skipped';
    case Cancelled = 'Cancelled';
}
