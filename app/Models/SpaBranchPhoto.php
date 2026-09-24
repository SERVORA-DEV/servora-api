<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// One photo in a branch's marketplace gallery (Branch Settings →
// Marketplace → Photos). `path` is a Cloudinary public_id.
class SpaBranchPhoto extends Model
{
    use HasUuids;

    public const MAX_PER_BRANCH = 8;

    protected $fillable = ['uuid', 'spa_branch_id', 'path', 'is_cover', 'sort_order'];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean', 'sort_order' => 'integer'];
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
