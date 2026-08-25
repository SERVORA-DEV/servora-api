<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'facilities';

    protected $fillable = [
        'uuid',
        'spa_branch_id',
        'name',
        'description',
        'type',
        'capacity',
        'status',
        'is_available',
        'amenities',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'is_available' => 'boolean',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function therapistAssignments()
    {
        return $this->hasMany(TherapistAssignment::class, 'facility_id');
    }
}
