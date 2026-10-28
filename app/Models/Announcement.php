<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// A message a System Administrator broadcast. Never edited after it is sent.
// Each recipient's copy is a row in `notifications` (announcement_id), which is
// also where their read status is.
class Announcement extends Model
{
    public const AUDIENCES = ['admins', 'businesses', 'branches', 'clients'];
    public const SCOPES = ['all', 'selected'];
    public const PRIORITIES = ['normal', 'important', 'urgent'];

    protected $fillable = [
        'uuid', 'sender_id', 'audience', 'scope', 'priority', 'title', 'message',
        'targets', 'request_id', 'status', 'recipient_count',
    ];

    protected function casts(): array
    {
        return ['targets' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Announcement $announcement) {
            $announcement->uuid ??= (string) Str::uuid();
        });
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}
