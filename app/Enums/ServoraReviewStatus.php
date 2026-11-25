<?php

namespace App\Enums;

/**
 * An owner's review of SERVORA itself: servora_reviews.status (ServoraReviewService).
 */
enum ServoraReviewStatus: string
{
    // Legacy value. These reviews are public alongside Published reviews.
    case Pending = 'Pending';
    case Published = 'Published';
    case Hidden = 'Hidden';
}
