<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            // True when this plan has at least one subscription attached.
            // The admin UI should use this to warn that editing will create
            // a new version (and archive this one) instead of a plain
            // in-place edit — see SubscriptionPlanService::updateSubscriptionPlan().
            'has_subscribers' => ($this->subscriptions_count ?? 0) > 0,
        ];
    }
}
