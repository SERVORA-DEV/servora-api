<?php

namespace App\Repository;

use App\Models\Notification;

class NotificationRepository
{
    public function create(int $userId, string $title, string $message, string $type = 'System', ?string $referenceUuid = null): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'reference_uuid' => $referenceUuid,
        ]);
    }

    // $type is optional and matched case-insensitively — the frontend
    // currently filters categories client-side over the already-loaded page
    // (see useSystemNotifications.ts), but the param is accepted here too so
    // a server-side filter is available without a parallel endpoint.
    public function paginateForUser(int $userId, int $perPage = 15, ?string $type = null)
    {
        $query = Notification::where('user_id', $userId);

        if ($type) {
            $query->whereRaw('LOWER(type) = ?', [strtolower($type)]);
        }

        return $query->latest()->paginate($perPage);
    }

    public function countUnreadForUser(int $userId): int
    {
        return Notification::where('user_id', $userId)->where('is_read', false)->count();
    }

    // Scoped to $userId in the WHERE, not just looked up by $id then checked —
    // an id belonging to another user matches zero rows and this returns
    // false, rather than ever touching a row it shouldn't.
    public function markReadForUser(int $userId, int $id): bool
    {
        return (bool) Notification::where('id', $id)
            ->where('user_id', $userId)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    public function markAllReadForUser(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    // Same scoping as markReadForUser: another user's id deletes nothing.
    public function deleteForUser(int $userId, int $id): bool
    {
        return (bool) Notification::where('id', $id)->where('user_id', $userId)->delete();
    }

    public function deleteReadForUser(int $userId): int
    {
        return Notification::where('user_id', $userId)->where('is_read', true)->delete();
    }
}
