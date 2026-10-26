<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\BillingAdjustmentService;
use App\Support\BillAdjustment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    public function store(Request $request, string $uuid)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(BillAdjustment::KINDS)],
            'refund_policy_uuid' => ['nullable', 'uuid'],
            'amount' => ['nullable', 'required_without_all:refund_policy_uuid,client_voucher_uuid', 'numeric', 'min:0.01', 'max:9999999'],
            'charge_to' => ['nullable', Rule::in(\App\Support\RefundCharge::WHO)],
            // Before payment (kind credit): the service it is for; none for the whole bill.
            'service_uuid' => ['nullable', 'uuid'],
            'client_voucher_uuid' => ['nullable', 'required_if:kind,voucher', 'uuid'],
            'reason' => ['nullable', 'required_without:refund_policy_uuid', 'string', 'min:' . BillAdjustment::MIN_REASON, 'max:255'],
        ]);

        return $this->adjustments->create($request->user(), $uuid, $data);
    }

    public function approve(Request $request, string $uuid)
    {
        return $this->adjustments->approve($request->user(), $uuid);
    }

    public function decline(Request $request, string $uuid)
    {
        $note = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->adjustments->decline($request->user(), $uuid, $note);
    }

    // The person who asked takes it back.
    public function destroy(Request $request, string $uuid)
    {
        return $this->adjustments->withdraw($request->user(), $uuid);
    }
}
