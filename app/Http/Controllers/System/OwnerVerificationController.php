<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\OwnerVerificationRejectRequest;
use App\Service\System\OwnerVerificationService;
use App\Service\System\OwnerVerificationChecklistService;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;

class OwnerVerificationController extends Controller
{
    public function __construct(private OwnerVerificationService $ownerVerificationService) {}

    public function index(Request $request)
    {
        return $this->ownerVerificationService->listPending($request->input('per_page', 15));
    }

    public function show(string $uuid)
    {
        return $this->ownerVerificationService->getReview($uuid);
    }

    public function saveChecklist(Request $request, string $uuid, string $part, OwnerVerificationChecklistService $checklists)
    {
        abort_unless(AdminPermissions::allows($request->user(), 'spa_business_approve')
            || AdminPermissions::allows($request->user(), 'spa_business_reject'), 403, 'You do not have permission to review verifications.');
        return response()->json(['data' => $checklists->save($request->user(), $uuid, $part, $request)]);
    }

    public function requestIdentityResubmission(OwnerVerificationRejectRequest $request, string $uuid)
    {
        $data = $request->validated();
        return $this->ownerVerificationService->rejectIdentity($request->user(), $uuid, $data['reason'], $data['field'] ?? null, $request, true);
    }

    public function requestBusinessResubmission(OwnerVerificationRejectRequest $request, string $uuid)
    {
        return $this->ownerVerificationService->rejectBusiness($request->user(), $uuid, $request->validated()['reason'], $request, true);
    }

    public function approveIdentity(Request $request, string $uuid)
    {
        return $this->ownerVerificationService->approveIdentity($request->user(), $uuid, $request);
    }

    public function rejectIdentity(OwnerVerificationRejectRequest $request, string $uuid)
    {
        $validated = $request->validated();

        return $this->ownerVerificationService->rejectIdentity(
            $request->user(),
            $uuid,
            $validated['reason'],
            $validated['field'] ?? null,
            $request
        );
    }

    public function approveBusiness(Request $request, string $uuid)
    {
        return $this->ownerVerificationService->approveBusiness($request->user(), $uuid, $request);
    }

    public function rejectBusiness(OwnerVerificationRejectRequest $request, string $uuid)
    {
        return $this->ownerVerificationService->rejectBusiness(
            $request->user(),
            $uuid,
            $request->validated()['reason'],
            $request
        );
    }
}
