<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BillingAdjustmentDeclineRequest;
use App\Http\Requests\Business\BillingAdjustmentRequest;
use App\Service\Business\BillingAdjustmentService;
use Illuminate\Http\Request;

// Changing a bill after it is paid — see BillingAdjustmentService.
class BillingAdjustmentController extends Controller
{
    public function __construct(private BillingAdjustmentService $adjustments)
    {
    }

    // Requests waiting for the signed-in person to answer, and their own.
    public function index(Request $request)
    {
        return $this->adjustments->list($request->user());
    }

    // A voucher afterwards ({kind: voucher, client_voucher_uuid, reason}) or a
    // refund: under one of the owner's refund policies ({kind: refund,
    // refund_policy_uuid, amount?, reason?} — the policy's amount and name when
    // left out), or outside them ({kind: refund, amount, reason, charge_to} —
    // who it comes out of). Before payment the same with kind credit, and
    // service_uuid for one service. Applied at once or sent for approval, by
    // who is asking.
    public function store(BillingAdjustmentRequest $request, string $uuid)
    {
        $data = $request->validated();

        return $this->adjustments->create($request->user(), $uuid, $data);
    }

    public function approve(Request $request, string $uuid)
    {
        return $this->adjustments->approve($request->user(), $uuid);
    }

    public function decline(BillingAdjustmentDeclineRequest $request, string $uuid)
    {
        $note = $request->validated()['reason'] ?? null;

        return $this->adjustments->decline($request->user(), $uuid, $note);
    }

    // The person who asked takes it back.
    public function destroy(Request $request, string $uuid)
    {
        return $this->adjustments->withdraw($request->user(), $uuid);
    }
}
