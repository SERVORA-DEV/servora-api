<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// One subscription payment attempt through Servora's checkout (or an
// automatic renewal charge): what's being bought and for how much, kept
// until Xendit confirms it. CheckoutService::complete() turns a completed
// one into the subscription, invoice and saved payment method.
class CheckoutSession extends Model
{
    use HasUuids;

    public const PURPOSES = ['subscribe', 'renew', 'upgrade', 'save_method', 'auto_renew'];

    protected $fillable = [
        'uuid', 'spa_business_id', 'purpose', 'subscription_id', 'subscription_plan_id', 'billing_cycle',
        'amount', 'vat_amount', 'save_method', 'enable_auto_renew', 'payment_method_id', 'reference_id',
        'xendit_session_id', 'xendit_payment_request_id', 'status', 'failure_reason', 'completed_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'save_method' => 'boolean',
            'enable_auto_renew' => 'boolean',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(BusinessPaymentMethod::class, 'payment_method_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'expired'], true);
    }
}
