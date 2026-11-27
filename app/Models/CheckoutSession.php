<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// One subscription payment attempt through Servora's checkout (or an
// automatic renewal charge): what's being bought and for how much, kept
// until Xendit confirms it. CheckoutService::complete() turns a completed
// one into the subscription, invoice and saved payment method.
class CheckoutSession extends Model
{
    use HasUuids;

    public const PURPOSES = ['subscribe', 'renew', 'upgrade', 'save_method', 'auto_renew', 'add_slots'];

    protected $fillable = [
        'uuid', 'spa_business_id', 'purpose', 'subscription_id', 'subscription_plan_id', 'billing_cycle',
        'amount', 'vat_amount', 'save_method', 'enable_auto_renew', 'payment_method_id', 'reference_id',
        'xendit_session_id', 'xendit_payment_request_id', 'status', 'failure_reason', 'completed_at', 'meta',
    ];

    protected $hidden = ['pending_renewal_email'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'save_method' => 'boolean',
            'enable_auto_renew' => 'boolean',
            'completed_at' => 'datetime',
            'meta' => 'array',
            'pending_renewal_email' => 'array',
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
        return in_array($this->status, [CheckoutStatus::Completed->value, CheckoutStatus::Failed->value, CheckoutStatus::Expired->value], true);
    }
}
