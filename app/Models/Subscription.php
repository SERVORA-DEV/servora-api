<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',

        'spa_business_id',
        'subscription_plan_id',

        // Admin edited this subscription's plan (see PlanChangeService):
        // the new version, and the owner's answer (pending/accepted/declined).
        'pending_plan_id',
        'plan_change_status',
        'plan_change_responded_at',

        // Owner's own downgrade / billing-cycle switch, taking effect at
        // their next payment (see PlanSwitchService).
        'scheduled_plan_id',
        'scheduled_billing_cycle',
        'scheduled_at',

        'billing_cycle',
        // Free trial (config/trial.php) — see SubscriptionService::startTrial.
        'is_trial',

        'starts_at',
        'expires_at',
        // LAST renewal reminder sent (reminders can repeat — see
        // NotifyAlmostDueSubscriptions).
        'expiry_reminder_sent_at',

        'auto_renew',
        // The saved method auto-renewal charges, and how the last attempts
        // went (SubscriptionRenewalService).
        'payment_method_id',
        'renewal_attempts',
        'last_renewal_attempt_at',
        'renewal_failure_reason',

        'status',

        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_reminder_sent_at' => 'datetime',
            'plan_change_responded_at' => 'datetime',
            'scheduled_at' => 'datetime',

            'is_trial' => 'boolean',
            'auto_renew' => 'boolean',
            'renewal_attempts' => 'integer',
            'last_renewal_attempt_at' => 'datetime',

            'cancelled_at' => 'datetime',
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

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function pendingPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'pending_plan_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(BusinessPaymentMethod::class, 'payment_method_id');
    }

    public function scheduledPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'scheduled_plan_id');
    }

    // Owner chose to let this subscription end at expires_at instead of
    // moving to the updated plan — no grace period or reminders after that.
    public function isEndingByChoice(): bool
    {
        return $this->plan_change_status === 'declined';
    }

    public function billings()
    {
        return $this->hasMany(Billing::class, 'subscription_id');
    }
}
