<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A stretch of time a branch is closed outside its weekly hours. The rules
// (what it blocks, how it reads) live in App\Support\BranchClosures.
class BranchClosure extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'spa_branch_id',
        'starts_at',
        'ends_at',
        'all_day',
        'reason',
        'note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
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
