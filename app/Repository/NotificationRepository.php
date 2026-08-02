<?php

namespace App\Repository;

use App\Models\Notification;

class NotificationRepository
{
    public function create(int $userId, string $title, string $message, string $type = 'System'): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
        ]);
    }

    public function paginateForUser(int $userId, int $perPage = 15)
    {
        return Notification::where('user_id', $userId)->latest()->paginate($perPage);
    }
}
