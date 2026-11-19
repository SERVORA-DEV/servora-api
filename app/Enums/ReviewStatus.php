<?php

namespace App\Enums;

/**
 * A client's review of a branch: reviews.status.
 */
enum ReviewStatus: string
{
    case Published = 'Published';
    case Hidden = 'Hidden';
}
