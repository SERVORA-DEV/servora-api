<?php

namespace App\Support;

use Carbon\CarbonInterface;

// Where a business's subscription stands right now, for the administrator's
// Subscribers list. Worked out from the business's LATEST subscription row —
// a renewal adds a row and nothing flips an old one to Expired, so the dates
// decide, not the status column alone (see
// SubscriptionRepository::countOverdue).
//
// The five states don't overlap, so their counts add up to every subscriber:
//   active         paid plan, more than RENEWING_SOON_DAYS left
//   trial          free trial still running
//   renewing_soon  paid plan ending within RENEWING_SOON_DAYS
//   overdue        paid plan past its end date, still inside the grace period
//                  (the business still has access — EnsureBusinessSubscribed)
//   lapsed         past the grace period, cancelled, ended by the owner's
//                  choice, or a trial that ended with no plan bought
//
// No database here, so the rules can be tested on their own.
final class SubscriberState
{
    public const ACTIVE = 'active';
    public const TRIAL = 'trial';
    public const RENEWING_SOON = 'renewing_soon';
    public const OVERDUE = 'overdue';
    public const LAPSED = 'lapsed';

    public const ALL = [self::ACTIVE, self::TRIAL, self::RENEWING_SOON, self::OVERDUE, self::LAPSED];

    // The same window the dashboard's "Ending soon" list uses.
    public const RENEWING_SOON_DAYS = 14;

    /**
     * @param  bool  $endingByChoice  the owner declined to carry on (no grace, no reminders)
     * @param  ?string  $renewalFailure  why the last automatic renewal could not be charged
     * @return array{state: string, note: string} the state, and one short line saying why
     */
    public static function of(
        bool $isTrial,
        string $status,
        ?CarbonInterface $expiresAt,
        bool $autoRenew,
        bool $endingByChoice,
        ?string $renewalFailure,
        int $graceDays,
        ?CarbonInterface $now = null,
    ): array {
        $now ??= now();
        $running = $status === 'Active' && ($expiresAt === null || $expiresAt->gt($now));

        if ($status === 'Cancelled') {
            return self::is(self::LAPSED, 'Cancelled');
        }

        if ($isTrial) {
            return $running
                ? self::is(self::TRIAL, 'Free trial')
                : self::is(self::LAPSED, 'Trial ended, no plan bought');
        }

        if ($status !== 'Active') {
            return self::is(self::LAPSED, 'Did not renew');
        }

        if ($running) {
            if ($expiresAt === null || $expiresAt->gt($now->copy()->addDays(self::RENEWING_SOON_DAYS))) {
                return self::is(self::ACTIVE, $autoRenew ? 'Renews automatically' : 'Owner renews by hand');
            }

            return self::is(self::RENEWING_SOON, match (true) {
                $endingByChoice => 'Ending by the owner\'s choice',
                filled($renewalFailure) => 'Automatic renewal failed',
                $autoRenew => 'Renews automatically',
                default => 'Owner has to renew by hand',
            });
        }

        // Past the end date.
        if ($endingByChoice) {
            return self::is(self::LAPSED, 'Ended by the owner\'s choice');
        }
        if ($expiresAt->gt($now->copy()->subDays($graceDays))) {
            return self::is(self::OVERDUE, filled($renewalFailure) ? 'Automatic renewal failed' : 'Still has access (grace period)');
        }

        return self::is(self::LAPSED, 'Did not renew');
    }

    /** @return array{state: string, note: string} */
    private static function is(string $state, string $note): array
    {
        return ['state' => $state, 'note' => $note];
    }
}
