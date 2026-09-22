<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\AnnouncementRequest;
use App\Service\NotificationService;
use Illuminate\Http\Request;

// The signed-in system administrator's OWN notification feed — every method
// here is scoped through $request->user(), so one admin can never read or
// mutate another admin's notifications (see NotificationRepository, which
// enforces this in the query itself, not just in this controller).
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    public function index(Request $request)
    {
        return $this->notificationService->listForUser(
            $request->user(),
            (int) $request->input('per_page', 15),
            $request->input('type'),
        );
    }

    public function markRead(Request $request, int $id)
    {
        return $this->notificationService->markRead($request->user(), $id);
    }

    public function markAllRead(Request $request)
    {
        return $this->notificationService->markAllRead($request->user());
    }

    public function announce(AnnouncementRequest $request)
    {
        return $this->notificationService->sendAnnouncement($request->user(), $request->validated());
    }
}
