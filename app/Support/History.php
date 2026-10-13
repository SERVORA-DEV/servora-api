<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// History stays as it happened.
//
// A record of something that took place — a service on a visit, who did it
// and where, what they earned for it, how an attendance day turned out, the
// deal a voucher was issued with — keeps its OWN COPY of what that was at the
// time. The menu, the staff list, the rooms, the programs and the owner's
// settings can all be edited or removed afterwards; edits apply to what
// happens next, never to what already has.
//
// The copies live in columns added by one migration
// (2026_10_12_090000_keep_history_as_it_happened). Until it has run, and for
// any record that has no copy, readers fall back to the live value — own().
class History
{
    /** Whether the history columns exist yet (the migration has been run). */
    public static function ready(): bool
    {
        // Asked on most writes, so both answers are remembered: "yes" for
        // good, "not yet" for a minute (same as Service::hasTypes).
        $known = Cache::get('schema:history');
        if (is_bool($known)) {
            return $known;
        }
        // The first and the last column the migration adds.
        $ready = Schema::hasColumn('appointment_services', 'service_name') && Schema::hasColumn('client_memberships', 'perks');
        $ready ? Cache::forever('schema:history', true) : Cache::put('schema:history', false, 60);

        return $ready;
    }

    /**
     * The record's own copy when it has one, otherwise the live value.
     * An empty string is not a copy; 0 and false are.
     *
     * @template T
     * @param  T|null  $copy
     * @param  T|null|\Closure(): (T|null)  $live  a closure is only called when there is no copy
     * @return T|null
     */
    public static function own(mixed $copy, mixed $live = null): mixed
    {
        if ($copy !== null && $copy !== '') {
            return $copy;
        }

        return $live instanceof \Closure ? $live() : $live;
    }

    /** "First Last", or null when there is neither. */
    public static function person(?string $first, ?string $last): ?string
    {
        $name = trim(trim((string) $first) . ' ' . trim((string) $last));

        return $name !== '' ? $name : null;
    }
}
