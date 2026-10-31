<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasUuids, SoftDeletes;

    public function uniqueIds()
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'category',
        'name',
        'description',
        'monthly_price',
        'yearly_price',
        'billing_cycle',
        'max_branches',
        'max_user_accounts',
        'package_access',
        'reward_access',
        'review_access',
        'report_access',
        'report_export',
        'mobile_app_access',
        'app_booking_limit', 'export_mode', 'export_days', 'extra_branch_price', 'extra_account_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'app_booking_limit' => 'integer', 'export_days' => 'integer',
            'extra_branch_price' => 'decimal:2', 'extra_account_price' => 'decimal:2',
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',

            'package_access' => 'boolean',

            'reward_access' => 'boolean',
            'review_access' => 'boolean',

            'report_access' => 'boolean',
            'report_export' => 'boolean',

            'mobile_app_access' => 'boolean',

            'is_active' => 'boolean',
        ];
    }

    // The Free Trial plan: never sold, never switched to, always free.
    public const TRIAL_CATEGORY = 'Trial';

    public function isTrial(): bool
    {
        return $this->category === self::TRIAL_CATEGORY;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'subscription_plan_id');
    }
}
