<?php

namespace App\Repository;

use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Support\Collection;

class SpaBusinessRepository
{
    public function create(array $payload)
    {
        return SpaBusiness::create($payload);
    }

    public function findByOwnerId(int $ownerId)
    {
        return SpaBusiness::where('owner_id', $ownerId)->first();
    }

    // Resolves "the business this request is scoped to" for any of the
    // roles allowed into the business/* API — business_owner is
    // SpaBusiness.owner_id directly; manager (and front_officer, should it
    // ever need this) has no owner_id of its own and is instead linked via
    // User::accountBranch -> SpaBranch -> SpaBusiness (see AccountService::
    // createAccount, which is what populates account_branches). Services
    // that used to call findByOwnerId($user->id) directly should call this
    // instead so manager requests resolve the same business the owner sees,
    // rather than always coming back null.
    public function findForUser(User $user): ?SpaBusiness
    {
        if ($user->role === 'business_owner') {
            return $this->findByOwnerId($user->id);
        }

        return $user->accountBranch?->branch?->business;
    }

    // Single source of truth for "which branches can this user see" — every
    // scoped endpoint (staff list, branch list, dashboard, ...) should go
    // through this rather than re-deriving it: business_owner gets every
    // branch of their business, manager/front_officer gets only their one
    // AccountBranch-assigned branch. Empty collection if the user has no
    // business/branch resolved at all (mirrors findForUser's null case).
    public function branchesForUser(User $user): Collection
    {
        if ($user->role === 'business_owner') {
            $business = $this->findByOwnerId($user->id);
            return $business ? SpaBranch::where('spa_business_id', $business->id)->get() : collect();
        }

        $branch = $user->accountBranch?->branch;
        return $branch ? collect([$branch]) : collect();
    }

    // Public lookup — no owner scoping, since this backs the unauthenticated
    // /business/{uuid}/public endpoint (branded login page). Callers must
    // only ever expose public-safe fields from the result (see
    // PublicSpaBusinessResource), never the full model.
    public function findByUuid(string $uuid)
    {
        return SpaBusiness::where('uuid', $uuid)->first();
    }
}
