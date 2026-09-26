<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Repository\NotificationRepository;
use Illuminate\Support\Facades\DB;

// Puts a notification in a mobile client's feed when the spa does something
// to their booking (confirmed, moved, cancelled, checked in, called,
// completed). Only for clients linked to a login account — a walk-in typed
// in at the front desk has no feed to write to, so that's a silent no-op.
// Never fails the caller: a feed entry is a courtesy, not part of the
// booking change itself, so it's written after the surrounding transaction
// commits (and dropped if that transaction rolls back).
class ClientNotifier
{
    public function __construct(private NotificationRepository $notifications)
    {
    }

    public function appointment(Appointment $appointment, string $title, string $message, string $type = 'Appointment'): void
    {
        $userId = $appointment->client?->user_id;
        if (! $userId) {
            return;
        }

        $reference = $appointment->uuid;

        DB::afterCommit(function () use ($userId, $title, $message, $type, $reference) {
            try {
                $this->notifications->create($userId, $title, $message, $type, $reference);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
