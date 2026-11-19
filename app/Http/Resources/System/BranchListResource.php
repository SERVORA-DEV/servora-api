<?php

namespace App\Http\Resources\System;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin Branches list and its map (GET /system/branches): only what they show.
 * Opening a branch reads the whole of it (SpaBranchResource, GET /system/branches/{uuid}).
 */
class BranchListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $business = $this->business;
        $owner = $business?->owner;
        $manager = $this->manager;

        return [
            'uuid' => $this->uuid,
            'branch_name' => $this->branch_name,
            'code' => $this->code,
            'formatted_address' => $this->formatted_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'verification_status' => $this->verification_status,
            'operating_status' => $this->operating_status,
            'permit_number' => $this->permit_number,
            'verified_at' => $this->verified_at,
            'business' => $business ? [
                'uuid' => $business->uuid,
                'business_name' => $business->business_name,
                'owner' => $owner ? ['first_name' => $owner->first_name, 'last_name' => $owner->last_name, 'email' => $owner->email] : null,
            ] : null,
            'manager' => $manager ? ['first_name' => $manager->first_name, 'last_name' => $manager->last_name, 'email' => $manager->email] : null,
        ];
    }
}
