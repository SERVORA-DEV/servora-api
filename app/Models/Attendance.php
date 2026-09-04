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
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
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
