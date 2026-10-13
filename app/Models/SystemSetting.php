<?php

namespace App\Models;

use App\Support\ListingPolicy;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'subscription_grace_period_days',
        'almost_due_notify_enabled',
        'almost_due_notify_days_before',
        'almost_due_repeat_enabled',
        'almost_due_repeat_every_days',
        // Subscription Policy — see PlanSwitchService.
        'plan_changes_enabled',
        'plan_change_grace_days',
        // Marketplace Policy — see App\Support\ListingPolicy.
        'listing_policy',
    ];

    public const DEFAULT_PLAN_CHANGE_GRACE_DAYS = 7;

    protected function casts(): array
    {
        return [
            'subscription_grace_period_days' => 'integer',
            'almost_due_notify_enabled' => 'boolean',
            'almost_due_notify_days_before' => 'integer',
            'almost_due_repeat_enabled' => 'boolean',
            'almost_due_repeat_every_days' => 'integer',
            'plan_changes_enabled' => 'boolean',
            'plan_change_grace_days' => 'integer',
            'listing_policy' => 'array',
        ];
    }

    // `plan_change_grace_days` is no longer a rule: owners may upgrade any
    // time and schedule a downgrade any time before the due date
    // (PlanSwitchService). The column stays so no migration is needed;
    // nothing reads it.

    // Which listing items a branch must have to be shown in the client app.
    // Always complete: the defaults fill in for anything not stored, and for
    // a database where the column isn't there yet (migration not run).
    public function listingPolicy(): array
    {
        return ListingPolicy::normalize($this->listing_policy);
    }

    // Singleton accessor — creates the one row with defaults on first read
    // instead of relying on a seeder, so a fresh install works out of the box.
    public static function current(): self
    {
        return self::firstOrCreate([], [
            'subscription_grace_period_days' => 7,
            'almost_due_notify_enabled' => true,
            'almost_due_notify_days_before' => 3,
            'almost_due_repeat_enabled' => true,
            'almost_due_repeat_every_days' => 1,
            'plan_changes_enabled' => true,
            'plan_change_grace_days' => self::DEFAULT_PLAN_CHANGE_GRACE_DAYS,
        ]);
    }
}
