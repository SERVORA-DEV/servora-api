<?php

namespace App\Support;

// Who earns loyalty points: clients who use the Servora app. A client "uses
// the app" when their record at the spa is linked to an app account
// (clients.user_id — App\Models\Client::usesApp); the link is made the first
// time they book from the app. Any paid visit of theirs earns — a walk-in or
// a booking the front desk made counts as much as one made on the phone.
//
// A client without the app earns nothing, and holds no points: the balance
// such a client had when this rule came in was taken off (the migration
// 2026_10_18_090000_remove_points_from_clients_without_the_app).
//
// Only points. Vouchers earned by visits, spend or a first visit, and
// memberships, are for every client.
//
// Pure — tested in tests/Unit/LoyaltyEarningTest; applied by
// App\Service\Business\ProgramRewardService::onBillPaid.
class LoyaltyEarning
{
    /** The history line left on a client whose points were taken off. */
    public const REMOVED_NOTE = 'Points removed — loyalty points are for app users';

    /** The type of that history line in client_point_entries. */
    public const REMOVED_TYPE = 'remove';

    /** Whether a paid visit earns this client points. */
    public static function earns(bool $usesApp): bool
    {
        return $usesApp;
    }

    /**
     * The points to take off a client under this rule: the whole balance of
     * one without the app, nothing from an app user (and nothing from an
     * empty balance).
     */
    public static function toRemove(bool $usesApp, int $balance): int
    {
        return $usesApp ? 0 : max(0, $balance);
    }
}
