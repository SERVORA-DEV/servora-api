<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// A stretch of days one member of staff isn't working — leave, sick, absent,
// other — whatever their weekly schedule says. Read by
// AppointmentAvailabilityService (no booking on those days) and kept from the
// Employees → Schedule → Time off list (StaffTimeOffService).
class StaffTimeOff extends Model
{
    public const TYPES = ['leave', 'sick', 'absent', 'other'];

    /** How each type is said everywhere it shows (schedules, booking, dashboards). */
    public const LABELS = ['leave' => 'On leave', 'sick' => 'Sick', 'absent' => 'Absent', 'other' => 'Time off'];

    protected $table = 'staff_time_off';

    protected $fillable = [
        'uuid',
        'staff_id',
        'type',
        'starts_on',
        'ends_on',
        'note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Whether the table exists yet (the migration has been run). Until then
     * nobody has time off. "Yes" is remembered for good, "not yet" for a
     * minute (same as Staff::hasSpecialties).
     */
    public static function ready(): bool
    {
        $known = Cache::get('schema:staff_time_off');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('staff_time_off');
        $ready ? Cache::forever('schema:staff_time_off', true) : Cache::put('schema:staff_time_off', false, 60);

        return $ready;
    }

    /** The staff member's time off touching [$from, $to] (Y-m-d), or none before the migration. */
    public static function overlapping(int $staffId, string $from, string $to)
    {
        if (! self::ready()) {
            return collect();
        }

        return self::where('staff_id', $staffId)
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->get();
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
