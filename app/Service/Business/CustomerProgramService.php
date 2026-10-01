<?php

namespace App\Service\Business;

use App\Http\Resources\CustomerProgramResource;
use App\Models\Billing;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\Business\ClientRepository;
use App\Repository\BillingRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Customer programs as the business runs them:
//   - the owner creates/edits programs and picks the branches that offer them
//     (a manager switches programs on/off for their own branch);
//   - the front desk sells memberships and redeems points for a client;
//   - summaryFor() is what a client holds at a spa — the same shape for the
//     front desk's client profile and the client app's Rewards tab.
// Earning and expiry are ProgramRewardService's job.
class CustomerProgramService
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private ClientRepository $clientRepository,
        private BillingRepository $billingRepository,
        private PaymentRepository $paymentRepository,
        private BillingService $billingService,
        private ProgramRewardService $rewards,
        private BusinessSettingsService $settingsService,
        private SubscriptionRepository $subscriptionRepository,
    ) {
    }

    // ── Programs ──────────────────────────────────────────────────────────

    public function index(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $isOwner = $user->role === 'business_owner';
        $branchIds = $isOwner ? null : $this->branchIds($user);

        $programs = CustomerProgram::with(['branches', 'items'])
            ->where('spa_business_id', $business->id)
            ->when(! $isOwner, fn ($q) => $q->where('is_active', true))
            ->orderBy('created_at')
            ->get();

        $subscription = $this->subscriptionRepository->findActiveOrInGraceForBusiness($business->id, SystemSetting::current()->subscription_grace_period_days);

        return response()->json(['data' => [
            'enabled' => $this->rewards->switchedOn($business->id),
            // Whether the current plan includes customer programs (writes are
            // gated by plan.feature:reward_access).
            'plan_allows' => (bool) $subscription?->plan?->reward_access,
            'programs' => $programs->map(fn ($p) => (new CustomerProgramResource($p))->onlyBranches($branchIds)->resolve())->values(),
            // Services whose options earn bonus points (Loyalty tab).
            'bonus_services' => $isOwner ? $this->bonusServices($business->id, null, 20) : [],
        ]]);
    }

    public function setEnabled(User $user, bool $enabled)
    {
        $this->settingsService->updateSection($user, 'programs', ['enabled' => $enabled]);

        return $this->index($user);
    }

    public function store(User $user, array $data)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($business, $data) {
            // Loyalty is one program for the whole business: saving it again
            // updates the existing one.
            $program = $data['type'] === 'loyalty'
                ? CustomerProgram::where('spa_business_id', $business->id)->where('type', 'loyalty')->first()
                : null;

            if ($problem = $this->problem($data['type'], $data)) {
                return $this->reject($problem);
            }

            // A new program with no branches chosen is offered everywhere, so
            // it never starts out reaching nobody.
            if (! $program && ! array_key_exists('branch_uuids', $data)) {
                $data['branch_uuids'] = SpaBranch::where('spa_business_id', $business->id)->pluck('uuid')->all();
            }

            $program ??= new CustomerProgram(['spa_business_id' => $business->id, 'type' => $data['type']]);
            $this->fill($program, $data, $business);

            return new CustomerProgramResource($program->load(['branches', 'items']));
        });
    }

    public function update(User $user, string $uuid, array $data)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($business, $uuid, $data) {
            $program = $this->program($business, $uuid, lock: true);

            if ($problem = $this->problem($program->type, array_merge($this->current($program), $data))) {
                return $this->reject($problem);
            }

            $this->fill($program, $data, $business);

            return new CustomerProgramResource($program->load(['branches', 'items']));
        });
    }

    public function destroy(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        DB::transaction(function () use ($business, $uuid) {
            $program = $this->program($business, $uuid, lock: true);
            // Take it out of every membership bundle; vouchers already in
            // clients' wallets stay usable until they expire.
            DB::table('customer_program_items')->where('item_program_id', $program->id)->delete();
            $program->delete();
        });

        return response()->json(['message' => 'Program removed.']);
    }

    // A branch switching one program on/off. The owner may do it for any
    // branch; a manager only for their own.
    public function setBranch(User $user, string $uuid, string $branchUuid, bool $offered)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $branch = SpaBranch::where('uuid', $branchUuid)->where('spa_business_id', $business->id)->firstOrFail();
        if (! in_array($branch->id, $this->branchIds($user), true)) {
            abort(403, 'You can only change programs for your own branch.');
        }

        $program = $this->program($business, $uuid);
        $offered
            ? $program->branches()->syncWithoutDetaching([$branch->id])
            : $program->branches()->detach($branch->id);

        return (new CustomerProgramResource($program->load(['branches', 'items'])))
            ->onlyBranches($user->role === 'business_owner' ? null : $this->branchIds($user));
    }

    // ── Memberships (front desk) ──────────────────────────────────────────

    public function memberships(User $user, ?string $branchUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $branchIds = $this->branchIds($user);
        if ($branchUuid) {
            $branch = SpaBranch::where('uuid', $branchUuid)->where('spa_business_id', $business->id)->firstOrFail();
            $branchIds = array_values(array_intersect($branchIds, [$branch->id]));
        }

        $rows = ClientMembership::with(['client', 'program', 'branch'])
            ->whereHas('client', fn ($q) => $q->where('spa_business_id', $business->id))
            ->whereIn('spa_branch_id', $branchIds)
            ->where('status', 'active')->where('ends_at', '>', now())
            ->orderBy('ends_at')
            ->get();

        return ['data' => $rows->map(fn ($m) => $this->membershipRow($m))->values()];
    }

    public function sellMembership(User $user, array $data)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $business, $data) {
            $branch = SpaBranch::where('uuid', $data['branch_uuid'])->where('spa_business_id', $business->id)->firstOrFail();
            if (! in_array($branch->id, $this->branchIds($user), true)) {
                abort(403, 'You can only sell memberships at your own branch.');
            }
            $client = $this->client($user, $business, $data['client_uuid']);
            $program = CustomerProgram::where('uuid', $data['program_uuid'])->where('spa_business_id', $business->id)
                ->where('type', 'membership')->where('is_active', true)->first();
            if (! $program || ! $program->offeredAt($branch->id)) {
                return $this->reject('That membership isn\'t offered at this branch.');
            }
            if (! $this->rewards->enabledFor($business->id)) {
                return $this->reject('Customer programs are turned off.');
            }

            $current = ClientMembership::where('client_id', $client->id)->where('status', 'active')->where('ends_at', '>', now())->lockForUpdate()->first();
            if ($current && $current->customer_program_id !== $program->id) {
                return $this->reject("{$client->first_name} already has {$current->program?->name} until " . $current->ends_at->format('M j, Y') . ' — cancel it first.');
            }

            // Renewing an unexpired one extends it from its end date.
            return $this->startPeriod($user, $business, $branch, $client, $program, $data, from: $current?->ends_at);
        });
    }

    public function renewMembership(User $user, string $uuid, array $data)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $business, $uuid, $data) {
            $membership = $this->membership($user, $business, $uuid);
            if ($membership->status === 'cancelled') {
                return $this->reject('This membership was cancelled — sell a new one instead.');
            }
            $program = $membership->program;
            if (! $program || $program->trashed() || ! $program->is_active) {
                return $this->reject('This membership is no longer offered.');
            }
            $branch = $membership->branch ?? SpaBranch::whereIn('id', $this->branchIds($user))->firstOrFail();
            $from = $membership->ends_at->isFuture() ? $membership->ends_at : null;

            return $this->startPeriod($user, $business, $branch, $membership->client, $program, $data, from: $from);
        });
    }

    public function cancelMembership(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $business, $uuid) {
            $membership = $this->membership($user, $business, $uuid);
            // Cancelling ends every running period of this membership for the
            // client, and takes back the bundled vouchers not used yet.
            $periods = ClientMembership::where('client_id', $membership->client_id)
                ->where('customer_program_id', $membership->customer_program_id)
                ->where('status', 'active')->get();
            foreach ($periods as $period) {
                $period->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                ClientVoucher::where('client_membership_id', $period->id)->where('status', 'available')->update(['status' => 'cancelled']);
            }

            return ['data' => $this->membershipRow($membership->fresh(['client', 'program', 'branch']))];
        });
    }

    private function startPeriod(User $user, SpaBusiness $business, SpaBranch $branch, Client $client, CustomerProgram $program, array $payment, $from = null)
    {
        $price = round((float) $program->value('price', 0), 2);
        $billing = null;

        if ($price > 0) {
            $billing = $this->billingRepository->create([
                'spa_business_id' => $business->id,
                'spa_branch_id' => $branch->id,
                'billing_type' => 'Membership',
                'billing_number' => $this->billingRepository->generateBillingNumber(),
                'subtotal' => $price,
                'amount' => $price,
                'status' => 'Pending',
                'issued_at' => now(),
                'remarks' => "{$program->name} — {$client->first_name} {$client->last_name}",
            ]);

            $payload = array_merge(Arr::only($payment, ['payment_method', 'reference_number', 'amount_tendered', 'remarks']), ['amount' => $price]);
            if ($rejection = $this->billingService->paymentRejection($billing, $business, $payload)) {
                // Rolls the bill back with the transaction.
                throw new \Illuminate\Http\Exceptions\HttpResponseException($rejection);
            }

            $tendered = isset($payload['amount_tendered']) ? round((float) $payload['amount_tendered'], 2) : null;
            $this->paymentRepository->create([
                'billing_id' => $billing->id,
                'spa_business_id' => $business->id,
                'spa_branch_id' => $branch->id,
                'payment_method' => $payload['payment_method'],
                'reference_number' => $payload['reference_number'] ?? null,
                'amount' => $price,
                'amount_tendered' => $tendered,
                'change_given' => $tendered !== null ? round($tendered - $price, 2) : null,
                'payment_status' => 'Paid',
                'paid_at' => now(),
                'received_by' => $user->id,
                'remarks' => $payload['remarks'] ?? null,
            ]);
            $this->billingService->settle($billing);
        }

        $starts = $from ?? now();
        $membership = ClientMembership::create([
            'client_id' => $client->id,
            'customer_program_id' => $program->id,
            'spa_branch_id' => $branch->id,
            'billing_id' => $billing?->id,
            'starts_at' => $starts,
            'ends_at' => $this->rewards->addPeriod($starts, $program->value('billing', 'Monthly')) ?? $starts->copy()->addMonth(),
            'status' => 'active',
            'price' => $price,
            'sold_by' => $user->id,
        ]);
        $this->rewards->issueMembershipVouchers($membership);
        $this->rewards->notifyClient($client, "Welcome to {$program->name}", 'Your membership runs until ' . $membership->ends_at->format('M j, Y') . '. Your member vouchers are in your wallet.', null);

        return response()->json(['data' => $this->membershipRow($membership->load(['client', 'program', 'branch'])) + [
            'billing_number' => $billing?->billing_number,
        ]], 201);
    }

    private function membershipRow(ClientMembership $m): array
    {
        return [
            'uuid' => $m->uuid,
            'status' => $m->status,
            'starts_at' => $m->starts_at?->toIso8601String(),
            'ends_at' => $m->ends_at?->toIso8601String(),
            'price' => (float) $m->price,
            'program' => ['uuid' => $m->program?->uuid, 'name' => $m->program?->name],
            'client' => ['uuid' => $m->client?->uuid, 'name' => trim(($m->client?->first_name ?? '') . ' ' . ($m->client?->last_name ?? ''))],
            'branch' => ['uuid' => $m->branch?->uuid, 'name' => $m->branch?->branch_name],
        ];
    }

    // ── A client's rewards ────────────────────────────────────────────────

    public function clientRewards(User $user, string $clientUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }
        $client = $this->client($user, $business, $clientUuid);
        $branchIds = $user->role === 'business_owner' ? null : $this->branchIds($user);

        return ['data' => $this->summaryFor($client, $branchIds ? $branchIds[0] : null)];
    }

    public function redeemForClient(User $user, string $clientUuid, string $programUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $business, $clientUuid, $programUuid) {
            $client = $this->client($user, $business, $clientUuid);
            $program = CustomerProgram::where('uuid', $programUuid)->where('spa_business_id', $business->id)->firstOrFail();
            $branchIds = $user->role === 'business_owner' ? [] : $this->branchIds($user);

            $result = $this->rewards->redeemPoints($client, $program, $user, $branchIds[0] ?? null);
            if (is_string($result)) {
                return $this->reject($result);
            }

            return ['data' => $this->summaryFor($client->fresh(), $branchIds[0] ?? null)];
        });
    }

    // What a client holds at one business: points and the loyalty rules,
    // their vouchers (in the wallet, and the ones they can earn next with
    // progress), discounts (members-only ones locked unless their membership
    // bundles them) and memberships. $branchId limits the catalogue to what
    // that branch offers; null = anything offered at any branch.
    public function summaryFor(Client $client, ?int $branchId = null): array
    {
        $business = $client->business;
        $enabled = $this->rewards->enabledFor($client->spa_business_id);

        $catalogue = $enabled
            ? CustomerProgram::with('items')
                ->where('spa_business_id', $client->spa_business_id)->where('is_active', true)
                ->whereHas('branches', fn ($q) => $branchId ? $q->where('spa_branches.id', $branchId) : $q)
                ->orderBy('created_at')->get()
            : collect();

        $membership = $client->activeMembership()->with('program.items')->first();
        $memberItemIds = $membership?->program?->items->pluck('id')->all() ?? [];
        $wallet = ClientVoucher::with('program')->where('client_id', $client->id)->where('status', 'available')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('expires_at')->get();
        $paidVisits = $this->rewards->paidVisits($client);

        $loyalty = $catalogue->firstWhere('type', 'loyalty');
        $expiring = $loyalty ? ClientPointEntry::where('client_id', $client->id)->where('type', 'earn')->where('remaining', '>', 0)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(30))
            ->selectRaw('COALESCE(SUM(remaining), 0) AS points, MIN(expires_at) AS first_at')->first() : null;

        return [
            'business' => [
                'uuid' => $business?->uuid,
                'name' => $business?->business_name,
                'logo_url' => $business?->business_logo ? \App\Services\ImageUploadService::url($business->business_logo) : null,
            ],
            'client_uuid' => $client->uuid,
            'enabled' => $enabled,
            'loyalty' => $loyalty ? [
                'program_uuid' => $loyalty->uuid,
                'name' => $loyalty->name,
                'points' => (int) $client->current_points,
                'lifetime_points' => (int) $client->lifetime_points,
                'points_per_peso' => (float) $loyalty->value('pointsPerPeso', 0),
                'point_value' => (float) $loyalty->value('pointValue', 0),
                'min_redeem' => (int) $loyalty->value('minRedeem', 0),
                'expiry' => $loyalty->value('expiryMonths', '12 months'),
                'expiring_points' => (int) ($expiring?->points ?? 0),
                'expiring_at' => $expiring?->first_at ? \Illuminate\Support\Carbon::parse($expiring->first_at)->toIso8601String() : null,
                'bonus' => $this->bonusServices($client->spa_business_id, $branchId),
            ] : null,
            'wallet' => $wallet->filter(fn ($v) => $v->program)->map(fn ($v) => [
                'uuid' => $v->uuid,
                'program_uuid' => $v->program->uuid,
                'name' => $v->program->name,
                'deal' => $this->dealText($v->program),
                'usable_on' => $v->program->value('usableOn', 'All services'),
                'source' => $v->source,
                'issued_at' => $v->issued_at?->toIso8601String(),
                'expires_at' => $v->expires_at?->toIso8601String(),
            ])->values(),
            'vouchers' => $catalogue->where('type', 'voucher')->map(fn ($p) => [
                'program_uuid' => $p->uuid,
                'name' => $p->name,
                'deal' => $this->dealText($p),
                'usable_on' => $p->value('usableOn', 'All services'),
                'valid_for' => $p->value('validDays', '3 months'),
                'earn' => ['kind' => $p->condition_kind, 'value' => $p->condition_value],
                'in_wallet' => $wallet->where('customer_program_id', $p->id)->count(),
                'progress' => $this->progress($p, $client, $paidVisits),
                'redeemable' => $p->condition_kind === 'points' && $loyalty
                    && $client->current_points >= max((int) $p->condition_value, (int) $loyalty->value('minRedeem', 0)),
            ])->values(),
            'discounts' => $catalogue->where('type', 'discount')->map(fn ($p) => [
                'program_uuid' => $p->uuid,
                'name' => $p->name,
                'label' => $this->discountText($p),
                'applies_to' => $p->value('appliesTo', 'All services'),
                'eligible' => $p->value('eligible', ''),
                'members_only' => $p->audience === 'members',
                'locked' => $p->audience === 'members' && ! in_array($p->id, $memberItemIds, true),
            ])->values(),
            'memberships' => $catalogue->where('type', 'membership')->map(fn ($p) => [
                'program_uuid' => $p->uuid,
                'name' => $p->name,
                'price' => (float) $p->value('price', 0),
                'billing' => $p->value('billing', 'Monthly'),
                'perks' => $p->value('perks', ''),
                'includes' => $p->items->map(fn ($i) => ['program_uuid' => $i->uuid, 'type' => $i->type, 'name' => $i->name])->values(),
                'joined' => $membership?->customer_program_id === $p->id,
                'ends_at' => $membership?->customer_program_id === $p->id ? $membership->ends_at->toIso8601String() : null,
            ])->values(),
        ];
    }

    private function progress(CustomerProgram $voucher, Client $client, int $paidVisits): ?float
    {
        $n = (int) $voucher->condition_value;

        return match ($voucher->condition_kind) {
            'visits' => $n > 0 ? round(($paidVisits % $n) / $n, 2) : null,
            'points' => $n > 0 ? round(min(1, $client->current_points / $n), 2) : null,
            default => null,
        };
    }

    // Services that earn bonus points on top of the per-₱ points (the
    // owner's per-option "Bonus points"), best first — at one branch when
    // given. One row per service with its highest option's bonus.
    private function bonusServices(int $businessId, ?int $branchId, int $limit = 6): array
    {
        return \App\Models\ServiceVariant::query()
            ->join('services', 'services.id', '=', 'service_variants.service_id')
            ->where('services.spa_business_id', $businessId)
            ->where('services.is_active', true)
            ->whereNull('services.deleted_at')
            ->where('service_variants.loyalty_points', '>', 0)
            ->when($branchId, fn ($q) => $q->whereHas('branchServices', fn ($b) => $b->where('spa_branch_id', $branchId)->where('is_available', true)))
            ->groupBy('services.id', 'services.name')
            ->selectRaw('services.name AS name, MAX(service_variants.loyalty_points) AS points')
            ->orderByDesc('points')
            ->orderBy('services.name')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'points' => (int) $r->points])
            ->all();
    }

    public function dealText(CustomerProgram $p): string
    {
        $deal = $p->value('dealType', '₱ off');
        if ($deal === 'Free service') {
            return 'Free ' . $p->value('usableOn', 'service');
        }
        $v = (float) $p->value('value', 0);

        return $deal === '% off' ? rtrim(rtrim(number_format($v, 2), '0'), '.') . '% off' : '₱' . number_format($v, $v == floor($v) ? 0 : 2) . ' off';
    }

    // One line describing a program, for lists (spa page, admin overview).
    public function summaryText(CustomerProgram $p): string
    {
        return match ($p->type) {
            'loyalty' => 'Earn ' . rtrim(rtrim(number_format((float) $p->value('pointsPerPeso', 0), 2), '0'), '.') . ' pt per ₱1',
            'voucher' => $this->dealText($p),
            'discount' => $this->discountText($p) . ($p->audience === 'members' ? ' · members' : ''),
            'membership' => '₱' . number_format((float) $p->value('price', 0)) . ' / ' . $p->value('billing', 'Monthly'),
            default => '',
        };
    }

    // What the platform admin sees on a business's page: is the module on,
    // does the plan allow it, what runs, and how much it's used. Read-only.
    public function adminOverview(SpaBusiness $business): array
    {
        $subscription = $this->subscriptionRepository->findActiveOrInGraceForBusiness($business->id, SystemSetting::current()->subscription_grace_period_days);
        $branchCount = $business->branches()->count();
        $clientIds = Client::where('spa_business_id', $business->id)->select('id');

        $programs = CustomerProgram::withCount('branches')
            ->where('spa_business_id', $business->id)
            ->orderByRaw("CASE type WHEN 'loyalty' THEN 0 WHEN 'voucher' THEN 1 WHEN 'discount' THEN 2 ELSE 3 END")
            ->orderBy('created_at')
            ->get();

        return [
            'enabled' => $this->rewards->switchedOn($business->id),
            'plan_allows' => (bool) $subscription?->plan?->reward_access,
            'plan_name' => $subscription?->plan?->name,
            'programs' => $programs->map(fn ($p) => [
                'uuid' => $p->uuid,
                'type' => $p->type,
                'name' => $p->name,
                'active' => (bool) $p->is_active,
                'summary' => $this->summaryText($p),
                'branches' => $p->branches_count,
                'all_branches' => $branchCount > 0 && $p->branches_count >= $branchCount,
            ])->values(),
            'stats' => [
                'clients_with_points' => Client::where('spa_business_id', $business->id)->where('current_points', '>', 0)->count(),
                'points_issued' => (int) ClientPointEntry::whereIn('client_id', $clientIds)->where('type', 'earn')->sum('points'),
                'points_outstanding' => (int) Client::where('spa_business_id', $business->id)->sum('current_points'),
                'active_members' => ClientMembership::whereIn('client_id', $clientIds)->where('status', 'active')->where('ends_at', '>', now())->count(),
                'membership_sales' => (float) Billing::where('spa_business_id', $business->id)->where('billing_type', 'Membership')->where('status', 'Paid')->sum('amount'),
                'vouchers_issued' => ClientVoucher::whereIn('client_id', $clientIds)->where('status', '!=', 'cancelled')->count(),
                'vouchers_used' => ClientVoucher::whereIn('client_id', $clientIds)->where('status', 'used')->count(),
            ],
        ];
    }

    public function discountText(CustomerProgram $p): string
    {
        $v = (float) $p->value('percent', 0);

        return $p->value('kind') === '₱' ? '₱' . number_format($v, $v == floor($v) ? 0 : 2) . ' off' : rtrim(rtrim(number_format($v, 2), '0'), '.') . '% off';
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function fill(CustomerProgram $program, array $data, SpaBusiness $business): void
    {
        $program->fill(array_filter([
            'name' => $data['name'] ?? ($program->exists ? null : ($program->type === 'loyalty' ? 'Loyalty Points' : ucfirst($program->type))),
            'is_active' => array_key_exists('active', $data) ? (bool) $data['active'] : ($program->exists ? null : true),
            'audience' => $data['audience'] ?? ($program->exists ? null : 'all'),
            'values' => array_key_exists('values', $data) ? $this->cleanValues($data['values']) : null,
        ], fn ($v) => $v !== null));

        if ($program->type === 'voucher' && array_key_exists('condition', $data)) {
            $program->condition_kind = $data['condition']['kind'] ?? null;
            $program->condition_value = in_array($program->condition_kind, ['min_spend', 'visits', 'points'], true)
                ? (int) ($data['condition']['value'] ?? 0) ?: null
                : null;
        }
        $program->save();

        if (array_key_exists('branch_uuids', $data)) {
            $ids = SpaBranch::whereIn('uuid', (array) $data['branch_uuids'])->where('spa_business_id', $business->id)->pluck('id')->all();
            $program->branches()->sync($ids);
        }

        if ($program->type === 'membership' && array_key_exists('includes', $data)) {
            $uuids = array_merge($data['includes']['voucher_uuids'] ?? [], $data['includes']['discount_uuids'] ?? []);
            // Only vouchers and members-only discounts belong in a bundle —
            // an every-client discount gives members nothing extra.
            $ids = CustomerProgram::whereIn('uuid', $uuids)->where('spa_business_id', $business->id)
                ->where(fn ($q) => $q->where('type', 'voucher')->orWhere(fn ($d) => $d->where('type', 'discount')->where('audience', 'members')))
                ->pluck('id')->all();
            $program->items()->sync($ids);
        }
    }

    private function cleanValues($values): array
    {
        return collect((array) $values)
            ->filter(fn ($v, $k) => is_string($k) && (is_scalar($v) || $v === null))
            ->map(fn ($v) => $v === null ? '' : (string) $v)
            ->all();
    }

    private function current(CustomerProgram $p): array
    {
        return [
            'values' => $p->values ?? [],
            'condition' => ['kind' => $p->condition_kind, 'value' => $p->condition_value],
        ];
    }

    // Per-type rules the web form also checks — enforced here so the client
    // app never gets a program it can't make sense of.
    private function problem(string $type, array $data): ?string
    {
        $v = $data['values'] ?? [];
        $num = fn ($key) => is_numeric($v[$key] ?? null) ? (float) $v[$key] : 0;

        return match ($type) {
            'loyalty' => array_key_exists('values', $data) && $num('pointsPerPeso') <= 0 ? 'Set how many points clients earn per ₱1.' : null,
            'voucher' => match (true) {
                ! in_array($data['condition']['kind'] ?? null, CustomerProgram::CONDITIONS, true) => 'Choose how clients earn this voucher.',
                in_array($data['condition']['kind'], ['min_spend', 'visits', 'points'], true) && (int) ($data['condition']['value'] ?? 0) < 1 => 'Enter the amount needed to earn this voucher.',
                ($v['dealType'] ?? '₱ off') === 'Free service' && blank($v['usableOn'] ?? null) => 'Say which service is free.',
                ($v['dealType'] ?? '₱ off') !== 'Free service' && $num('value') <= 0 => 'Enter the voucher amount.',
                ($v['dealType'] ?? '') === '% off' && $num('value') > 100 => 'A percent voucher can be at most 100%.',
                default => null,
            },
            'discount' => match (true) {
                $num('percent') <= 0 => 'Enter the discount amount.',
                ($v['kind'] ?? '%') === '%' && $num('percent') > 100 => 'A percent discount can be at most 100%.',
                default => null,
            },
            'membership' => $num('price') < 0 ? 'Enter a valid price.' : null,
            default => 'Unknown program type.',
        };
    }

    private function program(SpaBusiness $business, string $uuid, bool $lock = false): CustomerProgram
    {
        return CustomerProgram::where('uuid', $uuid)->where('spa_business_id', $business->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->firstOrFail();
    }

    private function client(User $user, SpaBusiness $business, string $uuid): Client
    {
        $branchIds = $user->role === 'business_owner' ? null : $this->branchIds($user);

        return $this->clientRepository->findByUuidForBusiness($uuid, $business->id, $branchIds);
    }

    private function membership(User $user, SpaBusiness $business, string $uuid): ClientMembership
    {
        return ClientMembership::with(['client', 'program', 'branch'])
            ->where('uuid', $uuid)
            ->whereHas('client', fn ($q) => $q->where('spa_business_id', $business->id))
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    private function reject(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }

    private function noBusiness(): JsonResponse
    {
        return $this->reject('No spa business found for this account.');
    }
}
