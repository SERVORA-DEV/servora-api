<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
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
            'plan' => $this->whenLoaded('plan', fn () => new SubscriptionPlanResource($this->plan)),
            // Owner's downgrade taking effect at the next billing (PlanSwitchService).
            'scheduled_plan' => $this->whenLoaded('scheduledPlan', fn () => $this->scheduledPlan ? new SubscriptionPlanResource($this->scheduledPlan) : null),
            'billings' => $this->whenLoaded('billings', fn () => BillingResource::collection($this->billings)),
        ];
    }
}