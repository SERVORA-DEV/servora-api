<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\CustomerProgramRequest;
use App\Service\Business\CustomerProgramService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerProgramController extends Controller
{
    public function __construct(private CustomerProgramService $programs)
    {
    }

    public function index(Request $request)
    {
        return $this->programs->index($request->user());
    }

    // The module switch (Business Settings → Customer Programs).
    public function module(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return $this->programs->setEnabled($request->user(), (bool) $data['enabled']);
    }

    public function store(CustomerProgramRequest $request)
    {
        return $this->programs->store($request->user(), $request->validated());
    }

    public function update(CustomerProgramRequest $request, string $uuid)
    {
        return $this->programs->update($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->programs->destroy($request->user(), $uuid);
    }

    public function branch(Request $request, string $uuid, string $branchUuid)
    {
        $data = $request->validate(['offered' => ['required', 'boolean']]);

        return $this->programs->setBranch($request->user(), $uuid, $branchUuid, (bool) $data['offered']);
    }

    public function memberships(Request $request)
    {
        return $this->programs->memberships($request->user(), $request->query('branch'));
    }

    public function sellMembership(Request $request)
    {
        $data = $request->validate($this->paymentRules() + [
            'client_uuid' => ['required', 'uuid'],
            'program_uuid' => ['required', 'uuid'],
            'branch_uuid' => ['required', 'uuid'],
        ]);

        return $this->programs->sellMembership($request->user(), $data);
    }

    public function renewMembership(Request $request, string $uuid)
    {
        return $this->programs->renewMembership($request->user(), $uuid, $request->validate($this->paymentRules()));
    }

    public function cancelMembership(Request $request, string $uuid)
    {
        return $this->programs->cancelMembership($request->user(), $uuid);
    }

    public function clientRewards(Request $request, string $uuid)
    {
        return $this->programs->clientRewards($request->user(), $uuid);
    }

    public function redeem(Request $request, string $uuid)
    {
        $data = $request->validate(['program_uuid' => ['required', 'uuid']]);

        return $this->programs->redeemForClient($request->user(), $uuid, $data['program_uuid']);
    }

    // Same as BillingPaymentRequest; the amount is the membership's price.
    private function paymentRules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Online Banking', 'Credit Card', 'Debit Card', 'QR Code', 'Other'])],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0.01'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
