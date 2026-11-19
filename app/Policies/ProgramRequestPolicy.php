<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AnsweredByTheOwner;
use Illuminate\Auth\Access\Response;

// A manager asking the owner for a customer program (ProgramRequestService).
class ProgramRequestPolicy
{
    use AnsweredByTheOwner;

    public function create(User $user): Response
    {
        return $this->onlyManagers($user, 'Only a branch manager sends a request. The owner creates programs directly.');
    }

    // Approving or declining.
    public function decide(User $user): Response
    {
        return $this->onlyTheOwner($user, 'Only the owner can answer a request.');
    }
}
