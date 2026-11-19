<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\AnnouncementListRequest;
use App\Http\Requests\System\AnnouncementOptionsRequest;
use App\Http\Requests\System\AnnouncementPreviewRequest;
use App\Http\Requests\System\AnnouncementRequest;
use App\Service\System\AnnouncementAudience;
use App\Service\System\AnnouncementService;

// Manual announcements by a System Administrator. Every route here sits behind
// role:system_administrator and the notification_send permission (routes/api.php).
class AnnouncementController extends Controller
{
    public function __construct(
        private AnnouncementService $announcements,
        private AnnouncementAudience $audience,
    ) {}

    // The history, newest first.
    public function index(AnnouncementListRequest $request)
    {
        $filters = $request->validated();

        return $this->announcements->history($filters, (int) ($filters['per_page'] ?? 15));
    }

    public function store(AnnouncementRequest $request)
    {
        return $this->announcements->send($request->user(), $request->validated());
    }

    // How many people the chosen audience and selection would reach.
    public function preview(AnnouncementPreviewRequest $request)
    {
        $data = $request->validated();

        return response()->json($this->announcements->preview(
            $request->user(), $data['audience'], $data['scope'], $data['targets'] ?? [],
        ));
    }

    // The searchable list behind "selected recipients" (businesses, branches, clients).
    public function options(AnnouncementOptionsRequest $request)
    {
        $data = $request->validated();

        $page = $this->audience->options($data['audience'], $data['search'] ?? null, (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
