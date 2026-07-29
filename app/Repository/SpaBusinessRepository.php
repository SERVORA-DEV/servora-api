<?php

namespace App\Repository;

use App\Models\SpaBusiness;

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
}
