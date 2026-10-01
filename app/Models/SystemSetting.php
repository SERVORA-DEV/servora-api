<?php

namespace App\Models;

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
        'upgrade_cutoff_days',
        'downgrade_notice_days',
    ];

    protected function casts(): array
    {
        return [
            'subscription_grace_period_days' => 'integer',
            'almost_due_notify_enabled' => 'boolean',
            'almost_due_notify_days_before' => 'integer',
            'almost_due_repeat_enabled' => 'boolean',
            'almost_due_repeat_every_days' => 'integer',
            'plan_changes_enabled' => 'boolean',
            'upgrade_cutoff_days' => 'integer',
            'downgrade_notice_days' => 'integer',
        ];
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
            'upgrade_cutoff_days' => 1,
            'downgrade_notice_days' => 3,
        ]);
    }
}
