<?php

namespace App\Support;

use App\Models\SystemSetting;
use Carbon\Carbon;

// When an owner is reminded about their Servora plan. The whole rule is here,
// with no database behind it, so the daily job, the settings screens and the
// tests all read the same thing:
//
//   FIRST        N days before it is due (the administrator sets N)
//   DAY_BEFORE   the day before
//   DUE_TODAY    on the due day
//   OVERDUE      every R days while the plan has lapsed but access continues
//                (the grace period; the administrator sets R and its length)
//   ENDED        once, when the grace period is over and access is paused
//
// The first three are a courtesy: the administrator can switch them off for
// everyone, and an owner for themselves. OVERDUE and ENDED are about losing
// access and always go out.
//
// Variations:
//   - A free trial has no grace period: the three courtesy moments, then
//     ENDED the moment it lapses.
//   - With auto-renew on there is nothing for the owner to do, so they get
//     FIRST only ("we'll charge your card on the 30th"). If the charge fails
//     and the plan lapses, OVERDUE and ENDED apply as for anyone.
//
// One timestamp is enough to keep track: when the last reminder went out. A
// moment is sent when that is from before the moment began. Only the current
// moment is ever sent, so a job that missed a few days doesn't send a backlog.
final class SubscriptionReminderSchedule
{
    public const FIRST = 'first';
    public const DAY_BEFORE = 'day_before';
    public const DUE_TODAY = 'due_today';
    public const OVERDUE = 'overdue';
    public const ENDED = 'ended';

    public function __construct(
        // FIRST goes out this many days before the due date.
        public readonly int $firstDays,
        // Time between OVERDUE reminders.
        public readonly int $overdueEveryDays,
        // How long access continues after the due date.
        public readonly int $graceDays,
        // The administrator's switch for the three courtesy moments.
        public readonly bool $earlyEnabled,
    ) {
    }

    public static function fromSettings(SystemSetting $settings): self
    {
        return new self(
            firstDays: max(1, (int) $settings->almost_due_notify_days_before),
            overdueEveryDays: max(1, (int) $settings->almost_due_repeat_every_days),
            graceDays: max(0, (int) $settings->subscription_grace_period_days),
            earlyEnabled: (bool) $settings->almost_due_notify_enabled,
        );
    }

    /**
     * The moment to send now for one subscription, or null for none.
     *
     * @param  Carbon  $expiresAt  When the plan (or trial) is due.
     * @param  Carbon|null  $lastSentAt  When this subscription was last reminded.
     * @param  bool  $ownerWantsEarly  The owner's own "Remind me before my plan is due".
     */
    public function due(Carbon $expiresAt, ?Carbon $lastSentAt, Carbon $now, bool $isTrial = false, bool $autoRenew = false, bool $ownerWantsEarly = true): ?string
    {
        $dueDay = $expiresAt->copy()->startOfDay();
        $endsAt = $expiresAt->copy()->addDays($isTrial ? 0 : $this->graceDays);
        $notSince = fn (Carbon $moment) => $lastSentAt === null || $lastSentAt->lt($moment);

        // ── After the due date: always ─────────────────────────────────
        if ($now->gte($endsAt)) {
            return $notSince($endsAt) ? self::ENDED : null;
        }
        if ($now->gte($expiresAt)) {
            // The first one as soon as it lapses, then every R days. The hour
            // of slack keeps a daily run from skipping a day because
            // yesterday's send happened a few seconds later than today's check.
            $again = $now->copy()->subDays($this->overdueEveryDays)->addHour();

            return $notSince($expiresAt) || $lastSentAt->lte($again) ? self::OVERDUE : null;
        }

        // ── Before the due date: a courtesy ────────────────────────────
        if (! $this->earlyEnabled || ! $ownerWantsEarly) {
            return null;
        }

        $first = $dueDay->copy()->subDays($this->firstDays);
        if ($now->lt($first)) {
            return null;
        }

        // Auto-renew: the one notice, whenever in the run-up it is first seen.
        if ($autoRenew && ! $isTrial) {
            return $notSince($first) ? self::FIRST : null;
        }

        if ($now->gte($dueDay)) {
            return $notSince($dueDay) ? self::DUE_TODAY : null;
        }

        $dayBefore = $dueDay->copy()->subDay();
        if ($now->gte($dayBefore)) {
            return $notSince($dayBefore) ? self::DAY_BEFORE : null;
        }

        return $notSince($first) ? self::FIRST : null;
    }

    /** Whole days from today to the due day: 0 on the day, 1 the day before. */
    public static function daysLeft(Carbon $expiresAt, Carbon $now): int
    {
        return max(0, (int) $now->copy()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false));
    }

    // "7 days before, the day before and on the due day" — the courtesy
    // moments in words, for the settings screens.
    public function earlyInWords(): string
    {
        return $this->firstDays > 1
            ? "{$this->firstDays} days before, the day before and on the due day"
            : 'the day before and on the due day';
    }
}
