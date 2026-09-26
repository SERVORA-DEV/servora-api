<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\NotificationService;
use Illuminate\Http\Request;

// The signed-in business owner's OWN notification feed — mirrors
// System\NotificationController exactly (same NotificationService, which is
// already generic per-user_id; no owner-specific business logic needed).
// No announce() here — that stays admin-only.
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

    public function destroy(Request $request, int $id)
    {
        return $this->notificationService->delete($request->user(), $id);
    }

    public function clearRead(Request $request)
    {
        return $this->notificationService->clearRead($request->user());
    }
}
