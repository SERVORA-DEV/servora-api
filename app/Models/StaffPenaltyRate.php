<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// What a late day and an absent day cost from `effective_from` on (Staff
// Policies → Penalties). A day is charged at the row in force on it, so a
// change applies from that day forward and earlier days keep what they were
// (App\Support\AttendancePenalty).
class StaffPenaltyRate extends Model
{
    protected $table = 'staff_penalty_rates';

    protected $fillable = [
        'spa_business_id',
        'late_enabled',
        'late_amount',
        // 'day': late_amount per late day; 'hour': late_amount per hour late.
        'late_mode',
        'absent_enabled',
        'absent_amount',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'late_enabled' => 'boolean',
            'late_amount' => 'decimal:2',
            'absent_enabled' => 'boolean',
            'absent_amount' => 'decimal:2',
            'effective_from' => 'date:Y-m-d',
        ];
    }

    /** Whether penalties and commission adjustments exist yet (the migration has been run). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:staff_penalties');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('staff_penalty_rates');
        $ready ? Cache::forever('schema:staff_penalties', true) : Cache::put('schema:staff_penalties', false, 60);

        return $ready;
    }

    /** Whether lateness can be charged by the hour yet (its migration has been run). */
    public static function hasLateMode(): bool
    {
        $known = Cache::get('schema:late_mode');
        if (is_bool($known)) {
            return $known;
        }
        $ready = self::ready() && Schema::hasColumn('staff_penalty_rates', 'late_mode');
        $ready ? Cache::forever('schema:late_mode', true) : Cache::put('schema:late_mode', false, 60);

        return $ready;
    }

    /**
     * The rates in force for a business, oldest first, as plain arrays for
     * AttendancePenalty. Before the migration: what Staff Policies says now,
     * for every day.
     *
     * @return array<int, array{from: string, late_enabled: bool, late_amount: float, late_mode: string, absent_enabled: bool, absent_amount: float}>
     */
    public static function history(int $businessId, ?array $policy): array
    {
        $rows = self::ready()
            ? self::where('spa_business_id', $businessId)->orderBy('effective_from')->orderBy('id')->get()
            : new Collection();

        if ($rows->isEmpty()) {
            return [self::fromPolicy($policy, '2000-01-01')];
        }

        return $rows->map(fn (self $r) => [
            'from' => $r->effective_from->format('Y-m-d'),
            'late_enabled' => $r->late_enabled,
            'late_amount' => (float) $r->late_amount,
            'late_mode' => ($r->late_mode ?? 'day') === 'hour' ? 'hour' : 'day',
            'absent_enabled' => $r->absent_enabled,
            'absent_amount' => (float) $r->absent_amount,
        ])->values()->all();
    }

    /**
     * Staff Policies were saved: if the penalties changed, they apply from
     * today on (a second change the same day replaces the first).
     */
    public static function record(int $businessId, array $policy, string $today): void
    {
        if (! self::ready()) {
            return;
        }

        $new = self::fromPolicy($policy, $today);
        $last = self::where('spa_business_id', $businessId)->orderByDesc('effective_from')->orderByDesc('id')->first();
        $withMode = self::hasLateMode();
        if ($last
            && $last->late_enabled === $new['late_enabled'] && (float) $last->late_amount === $new['late_amount']
            && (! $withMode || ($last->late_mode ?? 'day') === $new['late_mode'])
            && $last->absent_enabled === $new['absent_enabled'] && (float) $last->absent_amount === $new['absent_amount']) {
            return;
        }

        $values = [
            'late_enabled' => $new['late_enabled'],
            'late_amount' => $new['late_amount'],
            'absent_enabled' => $new['absent_enabled'],
            'absent_amount' => $new['absent_amount'],
        ] + ($withMode ? ['late_mode' => $new['late_mode']] : []);
        if ($last && $last->effective_from->format('Y-m-d') === $today) {
            $last->update($values);

            return;
        }
        self::create($values + ['spa_business_id' => $businessId, 'effective_from' => $today]);
    }

    private static function fromPolicy(?array $policy, string $from): array
    {
        return [
            'from' => $from,
            'late_enabled' => (bool) ($policy['late_penalty_enabled'] ?? false),
            'late_amount' => round((float) ($policy['late_penalty_amount'] ?? 0), 2),
            'late_mode' => ($policy['late_penalty_mode'] ?? 'day') === 'hour' ? 'hour' : 'day',
            'absent_enabled' => (bool) ($policy['absent_penalty_enabled'] ?? false),
            'absent_amount' => round((float) ($policy['absent_penalty_amount'] ?? 0), 2),
        ];
    }
}
