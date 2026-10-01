<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\AppointmentService;
use App\Service\Business\BillingService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    private AppointmentService $appointmentService;
    private BillingService $billingService;

    public function __construct(AppointmentService $appointmentService, BillingService $billingService)
    {
        $this->appointmentService = $appointmentService;
        $this->billingService = $billingService;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'payment_method', 'date_from', 'date_to', 'search']);
        return $this->appointmentService->listBillings($request->user(), $filters, (int) $request->input('per_page', 15));
    }

    public function show(Request $request, string $uuid)
    {
        return $this->appointmentService->getBilling($request->user(), $uuid);
    }

    // The methods this business accepts (Settings → Payments), for the
    // payment dialog. Readable by anyone who takes payments.
    public function paymentOptions(Request $request)
    {
        return $this->billingService->paymentOptions($request->user());
    }

    public function discount(Request $request, string $uuid)
    {
        $data = $request->validate([
            'type' => ['required', 'in:amount,percent'],
            'value' => ['required', 'numeric', 'min:0', $request->input('type') === 'percent' ? 'max:100' : 'max:9999999'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->billingService->applyDiscount($request->user(), $uuid, $data['type'], (float) $data['value'], $data['reason'] ?? null, $request);
    }

    // Customer programs at checkout: the client's vouchers and the discount
    // programs that apply to this bill.
    public function programOptions(Request $request, string $uuid)
    {
        return $this->billingService->programOptions($request->user(), $uuid);
    }

    // Apply one of those (or remove it with `remove`). Separate from
    // discount() so the front desk can use programs the owner set up
    // without being allowed to type in any discount they like.
    public function programDiscount(Request $request, string $uuid)
    {
        $data = $request->validate([
            'discount_program_uuid' => ['nullable', 'uuid', 'required_without_all:client_voucher_uuid,remove'],
            'client_voucher_uuid' => ['nullable', 'uuid'],
            'remove' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('remove')) {
            return $this->billingService->applyDiscount($request->user(), $uuid, 'amount', 0, null, $request, programOnly: true);
        }

        return $this->billingService->applyDiscount(
            $request->user(), $uuid, 'amount', 0, null, $request,
            programUuid: $data['discount_program_uuid'] ?? null,
            voucherUuid: $data['client_voucher_uuid'] ?? null,
            programOnly: true,
        );
    }

    public function refund(Request $request, string $uuid)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->billingService->refund($request->user(), $uuid, (float) $data['amount'], $data['reason'], $request);
    }
}
