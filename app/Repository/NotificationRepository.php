<?php

namespace App\Repository;

use App\Models\Notification;
use App\Service\PushSender;

class NotificationRepository
{
    // Every new notification is also popped up on its user's browsers and
    // phones while Servora isn't open there (PushSender, after the response).
    public function create(int $userId, string $title, string $message, string $type = 'System', ?string $referenceUuid = null): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'reference_uuid' => $referenceUuid,
        ]);
        app(PushSender::class)->queue($notification);

        return $notification;
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

    // Scoped to $userId in the WHERE, like markReadForUser below.
    // The newest notification $userId hasn't read yet, from the last
    // $minutes, with one of these titles and a message starting with
    // $startsWith (the name of whoever made the change). Lets a run of saves
    // by one person become one notification instead of many
    // (ManagerChangeNotifier).
    public function recentUnreadFrom(int $userId, array $titles, string $startsWith, int $minutes): ?Notification
    {
        return Notification::where('user_id', $userId)
            ->whereIn('title', $titles)
            ->where('is_read', false)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->first(fn (Notification $n) => str_starts_with($n->message, $startsWith));
    }

    public function findForUser(int $userId, int $id): ?Notification
    {
        return Notification::where('id', $id)->where('user_id', $userId)->first();
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
