<?php

namespace App\Http\Resources\System;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// System-admin listing shape — distinct from the owner-facing
// SpaBusinessResource, which doesn't expose owner/subscription/branch-count
// (not needed on the owner's own "my business" view, but this is the whole
// point of the admin list).
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'business_name' => $this->business_name,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'verification_status' => $this->verification_status,
            'operating_status' => $this->operating_status,
            'created_at' => $this->created_at,
            'branches_count' => $this->branches_count ?? 0,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'first_name' => $this->owner->first_name,
                'last_name' => $this->owner->last_name,
                'email' => $this->owner->email,
            ] : null),
            'subscription_plan' => $this->whenLoaded('activeSubscription', fn () => $this->activeSubscription?->plan ? [
                'name' => $this->activeSubscription->plan->name,
                'category' => $this->activeSubscription->plan->category,
            ] : null),
        ];
    }
}
