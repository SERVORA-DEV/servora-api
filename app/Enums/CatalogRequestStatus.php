<?php

namespace App\Enums;

/**
 * A manager's request for a service or package the catalog doesn't have:
 * catalog_requests.status (CatalogRequestService).
 */
enum CatalogRequestStatus: string
{
    case Pending = 'pending';
    // The owner added it to the catalog.
    case Added = 'added';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
