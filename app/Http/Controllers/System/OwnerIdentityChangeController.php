<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Service\Business\OwnerIdentityChangeService;
use Illuminate\Http\Request;

// System administrators review an owner's request to change their name or
// email, against the new ID and face scan (OwnerIdentityChangeService).
class OwnerIdentityChangeController extends Controller
{
    public function __construct(private OwnerIdentityChangeService $changes) {}

    public function index(Request $request)
    {
        $status = (string) $request->query('status', 'Pending');

        return $this->changes->adminList(in_array($status, ['Pending', 'Approved', 'Rejected', 'Cancelled', 'All'], true) ? $status : 'Pending');
    }

    public function show(string $uuid)
    {
        return $this->changes->adminShow($uuid);
    }

    public function approve(Request $request, string $uuid)
    {
        return $this->changes->approve($request->user(), $uuid, $request);
    }

    public function reject(Request $request, string $uuid)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->changes->reject($request->user(), $uuid, trim($data['reason']), $request);
    }
}
