<?php

namespace App\Service\Business;

use App\Models\Billing;
use App\Models\BillingAdjustment;
use App\Models\AppointmentServiceItem;
use App\Models\RefundPolicy;
use App\Models\StaffRefundCharge;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\NotificationRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\AccountScope;
use App\Support\AppCache;
use App\Support\BillAdjustment;
use App\Support\History;
use App\Support\RefundCharge;
use App\Support\RefundPolicyRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// Changing a bill after it is paid: a voucher put on afterwards (its value
// comes off, the difference is handed back) or a refund, part or all. And
// before it is paid: a refund that takes money off what is owed ("credit"),
// for one service or the whole bill — same policies, approvals and "comes out
// of" as a refund after payment; while one waits, no payment is taken.
//
// Every change is a billing_adjustments row, so the bill keeps who did it,
// why, and who approved it. Who has to agree is App\Support\BillAdjustment:
// the owner and anyone with "Adjust paid bills" (payment_adjust) apply it at
// once; anyone else sends a request that a manager of the branch or the
// owner (front desk) or only the owner (manager) approves or declines. The
// person who asked is told the answer.
//
// The money moves through BillingService (giveBack, applyVoucherAfterPayment),
// the same as a refund at the checkout: payments keep refunded_amount and the
// reason, and the audit log has each step.
class BillingAdjustmentService
{
    // How long an answered request stays on the requester's list.
    private const KEEP_DECIDED_DAYS = 30;

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private BillingService $billingService,
        private NotificationRepository $notifications,
        private StaffNotifier $staffNotifier,
        private RefundPolicyService $refundPolicies,
    ) {
    }

    // ── Who ──────────────────────────────────────────────────────────────

    /** Whether this account applies a change at once ("Adjust paid bills"). */
    public function mayAdjust(User $user): bool
    {
        if ($user->role === 'business_owner') {
            return true;
        }
        if (! BillingAdjustment::ready() || ! in_array('payment_adjust', config("permission.{$user->role}", []), true)) {
            return false;
        }
        $row = $user->relationLoaded('permission') ? $user->permission : AppCache::permission($user->id);

        return $row ? $row->getAttribute('payment_adjust') === true : $user->role === 'manager';
    }

    /** NOW, MANAGER or OWNER for this account (App\Support\BillAdjustment::route); $mustAsk for a refund policy that always needs approval. */
    public function routeFor(User $user, bool $mustAsk = false): string
    {
        return BillAdjustment::route($user->role, $this->mayAdjust($user), $mustAsk);
    }

    public function mayDecide(User $user, BillingAdjustment $adjustment): bool
    {
        return $adjustment->status === BillingAdjustment::PENDING && BillAdjustment::mayDecide(
            $user->role,
            $this->mayAdjust($user),
            (string) $adjustment->needs,
            $user->role === 'manager' && $this->managesBranch($user, $adjustment->spa_branch_id),
            $adjustment->requested_by_user_id === $user->id,
        );
    }

    /** What the bill page needs to offer "Adjust": whether it can, and what happens when it is used. */
    public function optionsFor(Billing $billing, ?User $viewer): array
    {
        $problem = BillingAdjustment::ready()
            ? BillAdjustment::billProblem((string) $billing->status, $billing->billing_type)
            : 'Changing a paid bill isn\'t set up on this server yet.';

        $options = [
            'enabled' => BillingAdjustment::ready(),
            'problem' => $problem,
            // now | manager | owner
            'route' => $viewer ? $this->routeFor($viewer) : null,
        ];
        if ($viewer && BillingAdjustment::ready() && BillingAdjustment::creditsReady()
            && BillAdjustment::openBillProblem((string) $billing->status, $billing->billing_type) === null) {
            $options['before'] = $this->beforePaymentOptions($billing, $viewer);
        }
        if (! $viewer || $problem !== null) {
            return $options;
        }

        // The owner's refund policies, each worked out for this bill, and
        // whether this person may refund outside them.
        $paid = $this->billingService->paidGross($billing);
        $kept = $this->billingService->kept($billing);
        $policies = $this->refundPolicies->policiesFor($billing->spa_business_id);
        $billing->loadMissing('business');

        return $options + ['refund' => [
            'paid' => $paid,
            'kept' => $kept,
            'policies' => $policies->map(fn (RefundPolicy $p) => $this->refundPolicies->present($p) + [
                'amount' => RefundPolicyRule::amount($p->mode, $p->value !== null ? (float) $p->value : null, $paid, $kept),
                'route' => $this->routeFor($viewer, (bool) $p->needs_approval),
            ])->values()->all(),
            'custom_allowed' => RefundPolicyRule::customProblem($viewer->role, $this->refundPolicies->customAllowed($billing->business), $policies->isNotEmpty()) === null,
            // Whether a refund outside them can say who it comes out of yet.
            'charges_available' => StaffRefundCharge::ready(),
        ]];
    }

    /**
     * Refunding an open bill: what can come off it — the whole bill, or one
     * service (its charge, less what was already taken off it) — never more
     * than is still owed, and the policies to do it under. The amounts for each
     * policy are worked out on the page for the target picked (the same
     * RefundPolicyRule::amount, with the target's charge and what can come off).
     */
    private function beforePaymentOptions(Billing $billing, User $viewer): array
    {
        $owed = $this->billingService->balance($billing);
        $credited = $this->creditedByService($billing);
        $plan = $this->billingService->serviceRefunds($billing);
        $services = $plan ? AppointmentServiceItem::whereIn('id', array_keys($plan))->get()->keyBy('id') : collect();

        $targets = [[
            'service_uuid' => null,
            'name' => 'Entire bill',
            'charge' => (float) $billing->amount,
            'left' => $owed,
        ]];
        // In the order the bill lists them.
        foreach ($services->sortBy('sort_order') as $serviceId => $service) {
            $line = $plan[$serviceId];
            $targets[] = [
                'service_uuid' => $service->uuid,
                'name' => $service->displayName() ?? 'Service',
                'charge' => round((float) $line['charge'], 2),
                'left' => round(max(0, min((float) $line['charge'] - ($credited[$serviceId] ?? 0), $owed)), 2),
            ];
        }

        $policies = $this->refundPolicies->policiesFor($billing->spa_business_id);
        $billing->loadMissing('business');

        return [
            'owed' => $owed,
            'total' => (float) $billing->amount,
            'credited' => (float) $billing->credit_amount,
            'targets' => $targets,
            'policies' => $policies->map(fn (RefundPolicy $p) => $this->refundPolicies->present($p) + [
                'route' => $this->routeFor($viewer, (bool) $p->needs_approval),
            ])->values()->all(),
            'custom_allowed' => RefundPolicyRule::customProblem($viewer->role, $this->refundPolicies->customAllowed($billing->business), $policies->isNotEmpty()) === null,
            'charges_available' => StaffRefundCharge::ready(),
            'route' => $this->routeFor($viewer),
        ];
    }

    /** @return array<int, float>  service id => what was already taken off it before payment */
    private function creditedByService(Billing $billing): array
    {
        return BillingAdjustment::where('billing_id', $billing->id)
            ->where('kind', BillAdjustment::CREDIT)
            ->where('status', BillingAdjustment::APPLIED)
            ->whereNotNull('appointment_service_id')
            ->get(['appointment_service_id', 'amount'])
            ->groupBy('appointment_service_id')
            ->map(fn ($rows) => round($rows->sum(fn ($r) => (float) $r->amount), 2))
            ->all();
    }

    /**
     * For a refund before payment on one service: the service, its charge on
     * the bill, and what can still come off it — or why it can't be refunded.
     *
     * @return array{0: AppointmentServiceItem, 1: float, 2: float}|string
     */
    private function serviceTarget(Billing $billing, string $serviceUuid): array|string
    {
        $service = $billing->appointment_id
            ? AppointmentServiceItem::where('uuid', $serviceUuid)->where('appointment_id', $billing->appointment_id)->where('status', '!=', 'Cancelled')->first()
            : null;
        if (! $service) {
            return 'That service is not on this bill.';
        }
        $charge = round((float) ($this->billingService->serviceRefunds($billing)[$service->id]['charge'] ?? $service->subtotal), 2);

        return [$service, $charge, round(max(0, $charge - ($this->creditedByService($billing)[$service->id] ?? 0)), 2)];
    }

    // ── Read ─────────────────────────────────────────────────────────────

    /** The bill's adjustments, newest first, as the bill page shows them. */
    public function forBill(Billing $billing, ?User $viewer): array
    {
        if (! BillingAdjustment::ready()) {
            return [];
        }

        return BillingAdjustment::where('billing_id', $billing->id)
            ->when(StaffRefundCharge::ready(), fn ($q) => $q->with('charges'))
            ->when(BillingAdjustment::creditsReady(), fn ($q) => $q->with('service'))
            ->latest()->limit(50)->get()
            ->map(fn (BillingAdjustment $a) => $this->present($a->setRelation('billing', $billing), $viewer))
            ->values()->all();
    }

    // The requests waiting for this person to answer, and their own (waiting,
    // and answered in the last 30 days, so the answer can be read).
    public function list(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business || ! BillingAdjustment::ready()) {
            return response()->json(['data' => [], 'available' => false]);
        }

        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);
        $rows = BillingAdjustment::with(['billing.appointment.client', 'branch'])
            ->where('spa_business_id', $business->id)
            ->whereIn('spa_branch_id', $branchIds)
            ->where(function ($q) use ($user) {
                $q->where('status', BillingAdjustment::PENDING)
                    ->orWhere(fn ($own) => $own->where('requested_by_user_id', $user->id)
                        ->where('decided_at', '>=', now()->subDays(self::KEEP_DECIDED_DAYS)));
            })
            ->latest()->limit(100)->get()
            ->filter(fn (BillingAdjustment $a) => $a->requested_by_user_id === $user->id || $this->mayDecide($user, $a))
            ->map(fn (BillingAdjustment $a) => $this->present($a, $user))
            ->values();

        return response()->json(['data' => $rows, 'available' => true]);
    }

    // ── Write ────────────────────────────────────────────────────────────

    /**
     * Applies the change at once, or sends it for approval (routeFor).
     *
     * A refund under one of the owner's refund policies (refund_policy_uuid):
     * its amount when none is given, held to its rule, the policy's name as
     * the reason when none is typed. Outside them (when the owner allows it):
     * the amount and reason typed in. Either way who it comes out of — the
     * policy's, or charge_to outside them (App\Support\RefundCharge) — is
     * copied onto the request and charged to staff once the money goes back
     * (chargeStaff).
     *
     * @param  array{kind: string, amount?: float|null, client_voucher_uuid?: string|null, refund_policy_uuid?: string|null, reason?: string|null}  $payload
     */
    public function create(User $user, string $billingUuid, array $payload)
    {
        if (! BillingAdjustment::ready()) {
            return $this->refuse('Changing a paid bill isn\'t set up on this server yet.');
        }

        return DB::transaction(function () use ($user, $billingUuid, $payload) {
            $billing = Billing::with('appointment.client')
                ->where('uuid', $billingUuid)
                ->whereIn('spa_branch_id', $this->spaBusinessRepository->branchIdsForUser($user))
                ->lockForUpdate()
                ->firstOrFail();

            $kind = $payload['kind'];
            $before = $kind === BillAdjustment::CREDIT;
            if ($before && ! BillingAdjustment::creditsReady()) {
                return $this->refuse('Refunds before payment aren\'t set up on this server yet.');
            }
            $billProblem = $before
                ? BillAdjustment::openBillProblem((string) $billing->status, $billing->billing_type)
                : BillAdjustment::billProblem((string) $billing->status, $billing->billing_type);
            if ($billProblem) {
                return $this->refuse($billProblem);
            }
            if (BillingAdjustment::where('billing_id', $billing->id)->where('status', BillingAdjustment::PENDING)->exists()) {
                return $this->refuse('This bill already has a change waiting for approval. Wait for the answer, or withdraw it first.');
            }

            $amount = round((float) ($payload['amount'] ?? 0), 2);
            $reason = trim((string) ($payload['reason'] ?? ''));
            $voucherUuid = $payload['client_voucher_uuid'] ?? null;
            $voucherId = null;
            $voucherName = null;
            $policy = null;
            $chargeTo = RefundCharge::BUSINESS;
            $share = null;
            $service = null;

            if (BillAdjustment::isRefundLike($kind)) {
                // After payment: what was paid, and what is still held. Before
                // payment: the target's charge (one service, or the bill), and
                // what can come off it (never more than is still owed).
                if ($before) {
                    $owed = $this->billingService->balance($billing);
                    $serviceLeft = null;
                    if ($serviceUuid = $payload['service_uuid'] ?? null) {
                        $target = $this->serviceTarget($billing, $serviceUuid);
                        if (is_string($target)) {
                            return $this->refuse($target);
                        }
                        [$service, $base, $serviceLeft] = $target;
                    } else {
                        $base = (float) $billing->amount;
                    }
                    $kept = round(min($owed, $serviceLeft ?? $owed), 2);
                    $paid = $base;
                } else {
                    $kept = $this->billingService->kept($billing);
                    $paid = $this->billingService->paidGross($billing);
                }
                $policyUuid = $payload['refund_policy_uuid'] ?? null;
                if ($policyUuid) {
                    $policy = RefundPolicy::ready() ? RefundPolicy::where('spa_business_id', $billing->spa_business_id)->where('uuid', $policyUuid)->first() : null;
                    if (! $policy) {
                        return $this->refuse('That refund policy no longer exists. Pick another one.');
                    }
                    $value = $policy->value !== null ? (float) $policy->value : null;
                    if (! isset($payload['amount'])) {
                        $amount = RefundPolicyRule::amount($policy->mode, $value, $paid, $kept);
                    }
                    if ($problem = RefundPolicyRule::problem($policy->mode, $value, (bool) $policy->allow_less, $paid, $kept, $amount)) {
                        return $this->refuse($problem);
                    }
                    $reason = $reason !== '' ? $reason : $policy->name;
                    $chargeTo = $policy->chargeTo();
                    $share = $policy->staff_share !== null ? (float) $policy->staff_share : RefundCharge::shareFor($chargeTo);
                } else {
                    $hasPolicies = $this->refundPolicies->policiesFor($billing->spa_business_id)->isNotEmpty();
                    $billing->loadMissing('business');
                    if ($problem = RefundPolicyRule::customProblem($user->role, $this->refundPolicies->customAllowed($billing->business), $hasPolicies)) {
                        return $this->refuse($problem);
                    }
                    // Outside the policies: the person giving it says who it comes out of.
                    $chargeTo = in_array($payload['charge_to'] ?? null, RefundCharge::WHO, true) ? $payload['charge_to'] : RefundCharge::BUSINESS;
                    $share = RefundCharge::shareFor($chargeTo);
                }
                $problem = $before
                    ? BillAdjustment::creditProblem($amount, $this->billingService->balance($billing), $service ? $kept : null)
                    : BillAdjustment::refundProblem($amount, $kept);
                if ($problem) {
                    return $this->refuse($problem);
                }
            } else {
                if (! $voucherUuid) {
                    return $this->refuse('Pick the client\'s voucher.');
                }
                $plan = $this->billingService->voucherAfterPayment($billing, $voucherUuid);
                if (is_string($plan)) {
                    return $this->refuse($plan);
                }
                $amount = $plan['give_back'];
                $voucherId = $plan['voucher']->id;
                $voucherName = $plan['program']->name;
            }
            if ($problem = BillAdjustment::reasonProblem($reason)) {
                return $this->refuse($problem);
            }

            $route = $this->routeFor($user, (bool) $policy?->needs_approval);
            $now = $route === BillAdjustment::NOW;

            if ($now) {
                $done = $this->apply($billing, $kind, $amount, $voucherUuid, $reason, $user, $service?->id);
                if (is_string($done)) {
                    return $this->refuse($done);
                }
                $amount = $done;
            }

            $adjustment = BillingAdjustment::create([
                'spa_business_id' => $billing->spa_business_id,
                'spa_branch_id' => $billing->spa_branch_id,
                'billing_id' => $billing->id,
                'kind' => $kind,
                'amount' => $amount,
                'client_voucher_id' => $voucherId,
                'voucher_name' => $voucherName,
                'reason' => $reason,
                'status' => $now ? BillingAdjustment::APPLIED : BillingAdjustment::PENDING,
                'needs' => $now ? null : $route,
                'requested_by_user_id' => $user->id,
                'requested_by_name' => $user->displayName(),
                'requested_by_role' => $user->role,
                'decided_by_user_id' => $now ? $user->id : null,
                'decided_by_name' => $now ? $user->displayName() : null,
                'decided_at' => $now ? now() : null,
            ] + ($policy ? [
                // Its own copy of the policy as it was (App\Support\History).
                'refund_policy_id' => $policy->id,
                'refund_policy_name' => $policy->name,
                'refund_policy_rule' => $policy->rule(),
            ] : []) + ($service ? [
                // The service it was for, with its name as it was.
                'appointment_service_id' => $service->id,
                'service_name' => $service->displayName(),
            ] : []) + (BillAdjustment::isRefundLike($kind) && StaffRefundCharge::ready() ? [
                // Who it comes out of, as the policy (or the giver) says now.
                'charge_to' => $chargeTo,
                'staff_share' => $share,
                'charge_rule' => RefundCharge::describe($chargeTo, $share),
            ] : []));
            $adjustment->setRelation('billing', $billing);

            if ($now) {
                $this->chargeStaff($adjustment, $billing);
                $this->tellApplied($adjustment, $billing, $user);
            } else {
                $this->tellApprovers($adjustment, $billing, $user);
            }

            return response()->json([
                'message' => $now ? $this->doneMessage($adjustment) : 'Sent for approval to ' . ($route === BillAdjustment::OWNER ? 'the owner' : 'a manager or the owner') . '.',
                'data' => $this->present($adjustment, $user),
            ], 201);
        });
    }

    public function approve(User $user, string $uuid)
    {
        if (! BillingAdjustment::ready()) {
            return $this->refuse('Changing a paid bill isn\'t set up on this server yet.');
        }

        return DB::transaction(function () use ($user, $uuid) {
            $adjustment = $this->findPending($user, $uuid, lock: true);
            if (! $adjustment) {
                return $this->refuse('This request has already been answered or withdrawn.');
            }
            if (! $this->mayDecide($user, $adjustment)) {
                return $this->refuse($this->whoAnswers($adjustment), 403);
            }

            $billing = Billing::with('appointment.client')->whereKey($adjustment->billing_id)->lockForUpdate()->firstOrFail();
            $problem = $adjustment->kind === BillAdjustment::CREDIT
                ? BillAdjustment::openBillProblem((string) $billing->status, $billing->billing_type)
                : BillAdjustment::billProblem((string) $billing->status, $billing->billing_type);
            if ($problem) {
                return $this->refuse($problem . ' Decline the request instead.');
            }

            // Worked out again: the bill or the voucher may have changed since it was asked.
            $done = $this->apply($billing, $adjustment->kind, (float) $adjustment->amount, $adjustment->voucher?->uuid, $adjustment->reason, $user, $adjustment->appointment_service_id);
            if (is_string($done)) {
                return $this->refuse($done . ' Decline the request instead.');
            }

            $adjustment->update([
                'status' => BillingAdjustment::APPLIED,
                'amount' => $done,
                'decided_by_user_id' => $user->id,
                'decided_by_name' => $user->displayName(),
                'decided_at' => now(),
            ]);
            $adjustment->setRelation('billing', $billing);
            $this->chargeStaff($adjustment, $billing);

            $this->tellRequester($adjustment, $billing, $user, true);

            return response()->json(['message' => 'Approved. ' . $this->doneMessage($adjustment), 'data' => $this->present($adjustment, $user)]);
        });
    }

    public function decline(User $user, string $uuid, ?string $note)
    {
        if (! BillingAdjustment::ready()) {
            return $this->refuse('Changing a paid bill isn\'t set up on this server yet.');
        }

        return DB::transaction(function () use ($user, $uuid, $note) {
            $adjustment = $this->findPending($user, $uuid, lock: true);
            if (! $adjustment) {
                return $this->refuse('This request has already been answered or withdrawn.');
            }
            if (! $this->mayDecide($user, $adjustment)) {
                return $this->refuse($this->whoAnswers($adjustment), 403);
            }

            $adjustment->update([
                'status' => BillingAdjustment::DECLINED,
                'decided_by_user_id' => $user->id,
                'decided_by_name' => $user->displayName(),
                'decided_at' => now(),
                'decision_note' => trim((string) $note) ?: null,
            ]);
            $billing = Billing::with('appointment.client')->find($adjustment->billing_id);
            if ($billing) {
                $adjustment->setRelation('billing', $billing);
                $this->tellRequester($adjustment, $billing, $user, false);
            }

            return response()->json(['message' => 'Request declined.', 'data' => $this->present($adjustment, $user)]);
        });
    }

    // The person who asked takes it back while it is still waiting.
    public function withdraw(User $user, string $uuid)
    {
        $adjustment = BillingAdjustment::ready() ? $this->findPending($user, $uuid) : null;
        if (! $adjustment) {
            return $this->refuse('This request has already been answered or withdrawn.');
        }
        if ($adjustment->requested_by_user_id !== $user->id) {
            return $this->refuse('Only the person who asked can withdraw it.', 403);
        }

        $adjustment->update(['status' => BillingAdjustment::WITHDRAWN, 'decided_at' => now()]);

        return response()->json(['message' => 'Request withdrawn.', 'data' => $this->present($adjustment, $user)]);
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * An applied refund under a policy that charges staff: each person who did
     * the visit gets their part (App\Support\RefundCharge::split, by what they
     * did), dated today, to come off their earnings like a penalty.
     */
    private function chargeStaff(BillingAdjustment $adjustment, Billing $billing): void
    {
        if (! BillAdjustment::isRefundLike($adjustment->kind) || ! $adjustment->charge_to || $adjustment->charge_to === RefundCharge::BUSINESS || ! StaffRefundCharge::ready()) {
            return;
        }

        // For one service: only who did that service.
        [$weights, $names] = $this->whoDidTheVisit($billing, $adjustment->appointment_service_id);
        $split = RefundCharge::split((float) $adjustment->amount, $adjustment->charge_to, $adjustment->staff_share !== null ? (float) $adjustment->staff_share : null, $weights);
        foreach ($split['staff'] as $staffId => $part) {
            StaffRefundCharge::create([
                'spa_business_id' => $billing->spa_business_id,
                'spa_branch_id' => $billing->spa_branch_id,
                'billing_adjustment_id' => $adjustment->id,
                'billing_id' => $billing->id,
                'staff_id' => $staffId,
                'amount' => $part,
                'charged_on' => now()->toDateString(),
                'staff_name' => $names[$staffId] ?? null,
                'billing_number' => $billing->billing_number,
                'policy_name' => $adjustment->refund_policy_name,
            ]);
        }
        $adjustment->update(['staff_charged' => round(array_sum($split['staff']), 2)]);
        $adjustment->load('charges');
    }

    /**
     * The people who did the visit (or one service of it) and what each did:
     * a service's charge on the bill, shared equally between everyone who
     * completed it.
     *
     * @return array{0: array<int, float>, 1: array<int, string>}  staff id => weight, staff id => name
     */
    private function whoDidTheVisit(Billing $billing, ?int $onlyServiceId = null): array
    {
        if (! $billing->appointment_id) {
            return [[], []];
        }
        $plan = $this->billingService->serviceRefunds($billing);
        $services = AppointmentServiceItem::with(['therapistAssignments' => fn ($q) => $q->where('assignment_status', 'Completed')->whereNotNull('staff_id')])
            ->where('appointment_id', $billing->appointment_id)
            ->where('status', '!=', 'Cancelled')
            ->when($onlyServiceId, fn ($q) => $q->whereKey($onlyServiceId))
            ->get();

        $weights = [];
        $names = [];
        foreach ($services as $service) {
            $done = $service->therapistAssignments;
            if ($done->isEmpty()) {
                continue;
            }
            $charge = (float) ($plan[$service->id]['charge'] ?? $service->subtotal);
            foreach ($done as $assignment) {
                // A free service still counts for who did it.
                $weights[$assignment->staff_id] = ($weights[$assignment->staff_id] ?? 0) + max($charge, 0.01) / $done->count();
                $names[$assignment->staff_id] ??= $assignment->staffName();
            }
        }

        return [$weights, $names];
    }

    /** Moves the money on the locked bill; what was handed back (or taken off), or why it couldn't be. */
    private function apply(Billing $billing, string $kind, float $amount, ?string $voucherUuid, string $reason, User $actor, ?int $serviceId = null): float|string
    {
        if ($kind === BillAdjustment::CREDIT) {
            $serviceLeft = null;
            if ($serviceId) {
                $service = AppointmentServiceItem::find($serviceId);
                $target = $service ? $this->serviceTarget($billing, $service->uuid) : 'That service is no longer on this bill.';
                if (is_string($target)) {
                    return $target;
                }
                $serviceLeft = $target[2];
            }
            if ($problem = BillAdjustment::creditProblem($amount, $this->billingService->balance($billing), $serviceLeft)) {
                return $problem;
            }
            $this->billingService->applyCredit($billing, round($amount, 2), $actor, request());

            return round($amount, 2);
        }

        if ($kind === BillAdjustment::REFUND) {
            if ($problem = BillAdjustment::refundProblem($amount, $this->billingService->kept($billing))) {
                return $problem;
            }
            $this->billingService->giveBack($billing, $amount, 'Refund after payment — ' . $reason, $actor, request());

            return round($amount, 2);
        }

        if (! $voucherUuid) {
            return 'The voucher on this request is no longer in the client\'s wallet.';
        }

        return $this->billingService->applyVoucherAfterPayment($billing, $voucherUuid, $reason, $actor, request());
    }

    private function findPending(User $user, string $uuid, bool $lock = false): ?BillingAdjustment
    {
        return BillingAdjustment::with('voucher')
            ->where('uuid', $uuid)
            ->whereIn('spa_branch_id', $this->spaBusinessRepository->branchIdsForUser($user))
            ->where('status', BillingAdjustment::PENDING)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function managesBranch(User $user, ?int $branchId): bool
    {
        return $branchId !== null && in_array($user->id, AccountScope::userIdsAt([$branchId], ['manager']), true);
    }

    private function whoAnswers(BillingAdjustment $adjustment): string
    {
        if ($adjustment->requested_by_user_id === request()->user()?->id) {
            return 'You can\'t answer your own request.';
        }

        return $adjustment->needs === BillAdjustment::OWNER
            ? 'Only the owner can answer a manager\'s request.'
            : 'Only the owner, or a manager of this branch who can adjust paid bills, can answer this.';
    }

    // ── Telling people ──────────────────────────────────────────────────
    //
    // Titles the web app looks for to open the bill (staffNotificationDestination):
    // Adjustment Approval Needed, Adjustment Approved, Adjustment Declined, Bill Adjusted.

    private function tellApprovers(BillingAdjustment $adjustment, Billing $billing, User $requester): void
    {
        $billing->loadMissing('branch');
        $ownerId = SpaBusiness::whereKey($billing->spa_business_id)->value('owner_id');
        $ids = [$ownerId];
        if ($adjustment->needs === BillAdjustment::MANAGER && $billing->spa_branch_id) {
            $managers = User::with('permission')->whereIn('id', AccountScope::userIdsAt([$billing->spa_branch_id], ['manager']))
                ->where('role', 'manager')->get();
            foreach ($managers as $manager) {
                if ($this->mayAdjust($manager)) {
                    $ids[] = $manager->id;
                }
            }
        }

        $who = $requester->displayName() . ' (' . $this->roleLabel($requester->role) . ')' . ($billing->branch ? " at {$billing->branch->branch_name}" : '');
        $this->tell($ids, $requester->id, 'Adjustment Approval Needed',
            "{$who} asked to {$this->what($adjustment)} on paid bill {$billing->billing_number}{$this->forClient($billing)}. Reason: {$adjustment->reason}." . ($adjustment->charge_rule ? " {$adjustment->charge_rule}." : '') . ' Open the bill to approve or decline.',
            $billing->uuid);
    }

    private function tellRequester(BillingAdjustment $adjustment, Billing $billing, User $decider, bool $approved): void
    {
        if (! $adjustment->requested_by_user_id) {
            return;
        }
        $by = $decider->displayName();
        $message = $approved
            ? "{$by} approved your request to {$this->what($adjustment)} on bill {$billing->billing_number}{$this->forClient($billing)}. It has been applied."
            : "{$by} declined your request to {$this->what($adjustment)} on bill {$billing->billing_number}{$this->forClient($billing)}." . ($adjustment->decision_note ? " Reason: {$adjustment->decision_note}" : '');

        $this->tell([$adjustment->requested_by_user_id], $decider->id, $approved ? 'Adjustment Approved' : 'Adjustment Declined', $message, $billing->uuid);
    }

    // Applied at once by someone allowed to: the branch's managers and the
    // owner are told, as for a refund at the checkout.
    private function tellApplied(BillingAdjustment $adjustment, Billing $billing, User $actor): void
    {
        $billing->loadMissing('branch');
        if (! $billing->branch) {
            return;
        }
        $this->staffNotifier->branch(
            $billing->branch,
            'Bill Adjusted',
            "{$actor->displayName()} {$this->did($adjustment)} on paid bill {$billing->billing_number}{$this->forClient($billing)}. Reason: {$adjustment->reason}.",
            type: 'Payment',
            roles: ['manager'],
            exceptUserId: $actor->id,
            reference: $billing->uuid,
        );
    }

    /** @param  array<int, int|null>  $userIds */
    private function tell(array $userIds, ?int $exceptUserId, string $title, string $message, string $reference): void
    {
        $ids = collect($userIds)->filter()->unique()->reject(fn ($id) => $id === $exceptUserId)->values()->all();
        if (! $ids) {
            return;
        }
        DB::afterCommit(function () use ($ids, $title, $message, $reference) {
            foreach ($ids as $id) {
                try {
                    $this->notifications->create($id, $title, $message, 'Payment', $reference);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }

    // "refund ₱500" / "put the voucher “Free foot spa” on it and hand back ₱300"
    private function what(BillingAdjustment $a): string
    {
        $peso = '₱' . number_format((float) $a->amount, 2);
        if ($a->kind === BillAdjustment::CREDIT) {
            return "take {$peso} off the bill before payment" . ($a->service_name ? " for {$a->service_name}" : '') . ($a->refund_policy_name ? " ({$a->refund_policy_name})" : '');
        }

        return $a->kind === BillAdjustment::REFUND
            ? "refund {$peso}" . ($a->refund_policy_name ? " ({$a->refund_policy_name})" : '')
            : "apply the voucher “{$a->voucher_name}” and hand back {$peso}";
    }

    private function did(BillingAdjustment $a): string
    {
        $peso = '₱' . number_format((float) $a->amount, 2);
        if ($a->kind === BillAdjustment::CREDIT) {
            return "took {$peso} off before payment" . ($a->service_name ? " for {$a->service_name}" : '') . ($a->refund_policy_name ? " ({$a->refund_policy_name})" : '');
        }

        return $a->kind === BillAdjustment::REFUND
            ? "refunded {$peso}" . ($a->refund_policy_name ? " ({$a->refund_policy_name})" : '')
            : "applied the voucher “{$a->voucher_name}” and handed back {$peso}";
    }

    private function doneMessage(BillingAdjustment $a): string
    {
        $peso = '₱' . number_format((float) $a->amount, 2);
        if ($a->kind === BillAdjustment::CREDIT) {
            return "{$peso} taken off the bill" . ($a->service_name ? " for {$a->service_name}" : '') . '.';
        }

        return $a->kind === BillAdjustment::REFUND
            ? "{$peso} refunded. Hand it back to the client."
            : "Voucher applied. Hand {$peso} back to the client.";
    }

    private function forClient(Billing $billing): string
    {
        $name = $this->clientName($billing);

        return $name ? " ({$name})" : '';
    }

    private function clientName(Billing $billing): ?string
    {
        $client = $billing->appointment?->client;

        return $client ? History::person($client->first_name, $client->last_name) : null;
    }

    private function roleLabel(?string $role): string
    {
        return match ($role) {
            'business_owner' => 'Owner',
            'manager' => 'Manager',
            default => 'Front desk',
        };
    }

    private function present(BillingAdjustment $a, ?User $viewer): array
    {
        $billing = $a->relationLoaded('billing') ? $a->billing : null;

        return [
            'uuid' => $a->uuid,
            'kind' => $a->kind,
            'amount' => (float) $a->amount,
            'voucher_name' => $a->voucher_name,
            // The refund policy it was given under, as it was then.
            'refund_policy_name' => $a->refund_policy_name,
            'refund_policy_rule' => $a->refund_policy_rule,
            // Before payment: the service it was for (null: the whole bill).
            'service_uuid' => $a->relationLoaded('service') ? $a->service?->uuid : null,
            'service_name' => $a->service_name,
            // Who it comes out of ("Staff pay 50%, the business the rest"), and
            // what came off whose earnings once applied.
            'charge_rule' => $a->charge_rule,
            'staff_charged' => (float) ($a->staff_charged ?? 0),
            'staff_charges' => $a->relationLoaded('charges')
                ? $a->charges->map(fn ($c) => ['staff_name' => $c->staff_name, 'amount' => (float) $c->amount])->values()->all()
                : [],
            'reason' => $a->reason,
            'status' => $a->status,
            // While it waits: who may answer — manager (a manager or the owner) | owner.
            'needs' => $a->needs,
            'billing_uuid' => $billing?->uuid,
            'billing_number' => $billing?->billing_number,
            'client_name' => $billing ? $this->clientName($billing) : null,
            'branch_name' => $a->relationLoaded('branch') ? $a->branch?->branch_name : null,
            'requested_by' => $a->requested_by_name,
            'requested_by_role' => $this->roleLabel($a->requested_by_role),
            'requested_by_me' => $viewer !== null && $a->requested_by_user_id === $viewer->id,
            'requested_at' => $a->created_at?->toIso8601String(),
            'decided_by' => $a->decided_by_name,
            'decided_at' => $a->decided_at?->toIso8601String(),
            'decision_note' => $a->decision_note,
            'can_decide' => $viewer !== null && $this->mayDecide($viewer, $a),
            'can_withdraw' => $viewer !== null && $a->status === BillingAdjustment::PENDING && $a->requested_by_user_id === $viewer->id,
        ];
    }

    private function refuse(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
