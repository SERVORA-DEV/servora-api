<?php

namespace App\Support;

// What kind of service something is, and what follows from it.
//
//   time       a massage: sold in lengths (60 / 90 / 120 min), each with its
//              price; a therapist performs it; when its time is up the desk is
//              told, and it can be extended to a longer length.
//   procedure  a haircut, waxing, nails: one price; the time is only an
//              estimate, so there is no "time is up" and nothing to extend to.
//              One therapist may do two of these for the same client at once.
//   facility   a sauna, a jacuzzi: sold in lengths like a time-based service,
//              but nobody performs it — it needs no therapist and earns no
//              commission.
//
// A facility (a room, a sauna) can be picked for any service. A facility-based
// service always needs one; any other needs one only when the owner ticked
// "Facility required" on it — a haircut or nails usually doesn't. The front
// desk can't start a service that needs one until one is chosen (never for a
// home visit, which has none).
//
// Packages are not a kind: a package is a bundle of services, each of its own
// kind. Pure — tested in tests/Unit/ServiceKindTest; mirrored on the site in
// app/utils/serviceKind.ts.
class ServiceKind
{
    public const TIME = 'time';
    public const PROCEDURE = 'procedure';
    public const FACILITY = 'facility';

    public const ALL = [self::TIME, self::PROCEDURE, self::FACILITY];

    /** Anything unknown — or a service saved before types existed — is time-based. */
    public static function of(?string $kind): string
    {
        return in_array($kind, self::ALL, true) ? $kind : self::TIME;
    }

    public static function label(?string $kind): string
    {
        return match (self::of($kind)) {
            self::PROCEDURE => 'Procedure-based',
            self::FACILITY => 'Facility-based',
            default => 'Time-based',
        };
    }

    /** Someone has to perform it. */
    public static function needsTherapist(?string $kind): bool
    {
        return self::of($kind) !== self::FACILITY;
    }

    /** A facility has to be chosen before it can start: always for a facility-based one, else the owner's tick. */
    public static function needsFacility(?string $kind, bool $required = false): bool
    {
        return self::of($kind) === self::FACILITY || $required;
    }

    /** Its length is real (not an estimate): "time is up" applies. */
    public static function isTimed(?string $kind): bool
    {
        return self::of($kind) !== self::PROCEDURE;
    }

    /** Sold in several lengths, so it can be extended to a longer one. */
    public static function hasLengths(?string $kind): bool
    {
        return self::of($kind) !== self::PROCEDURE;
    }

    /**
     * May one therapist do these two services for the same client at the same
     * time? Only two procedure-based ones — a service measured in time holds
     * its therapist for that time.
     */
    public static function canShareTherapist(?string $a, ?string $b): bool
    {
        return self::of($a) === self::PROCEDURE && self::of($b) === self::PROCEDURE;
    }
}
