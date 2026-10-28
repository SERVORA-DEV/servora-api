<?php

namespace App\Service\System;

use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\NotificationRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Manual announcements by a System Administrator. Each recipient's copy is an
// ordinary row in the app's own notifications feed (so it shows in the bell,
// the business and client apps, and push), tied back to the announcement; the
// automated notifications NotificationService writes for events (bookings,
// payments, reminders, approvals) never go through here and carry no
// announcement.
class AnnouncementService
{
    public function __construct(
        private AnnouncementAudience $audience,
        private NotificationRepository $notifications,
        private AuditLogRepository $audit,
    ) {}

    /** How many people this would reach, for the screen to show before sending. */
    public function preview(User $actor, string $audience, string $scope, array $targets): array
    {
        return ['recipients' => $this->audience->recipients($actor, $audience, $scope, $targets)->count()];
    }

    public function send(User $actor, array $data)
    {
        // The same submit twice (a double click, a retry) is one announcement.
        $existing = Announcement::where('request_id', $data['request_id'])->first();
        if ($existing) {
            return $this->alreadySent($actor, $existing);
        }

        $recipients = $this->audience->recipients($actor, $data['audience'], $data['scope'], $data['targets'] ?? []);
        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'audience' => 'There is no one eligible to receive this announcement.',
            ]);
        }

        $senderName = trim("{$actor->first_name} {$actor->last_name}") ?: $actor->email;
        // Administrators know who wrote it; everyone else is told it came from Servora.
        $signature = $data['audience'] === 'admins' ? $senderName : 'Servora';
        $message = "{$data['message']}\n\n— {$signature}";
        // The bell and the phone's pop-up show only the title: an urgent notice says so there.
        $title = $data['priority'] === 'urgent' ? mb_substr('Urgent: '.$data['title'], 0, 150) : $data['title'];

        try {
            // All or nothing: a failure part-way leaves no one half-notified and no record of a send.
            $announcement = DB::transaction(function () use ($actor, $data, $recipients, $message, $title) {
                $announcement = Announcement::create([
                    'sender_id' => $actor->id,
                    'audience' => $data['audience'],
                    'scope' => $data['scope'],
                    'priority' => $data['priority'],
                    'title' => $data['title'],
                    'message' => $data['message'],
                    'targets' => $data['scope'] === 'selected' ? array_values(array_unique($data['targets'])) : null,
                    'request_id' => $data['request_id'],
                    'status' => 'sent',
                    'recipient_count' => $recipients->count(),
                ]);

                foreach ($recipients as $user) {
                    $this->notifications->create(
                        $user->id, $title, $message, 'System', null, $announcement->id, $data['priority'],
                    );
                }

                $this->audit->record($actor->id, 'announcements', $announcement->id, 'Create', null, [
                    'name' => $data['title'],
                    'audience' => $data['audience'],
                    'scope' => $data['scope'],
                    'priority' => $data['priority'],
                    'recipients' => $recipients->count(),
                ], request());

                return $announcement;
            });
        } catch (UniqueConstraintViolationException) {
            // A second request with the same id got in first.
            return $this->alreadySent($actor, Announcement::where('request_id', $data['request_id'])->firstOrFail());
        }

        return response()->json([
            'message' => 'Announcement sent.',
            'duplicate' => false,
            'announcement' => new AnnouncementResource($announcement->loadMissing('sender:id,first_name,last_name')),
        ], 201);
    }

    /** The history: newest first, with how many have read each. */
    public function history(array $filters, int $perPage)
    {
        $query = Announcement::query()
            ->with('sender:id,first_name,last_name')
            ->withCount(['notifications as read_count' => fn ($q) => $q->where('is_read', true)])
            ->latest('id');

        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($filters['q']))).'%';
            $query->whereRaw('LOWER(title) LIKE ?', [$like]);
        }
        if (! empty($filters['audience'])) {
            $query->where('audience', $filters['audience']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        return AnnouncementResource::collection($query->paginate($perPage));
    }

    // Another administrator's announcement is not echoed back to someone who only guessed its id.
    private function alreadySent(User $actor, Announcement $existing)
    {
        if ($existing->sender_id !== $actor->id) {
            return response()->json(['message' => 'This request id is already in use.'], 409);
        }

        return response()->json([
            'message' => 'Announcement already sent.',
            'duplicate' => true,
            'announcement' => new AnnouncementResource($existing->loadMissing('sender:id,first_name,last_name')->loadCount(['notifications as read_count' => fn ($q) => $q->where('is_read', true)])),
        ], 200);
    }
}
