<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'attendances';

    protected $fillable = [
        'uuid',
        'staff_id',
        'attendance_date',
        'check_in_at',
        'check_out_at',
        'left_early',
        'covering_for_staff_id',
        'status',
        'remarks',
        'created_by',
        'updated_by',
        // How the day turned out, kept as it was (App\Support\History):
        // the shift and lateness from the check-in, the hours from the
        // check-out. Written by AttendanceStatusCalculator::keep().
        'scheduled_start',
        'scheduled_end',
        'late_minutes',
        'work_minutes',
        'overtime_minutes',
        'resolved_status',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $attendance) {
            // The branch where it happened, kept with the record so a staff
            // member who moves keeps their old days at the old branch.
            if ($attendance->spa_branch_id === null && \App\Models\StaffTransferRequest::ready()) {
                $attendance->spa_branch_id = Staff::where('id', $attendance->staff_id)->value('spa_branch_id');
            }

            app(\App\Service\Business\AttendanceStatusCalculator::class)->keep($attendance);
        });
    }

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'left_early' => 'boolean',
    ];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /** The branch it was recorded at (null on a record from before it was kept). */
    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    /** Where it happened: its own branch, else the one the person is at now (App\Support\History). */
    public function ownBranch(): ?SpaBranch
    {
        return $this->spa_branch_id ? $this->branch : $this->staff?->branch;
    }

    // The therapist this person is filling in for today (they left early or are on leave).
    public function coveringFor()
    {
        return $this->belongsTo(Staff::class, 'covering_for_staff_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
