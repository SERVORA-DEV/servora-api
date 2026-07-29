<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BranchSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'spa_branch_id',

        'day_of_week',

        'opening_time',
        'closing_time',

        'break_start',
        'break_end',

        'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
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
}
