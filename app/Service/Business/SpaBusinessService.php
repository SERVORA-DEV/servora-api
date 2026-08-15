<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\SpaBusinessResource;
use App\Http\Resources\PublicSpaBusinessResource;

class SpaBusinessService
{
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(SpaBusinessRepository $spaBusinessRepository)
    {
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    // Backs GET /business/me — the owner's own business, used by
    // Account Management to build the /login/{business_uuid} link to hand
    // out to newly-created accounts.
    public function getMyBusiness(User $user)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return new SpaBusinessResource($business);
    }

    // Backs GET /business/{uuid}/public — no auth, no ownership check by
    // design (see PublicSpaBusinessResource for what's safe to return here).
    public function getPublicBusiness(string $uuid)
    {
        $business = $this->spaBusinessRepository->findByUuid($uuid);

        if (! $business) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        return new PublicSpaBusinessResource($business);
    }
}
