<?php

namespace App\Enums;

/**
 * An owner's review of SERVORA itself: servora_reviews.status (ServoraReviewService).
 */
enum ServoraReviewStatus: string
{
    // Waiting for an administrator to publish or hide it.
    case Pending = 'Pending';
    case Published = 'Published';
    case Hidden = 'Hidden';
}
