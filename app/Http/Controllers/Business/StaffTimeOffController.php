<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffTimeOffRequest;
use App\Service\Business\StaffTimeOffService;
use Illuminate\Http\Request;

class StaffTimeOffController extends Controller
{
    public function __construct(private StaffTimeOffService $staffTimeOffService)
    {
    }

    public function index(Request $request, string $uuid)
    {
        return $this->staffTimeOffService->list($request->user(), $uuid);
    }

    // The date range in view (at most about four months), for every staff member this login works with.
    public function range(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function ($attribute, $value, $fail) use ($request) {
                if (strtotime((string) $value) - strtotime((string) $request->input('from')) > 140 * 86400) {
                    $fail('Choose up to four months at a time.');
                }
            }],
        ]);

        return $this->staffTimeOffService->range($request->user(), $data['from'], $data['to']);
    }

    public function store(StaffTimeOffRequest $request, string $uuid)
    {
        return $this->staffTimeOffService->create($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid, string $timeOffUuid)
    {
        return $this->staffTimeOffService->delete($request->user(), $uuid, $timeOffUuid);
    }
}
