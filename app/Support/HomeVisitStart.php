<?php

namespace App\Support;

use Carbon\CarbonInterface;

// Starting a home visit by scanning. Nobody from the spa is at the front desk
// when a home service begins, so the client's app shows a QR and the staff
// member scans it at the door with their branch's scanner (a link the front
// desk hands out). The scan does what the desk's Check In and Start do.
//
//   • Every home booking has a start code: 8 characters, none that look like
//     another (no I, L, O, 0 or 1). The QR holds it behind a short prefix;
//     the code is also written under the QR, so it can be typed instead.
//   • A code only works through the scanner of the branch the client booked
//     with, and only on the day of the booking.
//
// Pure — tested in tests/Unit/HomeVisitStartTest; mirrored on the site in
// app/utils/homeVisitStart.ts (keep the two in step).
class HomeVisitStart
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    /** What the QR holds before the code, so a scan of anything else is told apart. */
    public const PREFIX = 'SERVORA-HV:';

    public static function newCode(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    /** The text the client's QR is drawn from. */
    public static function qrText(string $code): string
    {
        return self::PREFIX . $code;
    }

    /** "ABCD-2345": how the code is written for reading out or typing. */
    public static function display(?string $code): string
    {
        $code = (string) $code;

        return strlen($code) === self::LENGTH ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
    }

    /**
     * The code in what was scanned or typed — the QR's text, or the code
     * itself in any case, with or without its dash or spaces. Null when it
     * is neither (some other QR, a mistyped code).
     */
    public static function read(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        $text = strtoupper(trim($input));
        if (str_starts_with($text, self::PREFIX)) {
            $text = substr($text, strlen(self::PREFIX));
        }
        $text = preg_replace('/[\s\-]+/', '', $text) ?? '';

        return strlen($text) === self::LENGTH && strspn($text, self::ALPHABET) === self::LENGTH ? $text : null;
    }

    /**
     * Why a home visit can't be started by a scan today, or null when it can.
     * `$status` is the booking's, `$bookedFor` its day and `$today` the day
     * it is now (both in the spa's own time).
     */
    public static function problem(string $status, CarbonInterface $bookedFor, CarbonInterface $today): ?string
    {
        switch ($status) {
            case 'cancelled':
                return 'This booking was cancelled.';
            case 'no_show':
                return 'This booking was marked as a no-show. Call the front desk.';
            case 'completed':
                return 'This visit is already finished.';
            case 'in_service':
                return 'This visit has already started.';
        }

        if (! in_array($status, ['scheduled', 'checked_in'], true)) {
            return 'This booking can\'t be started. Call the front desk.';
        }

        if ($bookedFor->toDateString() !== $today->toDateString()) {
            return 'This booking is for ' . $bookedFor->format('D, M j') . ', not today.';
        }

        return null;
    }
}
