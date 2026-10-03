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

            // Businesses on this exact version right now (paid up, not
            // expired) — only where the admin listing counted them.
            'active_subscribers' => $this->when(isset($this->active_subscribers_count), fn () => (int) $this->active_subscribers_count),

            // The live plan of the tier with the most current subscribers
            // (SubscriptionPlanService::markMostPopular) — replaces the old
            // hard-coded "Premium".
            'is_most_popular' => (bool) ($this->is_most_popular ?? false),

            // The Free Trial plan, and how long a trial lasts (config/trial.php).
            'is_trial' => $this->isTrial(),
            'trial_days' => $this->when($this->isTrial(), fn () => (int) config('trial.days')),
        ];
    }
}
