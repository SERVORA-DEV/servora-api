<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BillDiscountRequest;
use App\Http\Requests\Business\BillProgramDiscountRequest;
use App\Http\Requests\Business\BillRefundRequest;
use App\Http\Requests\Business\BillingListRequest;
use App\Http\Requests\Business\BillingSummaryRequest;
use App\Service\Business\AppointmentLookupService;
use App\Service\Business\AppointmentOverviewService;
use App\Service\Business\BillingOverviewService;
use App\Service\Business\BillingService;
use App\Support\PerPage;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private AppointmentLookupService $lookup,
        private BillingService $billingService,
    ) {}

    // One page of visit bills, filtered by the API (BillingListQuery says which filters);
    // `with_counts` / `with_totals` add the status counts and what the matching bills add up to.
    public function index(BillingListRequest $request)
    {
        $filters = $request->only(['status', 'display_status', 'payment_method', 'date_from', 'date_to', 'search', 'branch_uuid', 'sort']);
        $filters['with_counts'] = $request->boolean('with_counts');
        $filters['with_totals'] = $request->boolean('with_totals');

        return $this->lookup->listBillings($request->user(), $filters, PerPage::from($request));
    }

    // The overview card: a period's bills, money in, still owed, refunds, and how it was paid.
    public function summary(BillingSummaryRequest $request, BillingOverviewService $overview, AppointmentOverviewService $scope)
    {
        $data = $request->validated();
        if ($data['date_to'] < $data['date_from']) {
            return response()->json(['message' => 'The end date is before the start date.'], 422);
        }
        $ids = $scope->branchIds($request->user(), $data['branch_uuid'] ?? null);
        if ($ids === null) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return response()->json(['data' => $overview->overview($ids, $data['date_from'], $data['date_to'])]);
    }

    public function show(Request $request, string $uuid)
    {
        return $this->lookup->getBilling($request->user(), $uuid);
    }

    // The methods this business accepts (Settings → Payments), for the
    // payment dialog. Readable by anyone who takes payments.
    public function paymentOptions(Request $request)
    {
        return $this->billingService->paymentOptions($request->user());
    }

    public function discount(BillDiscountRequest $request, string $uuid)
    {
        $data = $request->validated();

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
    public function programDiscount(BillProgramDiscountRequest $request, string $uuid)
    {
        $data = $request->validated();

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

    public function refund(BillRefundRequest $request, string $uuid)
    {
        $data = $request->validated();

        return $this->billingService->refund($request->user(), $uuid, (float) $data['amount'], $data['reason'], $request, $data['service_uuid'] ?? null);
    }
}
