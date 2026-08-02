<?php

namespace App\Repository\System;

use App\Models\SpaBranch;

class BranchRegistrationRepository
{
    // "Pending Registrations" list — only branches currently awaiting
    // review, not every branch regardless of status.
    public function paginatePending(int $perPage = 15)
    {
        return SpaBranch::with('business.owner')
            ->where('verification_status', 'Pending')
            ->latest()
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid): SpaBranch
    {
        return SpaBranch::with('business.owner', 'schedules', 'verifier')
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function update(string $uuid, array $payload): SpaBranch
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model;
    }
}
