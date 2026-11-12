<?php

namespace App\Support;

// Customer programs (loyalty points, vouchers, discount programs and
// memberships) are for clients who use the Servora app: a client whose record
// at the spa is linked to an app account (clients.user_id —
// App\Models\Client::usesApp). A walk-in record the front desk made, with no
// app account, can't join, earn, buy with points, be sold a membership, or
// have a voucher or program discount put on a bill — at the desk or anywhere.
//
// What such a client already holds (vouchers in the wallet, a running
// membership) is not taken away: it is kept but can't be used while they are
// without the app (HELD_USABLE = false), and works again as soon as they sign
// up in the app and their record is linked. Nothing in the database changes.
//
// Discounts typed in by hand at checkout are not a customer program and stay
// open to everyone.
//
// Pure — tested in tests/Unit/AppOnlyProgramsTest; applied by
// ProgramRewardService, CustomerProgramService and BillingService.
final class AppOnlyPrograms
{
    /** Why a client without the app is refused. */
    public const MESSAGE = 'Customer programs are for Servora app users. Ask the client to sign up in the app.';

    /** The short label for the screens. */
    public const LABEL = 'For app users';

    /**
     * Whether what a non-app client already holds (vouchers, a running
     * membership's perks) can still be used. False: kept, but frozen until
     * they join the app.
     */
    public const HELD_USABLE = false;

    /** Whether the client can join, earn, buy with points or take a new program. */
    public static function allows(?bool $usesApp): bool
    {
        return $usesApp === true;
    }

    /** Whether the client can use a voucher or member perk they already hold. */
    public static function usesHeld(?bool $usesApp): bool
    {
        return self::allows($usesApp) || self::HELD_USABLE;
    }

    /** Why the client can't join or take a program; null when they can. */
    public static function refusal(?bool $usesApp): ?string
    {
        return self::allows($usesApp) ? null : self::MESSAGE;
    }
}
