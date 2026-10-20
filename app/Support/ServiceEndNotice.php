<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

// The notice the front desk gets when a service has run its time: when it is
// due, and what it says. Pure, so the wording and the timing are tested
// without a database (tests/Unit/ServiceEndNoticeTest).
// App\Service\Business\ServiceEndNotifier finds the services and sends it.
class ServiceEndNotice
{
    // Every such notice starts with this; the service's name follows, so a
    // visit's two services each get their own (and each only once).
    public const TITLE_PREFIX = 'Time is up: ';

    /** When a service that started at $startedAt, $minutes long, has run its time. Null without a length. */
    public static function dueAt(CarbonInterface $startedAt, ?int $minutes): ?Carbon
    {
        if (! $minutes || $minutes <= 0) {
            return null;
        }

        return Carbon::instance($startedAt)->addMinutes($minutes);
    }

    public static function isDue(CarbonInterface $startedAt, ?int $minutes, CarbonInterface $now): bool
    {
        $due = self::dueAt($startedAt, $minutes);

        return $due !== null && $now->gte($due);
    }

    public static function title(string $service): string
    {
        return self::TITLE_PREFIX . $service;
    }

    /**
     * @param  string[]  $therapists  who is doing it
     */
    public static function message(string $service, string $client, CarbonInterface $dueAt, array $therapists = [], ?string $room = null): string
    {
        $where = $room ? " in {$room}" : '';
        $who = $therapists ? ' with ' . self::join($therapists) : '';

        return "{$client}'s {$service}{$where}{$who} reached its time at " . $dueAt->format('g:i A')
            . '. Mark it complete once it is done' . ($room ? ' so the facility is free.' : '.');
    }

    private static function join(array $names): string
    {
        $names = array_values(array_filter($names));
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }
}
