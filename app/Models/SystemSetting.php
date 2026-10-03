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
        'plan_change_grace_days',
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
        ];
    }

    // Days after subscribing in which an owner may change plan. Falls back to
    // the default when the column isn't there yet (migration not run), so the
    // policy still works instead of treating the window as zero days.
    public function planChangeGraceDays(): int
    {
        return (int) ($this->plan_change_grace_days ?? self::DEFAULT_PLAN_CHANGE_GRACE_DAYS);
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
