<?php

namespace App\Repository\Business;

use App\Models\Addon;

class AddonRepository
{
    // Add-ons belong to the whole business (not a branch): owner and manager
    // see the same list, like the service catalog.
    public function listForBusiness(int $spaBusinessId, bool $activeOnly = false)
    {
        return Addon::with('services:id,uuid')
            ->where('spa_business_id', $spaBusinessId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    // 404s instead of returning (or letting someone edit) another business's
    // add-on just because its uuid was guessed.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId): Addon
    {
        return Addon::with('services:id,uuid')
            ->where('uuid', $uuid)
            ->where('spa_business_id', $spaBusinessId)
            ->firstOrFail();
    }

    public function create(array $payload): Addon
    {
        return Addon::create($payload);
    }
}
