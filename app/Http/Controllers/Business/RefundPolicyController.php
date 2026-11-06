<?php

namespace App\Http\Controllers\Business;

use App\Rules\NoEmoji;
use App\Http\Controllers\Controller;
use App\Service\Business\RefundPolicyService;
use App\Support\RefundCharge;
use App\Support\RefundPolicyRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function store(Request $request)
    {
        return $this->refundPolicies->store($request->user(), $this->validated($request));
    }

    public function update(Request $request, string $uuid)
    {
        return $this->refundPolicies->update($request->user(), $uuid, $this->validated($request));
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->refundPolicies->destroy($request->user(), $uuid);
    }

    // "Refunds outside these policies" on or off.
    public function settings(Request $request)
    {
        $allowed = $request->validate(['allow_custom' => ['required', 'boolean']])['allow_custom'];

        return $this->refundPolicies->setCustomAllowed($request->user(), (bool) $allowed);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', new NoEmoji()],
            'description' => ['nullable', 'string', 'max:200'],
            'mode' => ['required', Rule::in(RefundPolicyRule::MODES)],
            'value' => ['nullable', 'numeric', 'min:0', $request->input('mode') === 'percent' ? 'max:100' : 'max:1000000'],
            'allow_less' => ['sometimes', 'boolean'],
            'needs_approval' => ['sometimes', 'boolean'],
            'charge_to' => ['sometimes', Rule::in(RefundCharge::WHO)],
        ]);
    }
}
