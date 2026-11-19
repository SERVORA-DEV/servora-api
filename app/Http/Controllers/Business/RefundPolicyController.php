<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\RefundPolicyRequest;
use App\Http\Requests\Business\RefundSettingsRequest;
use App\Service\Business\RefundPolicyService;
use Illuminate\Http\Request;

// The owner's refund policies (Business Defaults → Refunds) — see RefundPolicyService.
class RefundPolicyController extends Controller
{
    public function __construct(private RefundPolicyService $refundPolicies)
    {
    }

    public function index(Request $request)
    {
        return $this->refundPolicies->list($request->user());
    }

    public function store(RefundPolicyRequest $request)
    {
        return $this->refundPolicies->store($request->user(), $request->validated());
    }

    public function update(RefundPolicyRequest $request, string $uuid)
    {
        return $this->refundPolicies->update($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->refundPolicies->destroy($request->user(), $uuid);
    }

    // "Refunds outside these policies" on or off.
    public function settings(RefundSettingsRequest $request)
    {
        $allowed = $request->validated()['allow_custom'];

        return $this->refundPolicies->setCustomAllowed($request->user(), (bool) $allowed);
    }
}
