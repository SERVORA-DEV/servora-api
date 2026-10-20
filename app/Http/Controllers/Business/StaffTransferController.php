<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\StaffTransferService;
use Illuminate\Http\Request;

// A manager's request to move a staff member to another branch, and the
// owner's answer to it (StaffTransferService). Asking is done by saving the
// Branch on the employee (StaffService::updateStaff).
class StaffTransferController extends Controller
{
    public function __construct(private StaffTransferService $transfers)
    {
    }

    public function branchOptions(Request $request)
    {
        return $this->transfers->branchOptions($request->user());
    }

    public function index(Request $request)
    {
        return $this->transfers->pendingRequests($request->user());
    }

    public function approve(Request $request, string $uuid)
    {
        return $this->transfers->approve($request->user(), $uuid);
    }

    public function decline(Request $request, string $uuid)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;

        return $this->transfers->decline($request->user(), $uuid, $reason);
    }

    public function withdraw(Request $request, string $uuid)
    {
        return $this->transfers->withdraw($request->user(), $uuid);
    }
}
