<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // payments is eager-loaded newest-first (see
        // BillingRepository::paginateSubscriptionTransactions) — first() is
        // the latest attempt, since a billing can have more than one (e.g.
        // a retry after a failed charge).
        $payment = $this->payments->first();
        $subscription = $this->subscription;
        $plan = $subscription?->plan;
        $business = $subscription?->business;
        $owner = $business?->owner;

        return [
            'uuid' => $this->uuid,
            'invoice' => $this->billing_number,
            'reference_number' => $payment?->reference_number,
            // Groups billings back to their subscription so the frontend can
            // tell a first charge from a renewal (a subscription with more
            // than one billing has been renewed) — see useSystemTransactions.ts.
            'subscription_id' => $subscription?->uuid,
            'spa_business' => $business?->business_name,
            'owner_first_name' => $owner?->first_name,
            'owner_last_name' => $owner?->last_name,
            'subscription_plan' => $plan?->category,
            'amount' => (float) $this->amount,
            'payment_method' => $payment?->payment_method,
            'status' => strtolower($this->status),
            'payment_date' => optional($this->paid_at)->toIso8601String(),
            'renewal_date' => optional($subscription?->expires_at)->toIso8601String(),
        ];
    }
}
