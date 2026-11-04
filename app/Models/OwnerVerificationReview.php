<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerVerificationReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'revision' => 'integer'];
    }
}
