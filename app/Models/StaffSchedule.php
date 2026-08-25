<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A staff member's weekly recurring shift — day_of_week + start/end +
// break window, with an optional effective_from/until date range for
// schedule versioning. First real consumer is
// AppointmentAvailabilityService::staffMatchesSchedule().
class StaffSchedule extends Model
{
    protected $table = 'staff_schedules';

    protected $fillable = [
        'staff_id',
        'day_of_week',
        'start_time',
        'end_time',
        'break_start',
        'break_end',
        'is_day_off',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'is_day_off' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
        ];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
