<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The shape every "a manager asks, the owner answers" request shares (catalog entries, customer
 * programs, schedule changes, moves to another branch): only a manager asks, only the owner
 * answers, and a request is withdrawn by whoever made it, or by the owner.
 *
 * Who may see which requests is not here: that is the services' branch scoping.
 */
trait AnsweredByTheOwner
{
    public function withdraw(User $user, Model $request): Response
    {
        return $this->isOwner($user) || $request->requested_by_user_id === $user->id
            ? Response::allow()
            : Response::deny('Only the person who made this request can withdraw it.');
    }

    private function onlyManagers(User $user, string $message): Response
    {
        return $user->role === 'manager' ? Response::allow() : Response::deny($message);
    }

    private function onlyTheOwner(User $user, string $message): Response
    {
        return $this->isOwner($user) ? Response::allow() : Response::deny($message);
    }

    private function isOwner(User $user): bool
    {
        return $user->role === 'business_owner';
    }
}
