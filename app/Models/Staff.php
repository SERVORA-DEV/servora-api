<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'staff';

    protected $fillable = [
        'uuid',

        'spa_branch_id',
        'employee_number',

        'first_name',
        'last_name',
        'middle_name',
        'suffix',

        'email',
        'phone_number',

        'gender',
        'birth_date',
        'hire_date',

        'role',

        'employment_type',
        'status',

        'emergency_contact_name',
        'emergency_contact_number',
        'notes',
    ];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'hire_date' => 'date:Y-m-d',
    ];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }
}
