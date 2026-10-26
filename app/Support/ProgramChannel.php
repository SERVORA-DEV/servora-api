<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\CustomerProgram;

// Where a customer program (loyalty, voucher, discount, membership) applies:
// everywhere, only on the mobile app, or only at the front desk (the spa).
// The owner sets it per program (`values.channel`); a program saved before it
// existed has none and means "everywhere".
//
// "Where" is the booking a bill belongs to: made from the client app
// (appointments.source = 'Mobile') or taken at the desk (anything else). For
// loyalty it limits earning points and spending them; for a membership, where
// its perks (the vouchers and discounts inside) can be used.
final class ProgramChannel
{
    public const ALL = 'all';
    public const APP = 'app';
    public const DESK = 'front_desk';

    public const VALUES = [self::ALL, self::APP, self::DESK];

    /** The channel a program is limited to; ALL when none is set. */
    public static function of(CustomerProgram|array|null $program): string
    {
        $value = $program instanceof CustomerProgram
            ? $program->value('channel')
            : (is_array($program) ? ($program['channel'] ?? ($program['values']['channel'] ?? null)) : null);

        return in_array($value, self::VALUES, true) ? $value : self::ALL;
    }

    /** Whether a program limited to $channel applies where the action happens ($where: APP or DESK). */
    public static function allows(string $channel, string $where): bool
    {
        return $channel === self::ALL || $channel === $where;
    }

    /** Where a booking is made: the app, or the front desk. */
    public static function whereOf(?Appointment $appointment): string
    {
        return $appointment?->source === 'Mobile' ? self::APP : self::DESK;
    }

    /** Why a program can't be used where the action happens ($where: APP or DESK). */
    public static function refusalAt(string $where): string
    {
        return $where === self::APP ? 'Not offered on the mobile app (spa only).' : 'Not offered at the spa (mobile app only).';
    }

    /** Plain words for why a program limited to a channel can't be used elsewhere. */
    public static function refusal(string $channel): string
    {
        return $channel === self::APP ? 'Only on the mobile app.' : 'Only at the spa.';
    }
}
