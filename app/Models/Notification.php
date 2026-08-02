<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// In-app notification feed row (notifications table) — unrelated to
// Illuminate\Notifications\Notification, which is only used here to build
// the mail side of App\Notifications\* classes.
class Notification extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',

        'title',
        'message',
        'type',

        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
