<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\AnnouncementRequest;
use App\Models\Announcement;
use App\Service\System\AnnouncementAudience;
use App\Service\System\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Manual announcements by a System Administrator. Every route here sits behind
// role:system_administrator and the notification_send permission (routes/api.php).
class AnnouncementController extends Controller
{
    public function __construct(
        private AnnouncementService $announcements,
        private AnnouncementAudience $audience,
    ) {}

    // The history, newest first.
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'audience' => ['nullable', Rule::in(Announcement::AUDIENCES)],
            'priority' => ['nullable', Rule::in(Announcement::PRIORITIES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->announcements->history($filters, (int) ($filters['per_page'] ?? 15));
    }

    public function store(AnnouncementRequest $request)
    {
        return $this->announcements->send($request->user(), $request->validated());
    }

    // How many people the chosen audience and selection would reach.
    public function preview(Request $request)
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'scope' => ['required', Rule::in(Announcement::SCOPES)],
            'targets' => ['nullable', 'array', 'max:500'],
            'targets.*' => ['string', 'uuid'],
        ]);

        return response()->json($this->announcements->preview(
            $request->user(), $data['audience'], $data['scope'], $data['targets'] ?? [],
        ));
    }

    // The searchable list behind "selected recipients" (businesses, branches, clients).
    public function options(Request $request)
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['businesses', 'branches', 'clients'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

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
