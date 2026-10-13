<?php

namespace App\Support;

use App\Models\BranchClosure;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// The one place that answers "is this branch closed for this window?" and
// "what should people be told?" — booking checks, the client's time picker,
// the spa page and the staff screens all ask here, so they can't disagree.
//
// A closure covers [starts_at, ends_at): the branch is open again at ends_at.
class BranchClosures
{
    public const REASONS = ['Holiday', 'Maintenance or repairs', 'Private event', 'Staff shortage', 'Emergency', 'Other'];

    // A closure can't be longer than this; a branch shut for longer is
    // switched off (Deactivate) instead.
    public const MAX_DAYS = 90;

    // How far ahead clients are told about a closure that hasn't started.
    private const NOTICE_DAYS = 30;

    private static ?bool $ready = null;

    // False until the branch_closures migration has run. Booking asks about
    // closures on every request, so a database that hasn't been migrated yet
    // must read as "no closures" rather than fail every booking.
    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }

        // "It is there" is remembered for good. "Not yet" only for a minute,
        // so the answer changes soon after the migration runs without every
        // request in between asking the database again.
        $known = Cache::get('branch_closures:ready');
        if ($known !== null) {
            return self::$ready = (bool) $known;
        }

        self::$ready = Schema::hasTable('branch_closures');
        self::$ready
            ? Cache::forever('branch_closures:ready', true)
            : Cache::put('branch_closures:ready', false, 60);

        return self::$ready;
    }

    /** Closures of a branch that touch [$start, $end), earliest first. */
    public static function overlapping(int $branchId, Carbon $start, Carbon $end): Collection
    {
        if (! self::ready()) {
            return collect();
        }

        return BranchClosure::where('spa_branch_id', $branchId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();
    }

    // The closure a booking starting at $start (and running to $end, when its
    // length is known) would fall into, or null.
    public static function blocking(int $branchId, Carbon $start, ?Carbon $end = null): ?BranchClosure
    {
        return self::overlapping($branchId, $start, $end ?? $start->copy()->addMinute())->first();
    }

    /** Still running or yet to start, earliest first. */
    public static function upcoming(int $branchId, ?Carbon $now = null): Collection
    {
        if (! self::ready()) {
            return collect();
        }

        return BranchClosure::where('spa_branch_id', $branchId)
            ->where('ends_at', '>', $now ?? Carbon::now())
            ->orderBy('starts_at')
            ->get();
    }

    // The eager-load constraint for "this branch's upcoming closures", for
    // lists of branches.
    public static function upcomingScope(): \Closure
    {
        return fn ($query) => $query->where('ends_at', '>', Carbon::now())->orderBy('starts_at');
    }

    public static function isActive(BranchClosure $closure, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $closure->starts_at->lte($now) && $closure->ends_at->gt($now);
    }

    // "Oct 12, 1:00 PM to Oct 14, 6:00 PM", "Oct 12 to Oct 14", "Nov 1".
    public static function period(BranchClosure $closure): string
    {
        $start = $closure->starts_at;
        $end = $closure->ends_at;
        $day = fn (Carbon $d) => $d->format($d->year === Carbon::now()->year ? 'M j' : 'M j, Y');

        if ($closure->all_day) {
            // Midnight to midnight: the last closed day is the one before ends_at.
            $last = $end->copy()->subMinute();

            return $start->isSameDay($last) ? $day($start) : $day($start) . ' to ' . $day($last);
        }

        return $start->isSameDay($end)
            ? $day($start) . ', ' . $start->format('g:i A') . ' to ' . $end->format('g:i A')
            : $day($start) . ', ' . $start->format('g:i A') . ' to ' . $day($end) . ', ' . $end->format('g:i A');
    }

    // What someone trying to book inside it is told. Staff read "the
    // branch", clients "the spa".
    public static function refusal(BranchClosure $closure, string $place = 'branch'): string
    {
        return "The {$place} is closed " . self::period($closure) . " ({$closure->reason}).";
    }

    // What the spa page tells clients: the closure running now, or the next
    // one coming up soon. Null when there is nothing to say.
    public static function notice(?Collection $closures, ?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();

        $closure = ($closures ?? collect())
            ->filter(fn (BranchClosure $c) => $c->ends_at->gt($now) && $c->starts_at->lte($now->copy()->addDays(self::NOTICE_DAYS)))
            ->sortBy('starts_at')
            ->first();

        return $closure ? self::present($closure, $now) : null;
    }

    public static function present(BranchClosure $closure, ?Carbon $now = null): array
    {
        return [
            'uuid' => $closure->uuid,
            // The branch's wall-clock time, not converted to any other zone.
            'starts_at' => $closure->starts_at->format('Y-m-d H:i'),
            'ends_at' => $closure->ends_at->format('Y-m-d H:i'),
            'all_day' => (bool) $closure->all_day,
            // For an all-day closure: its first and last closed day.
            'first_day' => $closure->starts_at->toDateString(),
            'last_day' => ($closure->all_day ? $closure->ends_at->copy()->subMinute() : $closure->ends_at)->toDateString(),
            'reason' => $closure->reason,
            'note' => $closure->note,
            // Ready to show: "Oct 12, 1:00 PM to Oct 14, 6:00 PM".
            'period' => self::period($closure),
            'active_now' => self::isActive($closure, $now),
        ];
    }
}
