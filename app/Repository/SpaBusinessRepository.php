<?php

namespace App\Repository;

use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Support\AppCache;
use Illuminate\Support\Collection;

class SpaBusinessRepository
{
    public function create(array $payload)
    {
        return SpaBusiness::create($payload);
    }

    // One lookup per request: EnsureBusinessVerified, EnsureBusinessSubscribed
    // and the controller's service all ask for the owner's business, and the
    // database is a network round trip away. Remembered on the current HTTP
    // request only (never in queue workers or commands, which have no route),
    // and only once found — a business created later in the request is still
    // picked up.
    public function findByOwnerId(int $ownerId)
    {
        $request = app()->bound('request') ? request() : null;
        $memo = $request?->route() ? "servora.business_of_owner:{$ownerId}" : null;

        if ($memo && $request->attributes->has($memo)) {
            return $request->attributes->get($memo);
        }

        $business = SpaBusiness::where('owner_id', $ownerId)->first();
        if ($memo && $business) {
            $request->attributes->set($memo, $business);
        }

        return $business;
    }

    public function update(SpaBusiness $business, array $payload): SpaBusiness
    {
        $business->update($payload);

        return $business->fresh();
    }

    // Resolves "the business this request is scoped to" for any of the
    // roles allowed into the business/* API — business_owner is
    // SpaBusiness.owner_id directly; manager (and front_officer, should it
    // ever need this) has no owner_id of its own and is instead linked via
    // User::staff -> SpaBranch -> SpaBusiness (see AccountService::
    // createAccount, which is what populates staff.user_id). Services
    // that used to call findByOwnerId($user->id) directly should call this
    // instead so manager requests resolve the same business the owner sees,
    // rather than always coming back null.
    //
    // A staff login's business is the one its branches belong to
    // (App\Support\AccountScope).
    public function findForUser(User $user): ?SpaBusiness
    {
        if ($user->role === 'business_owner') {
            return $this->findByOwnerId($user->id);
        }

        return \App\Support\AccountScope::business($user);
    }

    // Single source of truth for "which branches can this user see" — every
    // scoped endpoint (staff list, branch list, dashboard, ...) should go
    // through this rather than re-deriving it: business_owner gets every
    // branch of their business, manager/front_officer gets only their own
    // staff record's branch. Empty collection if the user has no
    // business/branch resolved at all (mirrors findForUser's null case).
    //
    // A staff login gets the branches the owner gave it — on Management-only
    // routes, only those it has Management at (App\Support\AccountScope).
    //
    // The owner working the front desk (Operation mode on the web) works one
    // branch: when the request names one of theirs (X-Branch), only that one.
    public function branchesForUser(User $user): Collection
    {
        if ($user->role === 'business_owner') {
            $business = $this->findByOwnerId($user->id);
            $all = $business ? SpaBranch::where('spa_business_id', $business->id)->get() : collect();
            $picked = request()?->header('X-Branch');
            $only = $picked ? $all->where('uuid', $picked)->values() : collect();

            return $only->isNotEmpty() ? $only : $all;
        }

        return \App\Support\AccountScope::branches($user);
    }

    // Just the ids — all most callers need, to scope their own queries.
    //
    // Almost every business/* request asks this, and the database is a
    // network round trip away, so an owner's list is kept between requests
    // (AppCache). The key carries the business's version, which changes
    // whenever one of its branches is saved, removed or restored
    // (CacheInvalidation), so a list from before such a change is never read;
    // the short lifetime only covers writes that fire no model event.
    public function branchIdsForUser(User $user): array
    {
        if ($user->role !== 'business_owner') {
            return $this->branchesForUser($user)->pluck('id')->all();
        }

        $business = $this->findByOwnerId($user->id);
        if (! $business) {
            return [];
        }

        $ids = AppCache::remember(
            "biz:{$business->id}:branch_ids:v" . AppCache::businessVersion($business->id),
            300,
            fn () => SpaBranch::where('spa_business_id', $business->id)->pluck('id')->all(),
        );

        // Working one branch (see branchesForUser).
        $picked = request()?->header('X-Branch');
        if ($picked) {
            $id = SpaBranch::where('spa_business_id', $business->id)->where('uuid', $picked)->value('id');
            if ($id && in_array($id, $ids, true)) {
                return [$id];
            }
        }

        return $ids;
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
