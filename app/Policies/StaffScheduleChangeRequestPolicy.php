<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AnsweredByTheOwner;
use Illuminate\Auth\Access\Response;

// A manager's change to a staff member's schedule that waits for the owner (StaffScheduleService).
// Whether a manager's change needs the owner at all is the business's Staff Policies, not this.
class StaffScheduleChangeRequestPolicy
{
    use AnsweredByTheOwner;

    public function approve(User $user): Response
    {
        return $this->onlyTheOwner($user, 'Only the owner can approve a schedule change.');
    }

    public function reject(User $user): Response
    {
        return $this->onlyTheOwner($user, 'Only the owner can reject a schedule change.');
    }
}
