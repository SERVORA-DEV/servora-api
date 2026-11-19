<?php

namespace App\Http\Controllers\System;

use App\Enums\ChangeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\RejectChangeRequest;
use App\Service\Business\OwnerIdentityChangeService;
use Illuminate\Http\Request;

// System administrators review an owner's request to change their name or
// email, against the new ID and face scan (OwnerIdentityChangeService).
class OwnerIdentityChangeController extends Controller
{
    public function __construct(private OwnerIdentityChangeService $changes) {}

    public function index(Request $request)
    {
        $status = (string) $request->query('status', ChangeRequestStatus::Pending->value);

        return $this->changes->adminList(in_array($status, [ChangeRequestStatus::Pending->value, ChangeRequestStatus::Approved->value, ChangeRequestStatus::Rejected->value, ChangeRequestStatus::Cancelled->value, 'All'], true) ? $status : ChangeRequestStatus::Pending->value);
    }

    public function show(string $uuid)
    {
        return $this->changes->adminShow($uuid);
    }

    public function approve(Request $request, string $uuid)
    {
        return $this->changes->approve($request->user(), $uuid, $request);
    }

    public function reject(RejectChangeRequest $request, string $uuid)
    {
        $data = $request->validated();

        return $this->changes->reject($request->user(), $uuid, trim($data['reason']), $request);
    }
}
