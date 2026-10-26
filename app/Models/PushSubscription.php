<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A browser or phone that gets a pop-up for each new notification of its
// user (push_subscriptions; sent by App\Service\PushSender).
class PushSubscription extends Model
{
    public const KIND_WEB = 'web';
    public const KIND_FCM = 'fcm';

    protected $fillable = [
        'user_id',
        'kind',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'quiet',
        'user_agent',
    ];

    protected function casts(): array
    {
        return ['quiet' => 'boolean'];
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
