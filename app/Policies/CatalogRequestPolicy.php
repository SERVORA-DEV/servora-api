<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AnsweredByTheOwner;
use Illuminate\Auth\Access\Response;

// A manager asking the owner to add a service or package to the catalog (CatalogRequestService).
class CatalogRequestPolicy
{
    use AnsweredByTheOwner;

    public function create(User $user): Response
    {
        return $this->onlyManagers($user, 'Only a branch manager sends a request. The owner adds services directly.');
    }

    // Approving or declining.
    public function decide(User $user): Response
    {
        return $this->onlyTheOwner($user, 'Only the owner can answer a request.');
    }
}
