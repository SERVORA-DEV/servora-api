<?php

namespace App\Service\Business;

use App\Models\RefundPolicy;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\StaffRefundCharge;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\RefundCharge;
use App\Support\RefundPolicyRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

// The owner's refund policies (Business Defaults → Refunds): the reasons a
// paid bill can be refunded for, how much each gives back
// (App\Support\RefundPolicyRule), and who it comes out of — the business,
// the staff who did the visit, or equally (App\Support\RefundCharge). The
// front desk picks one when it refunds a paid bill (BillingAdjustmentService).
// Plus one switch: whether a refund that fits none of them may be given
// (payments.allow_custom_refunds) — the giver then says who it comes out of.
//
// Everyone at the business reads them; only the owner changes them.
class RefundPolicyService
{
    private const MAX = 20;

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private BusinessSettingsService $businessSettingsService,
    ) {
    }

    /** @return Collection<int, RefundPolicy> */
    public function policiesFor(int $businessId): Collection
    {
        if (! RefundPolicy::ready()) {
            return collect();
        }

        return RefundPolicy::where('spa_business_id', $businessId)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Whether a refund that fits no policy may be given (the owner always may). */
    public function customAllowed(?SpaBusiness $business): bool
    {
        $business?->loadMissing('settings');
        $payments = $business?->settings?->section('payments') ?? SpaBusinessSetting::DEFAULTS['payments'];

        return (bool) ($payments['allow_custom_refunds'] ?? true);
    }

    public function list(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->refuse('No spa business found for this account.');
        }

        return response()->json([
            'data' => $this->policiesFor($business->id)->map(fn (RefundPolicy $p) => $this->present($p))->values(),
            'allow_custom' => $this->customAllowed($business),
            'available' => RefundPolicy::ready(),
            // Whether "who it comes out of" can be kept yet (the migration has been run).
            'charges_available' => StaffRefundCharge::ready(),
        ]);
    }

    public function store(User $user, array $payload)
    {
        $business = $this->ownerBusiness($user);
        if ($business instanceof JsonResponse) {
            return $business;
        }
        if ($this->policiesFor($business->id)->count() >= self::MAX) {
            return $this->refuse('You can have up to ' . self::MAX . ' refund policies. Remove one first.');
        }
        $fields = $this->fields($payload, $business->id);
        if (is_string($fields)) {
            return $this->refuse($fields);
        }

        $policy = RefundPolicy::create($fields + [
            'spa_business_id' => $business->id,
            'sort_order' => (int) RefundPolicy::where('spa_business_id', $business->id)->withTrashed()->max('sort_order') + 1,
        ]);

        return response()->json(['message' => 'Refund policy added.', 'data' => $this->present($policy)], 201);
    }

    public function update(User $user, string $uuid, array $payload)
    {
        $business = $this->ownerBusiness($user);
        if ($business instanceof JsonResponse) {
            return $business;
        }
        $policy = RefundPolicy::where('spa_business_id', $business->id)->where('uuid', $uuid)->first();
        if (! $policy) {
            return $this->refuse('That refund policy no longer exists.', 404);
        }
        $fields = $this->fields($payload, $business->id, $policy->id);
        if (is_string($fields)) {
            return $this->refuse($fields);
        }

        // Refunds already given under it keep their own copy of the name and rule.
        $policy->update($fields);

        return response()->json(['message' => 'Refund policy saved.', 'data' => $this->present($policy)]);
    }

    public function destroy(User $user, string $uuid)
    {
        $business = $this->ownerBusiness($user);
        if ($business instanceof JsonResponse) {
            return $business;
        }
        $policy = RefundPolicy::where('spa_business_id', $business->id)->where('uuid', $uuid)->first();
        if (! $policy) {
            return $this->refuse('That refund policy no longer exists.', 404);
        }
        $policy->delete();

        return response()->json(['message' => 'Refund policy removed.']);
    }

    public function setCustomAllowed(User $user, bool $allowed)
    {
        $business = $this->ownerBusiness($user, needsTable: false);
        if ($business instanceof JsonResponse) {
            return $business;
        }
        $settings = $this->businessSettingsService->settingsFor($business);
        $settings->payments = array_merge($settings->payments ?? [], ['allow_custom_refunds' => $allowed]);
        $settings->save();

        return response()->json([
            'message' => $allowed ? 'Staff can give refunds outside these policies.' : 'Staff can only refund under one of these policies.',
            'allow_custom' => $allowed,
        ]);
    }

    public function present(RefundPolicy $policy): array
    {
        return [
            'uuid' => $policy->uuid,
            'name' => $policy->name,
            'description' => $policy->description,
            'mode' => $policy->mode,
            'value' => $policy->value !== null ? (float) $policy->value : null,
            'allow_less' => (bool) $policy->allow_less,
            'needs_approval' => (bool) $policy->needs_approval,
            // "Up to 50% of what was paid"
            'rule' => $policy->rule(),
            // business | staff | both (equal)
            'charge_to' => $policy->chargeTo(),
            'staff_share' => $policy->staff_share !== null ? (float) $policy->staff_share : null,
            // "Split equally between staff and the business"
            'charge_rule' => $policy->chargeRule(),
        ];
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @return array<string, mixed>|string */
    private function fields(array $payload, int $businessId, ?int $exceptId = null): array|string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($payload['name'] ?? '')));
        if (mb_strlen($name) < 2) {
            return 'Give the policy a name.';
        }
        $mode = (string) ($payload['mode'] ?? '');
        $value = isset($payload['value']) && $payload['value'] !== '' && $payload['value'] !== null ? round((float) $payload['value'], 2) : null;
        if ($problem = RefundPolicyRule::invalid($mode, $value)) {
            return $problem;
        }
        $taken = RefundPolicy::where('spa_business_id', $businessId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($taken) {
            return "There is already a refund policy called \"{$name}\".";
        }
        $chargeTo = (string) ($payload['charge_to'] ?? RefundCharge::BUSINESS);
        if ($problem = RefundCharge::invalid($chargeTo)) {
            return $problem;
        }
        $charge = StaffRefundCharge::ready()
            ? ['charge_to' => $chargeTo, 'staff_share' => RefundCharge::shareFor($chargeTo)]
            : [];

        return $charge + [
            'name' => $name,
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'mode' => $mode,
            'value' => $mode === RefundPolicyRule::FULL ? null : $value,
            'allow_less' => (bool) ($payload['allow_less'] ?? true),
            'needs_approval' => (bool) ($payload['needs_approval'] ?? false),
        ];
    }

    private function ownerBusiness(User $user, bool $needsTable = true): SpaBusiness|JsonResponse
    {
        if ($user->role !== 'business_owner') {
            return $this->refuse('Only the owner sets the refund policies.', 403);
        }
        if ($needsTable && ! RefundPolicy::ready()) {
            return $this->refuse('Refund policies aren\'t set up on this server yet.');
        }

        return $this->spaBusinessRepository->findForUser($user) ?? $this->refuse('No spa business found for this account.');
    }

    private function refuse(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
