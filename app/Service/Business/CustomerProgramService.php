<?php

namespace App\Service\Business;

use App\Http\Resources\CustomerProgramResource;
use App\Models\Billing;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\ProgramRequest;
use App\Models\Service;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\Business\ClientRepository;
use App\Repository\BillingRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\ProgramChannel;
use App\Support\VoucherTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Customer programs as the business runs them:
//   - the owner creates/edits programs and picks the branches that offer them
//     (a manager switches programs on/off for their own branch);
//   - a manager can ask for a new program for their branch
//     (ProgramRequestService); an approved one is made here by store() and
//     carries only_branch_id — the branch it was made for — until the owner
//     offers it somewhere else;
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
        private ManagerChangeNotifier $managerChanges,
        private StaffNotifier $staffNotifier,
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
            // A program made for one branch (a manager asked for it) isn't
            // the other branches' to switch on, so they aren't shown it.
            ->when(! $isOwner && ProgramRequest::ready(), fn ($q) => $q->where(
                fn ($w) => $w->whereNull('only_branch_id')->orWhereIn('only_branch_id', $branchIds)
            ))
            ->orderBy('created_at')
            ->get();

        $subscription = \App\Support\AppCache::activeSubscription($business->id);

        return response()->json(['data' => [
            'enabled' => $this->rewards->switchedOn($business->id),
            // Whether the current plan includes customer programs (writes are
            // gated by plan.feature:reward_access).
            'plan_allows' => (bool) $subscription?->plan?->reward_access,
            // The same for packages (plan.feature:package_access). Sent here
            // because every business role already reads this on each page.
            'plan_packages' => (bool) $subscription?->plan?->package_access,
            'programs' => $programs->map(fn ($p) => (new CustomerProgramResource($p))->onlyBranches($branchIds)->resolve())->values(),
            // Per-service bonus points were removed (points come from the
            // amount paid only). Key kept so older clients don't break.
            'bonus_services' => [],
        ]]);
    }

    public function setEnabled(User $user, bool $enabled)
    {
        $this->settingsService->updateSection($user, 'programs', ['enabled' => $enabled]);

        return $this->index($user);
    }

    // $onlyFor: the program is being made for one branch (the owner approving
    // a manager's request). It is offered there and nowhere else.
    public function store(User $user, array $data, ?SpaBranch $onlyFor = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }
        if ($onlyFor) {
            $data['branch_uuids'] = [$onlyFor->uuid];
        }

        return DB::transaction(function () use ($business, $data, $onlyFor) {
            // Loyalty is one program for the whole business: saving it again
            // updates the existing one.
            $program = $data['type'] === 'loyalty'
                ? CustomerProgram::where('spa_business_id', $business->id)->where('type', 'loyalty')->first()
                : null;

            if ($problem = $this->problem($data['type'], $data) ?? $this->targetProblem($data['type'], $data, $business)) {
                return $this->reject($problem);
            }

            // A new program with no branches chosen is offered everywhere, so
            // it never starts out reaching nobody.
            if (! $program && ! array_key_exists('branch_uuids', $data)) {
                $data['branch_uuids'] = SpaBranch::where('spa_business_id', $business->id)->pluck('uuid')->all();
            }

            $program ??= new CustomerProgram(['spa_business_id' => $business->id, 'type' => $data['type']]);
            if ($onlyFor && $onlyFor->spa_business_id === $business->id) {
                $program->only_branch_id = $onlyFor->id;
            }
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

            $merged = array_merge($this->current($program), $data);
            if ($problem = $this->problem($program->type, $merged) ?? $this->targetProblem($program->type, $merged, $business)) {
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
        if ($offered && $program->only_branch_id && $program->only_branch_id !== $branch->id) {
            if ($user->role !== 'business_owner') {
                return $this->reject("\"{$program->name}\" was made for another branch. Ask the owner to offer it at {$branch->branch_name}.");
            }
            // The owner offering it somewhere else: it is no longer one
            // branch's own.
            $program->update(['only_branch_id' => null]);
        }
        $offered
            ? $program->branches()->syncWithoutDetaching([$branch->id])
            : $program->branches()->detach($branch->id);
        // A pivot change fires no model event: the spa page shows programs.
        \App\Support\AppCache::bumpBusiness($business->id);

        $this->managerChanges->tell(
            $user, $branch, ManagerChangeNotifier::LISTING, 'Branch Program Changed',
            "switched the program \"{$program->name}\" " . ($offered ? 'on' : 'off') . " for {$branch->branch_name}.",
        );

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
                return $this->reject("{$client->first_name} already has {$current->displayName()} until " . $current->ends_at->format('M j, Y') . ' — cancel it first.');
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
        $this->rewards->notifyClient($client, "Welcome to {$program->name}", 'Your membership is active until ' . $membership->ends_at->format('M j, Y') . ', and your member vouchers are now in your wallet.');

        // A payment was taken, so the branch's managers and the owner are told, as they are for a
        // visit's bill (not the person who took it). No reference: a click opens Billing & Payments.
        if ($billing) {
            $this->staffNotifier->branch(
                $branch,
                'Membership payment',
                '₱' . number_format($price, 2) . " received for {$program->name} from " . (trim("{$client->first_name} {$client->last_name}") ?: 'a client') . '.',
                type: 'Payment',
                roles: ['manager'],
                exceptUserId: $user->id,
            );
        }

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
            'program' => ['uuid' => $m->program?->uuid, 'name' => $m->displayName()],
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

        return ['data' => $this->summaryFor($client, $branchIds ? $branchIds[0] : null, ProgramChannel::DESK)];
    }

    // Just a client's usable vouchers, for the front desk's booking page (it
    // tags the services a free-service voucher covers) — without the
    // catalogue, progress and memberships that summaryFor works out.
    public function clientVouchers(User $user, string $clientUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }
        $client = $this->client($user, $business, $clientUuid);
        if (! $this->rewards->enabledFor($client->spa_business_id)) {
            return ['data' => []];
        }
        $wallet = ClientVoucher::with('program')->where('client_id', $client->id)->where('status', 'available')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('expires_at')->get()
            ->filter(fn ($v) => $this->rewards->voucherAllowedAt($v, ProgramChannel::DESK));

        // A first-visit voucher the client can still claim shows with the rest,
        // so the booking page can tag the service it makes free.
        $claimable = $this->claimableEntries(
            $client,
            CustomerProgram::where('spa_business_id', $client->spa_business_id)->where('is_active', true)->get(),
            ProgramChannel::DESK,
        );

        return ['data' => $this->walletEntries($wallet)->concat($claimable)->values()];
    }

    // The first-visit vouchers a client can claim now (no paid visit yet, not
    // claimed before), as wallet entries: `claimable` and the program's uuid
    // as the id. Claiming happens when they are used on a booking or a bill
    // (ProgramRewardService::claimFirstVisit).
    private function claimableEntries(Client $client, Collection $catalogue, string $where): Collection
    {
        if (! $this->rewards->enabledFor($client->spa_business_id)) {
            return collect();
        }

        return $catalogue->where('type', 'voucher')->where('condition_kind', 'first_visit')
            ->filter(fn ($p) => ProgramChannel::allows(ProgramChannel::of($p), $where) && $this->rewards->firstVisitClaimable($client, $p))
            ->map(fn ($p) => $this->dealEntry($p) + [
                'uuid' => $p->uuid,
                'source' => 'first_visit',
                'claimable' => true,
                'held' => false,
                'issued_at' => null,
                'expires_at' => null,
            ])->values();
    }

    // Each voucher as it was issued: its own copy of the name and deal
    // (ClientVoucher::dealProgram / App\Support\History). `deal_type` and
    // `usable` say, in data, what it does and which services it is for.
    private function walletEntries($wallet): Collection
    {
        // `held`: already on a booking the client made in the app, so it can't
        // go on another until that is billed or cancelled.
        $held = ClientVoucher::heldIds($wallet->pluck('id'));

        return $wallet->filter(fn ($v) => $v->dealProgram())->map(fn ($v) => $this->dealEntry($v->dealProgram()) + [
            'uuid' => $v->uuid,
            'source' => $v->source,
            'claimable' => false,
            'held' => in_array($v->id, $held, true),
            'issued_at' => $v->issued_at?->toIso8601String(),
            'expires_at' => $v->expires_at?->toIso8601String(),
        ])->values();
    }

    // What a voucher is, whether it is in the wallet or can still be claimed.
    private function dealEntry(CustomerProgram $deal): array
    {
        return [
            'program_uuid' => $deal->uuid,
            'name' => $deal->name,
            'deal' => $this->dealText($deal),
            'deal_type' => $deal->value('dealType', '₱ off'),
            // The number in "10% off" / "₱200 off" (0 for a free service).
            'deal_value' => (float) $deal->value('value', 0),
            'usable_on' => $deal->value('usableOn', 'All services'),
            'usable' => VoucherTarget::of($deal->values, 'usable'),
            'channel' => ProgramChannel::of($deal),
        ];
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

            $result = $this->rewards->redeemPoints($client, $program, $user, $branchIds[0] ?? null, ProgramChannel::DESK);
            if (is_string($result)) {
                return $this->reject($result);
            }

            return ['data' => $this->summaryFor($client->fresh(), $branchIds[0] ?? null, ProgramChannel::DESK)];
        });
    }

    // What a client holds at one business: points and the loyalty rules,
    // their vouchers (in the wallet, and the ones they can earn next with
    // progress), discounts (members-only ones locked unless their membership
    // bundles them) and memberships. $branchId limits the catalogue to what
    // that branch offers; null = anything offered at any branch.
    // $where is who is asking: the client app (default) or the front desk. A
    // program limited to the other one is left out (ProgramChannel); a
    // membership is still listed, only its perks follow its channel.
    public function summaryFor(Client $client, ?int $branchId = null, string $where = ProgramChannel::APP): array
    {
        $business = $client->business;
        $enabled = $this->rewards->enabledFor($client->spa_business_id);

        $catalogue = $enabled
            ? CustomerProgram::with('items')
                ->where('spa_business_id', $client->spa_business_id)->where('is_active', true)
                ->whereHas('branches', fn ($q) => $branchId ? $q->where('spa_branches.id', $branchId) : $q)
                ->orderBy('created_at')->get()
                ->filter(fn ($p) => $p->type === 'membership' || ProgramChannel::allows(ProgramChannel::of($p), $where))
                ->values()
            : collect();

        $membership = $client->activeMembership()->with('program.items')->first();
        $memberItemIds = $membership?->perkIds() ?? [];
        $wallet = ClientVoucher::with('program')->where('client_id', $client->id)->where('status', 'available')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('expires_at')->get()
            ->filter(fn ($v) => $this->rewards->voucherAllowedAt($v, $where))
            ->values();
        $visits = $this->rewards->paidVisitServices($client);
        // Points vouchers this client already claimed: a one-time one isn't offered again.
        $claimedWithPoints = ClientVoucher::where('client_id', $client->id)->where('source', 'points')
            ->where('status', '!=', 'cancelled')->pluck('customer_program_id')->unique()->all();

        $loyalty = $catalogue->firstWhere('type', 'loyalty');
        // Vouchers that can still be got with points this visit; null = no limit.
        $redeemLimit = $this->rewards->redeemLimit($loyalty);
        $earn = $loyalty ? $this->rewards->earnRule($loyalty) : ['points' => 0.0, 'per' => 1.0];
        $redeemedToday = $loyalty && $redeemLimit > 0 ? $this->rewards->redeemedToday($client) : 0;
        $redeemsLeft = $redeemLimit > 0 ? max(0, $redeemLimit - $redeemedToday) : null;
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
                'channel' => ProgramChannel::of($loyalty),
                'name' => $loyalty->name,
                'points' => (int) $client->current_points,
                'lifetime_points' => (int) $client->lifetime_points,
                // Points are for clients who use the app (LoyaltyEarning).
                'earns' => \App\Support\LoyaltyEarning::earns($client->usesApp()),
                // "1 point for every ₱10": the two numbers to show. The
                // rate per ₱1 stays for app builds that only read that.
                'earn_points' => $earn['points'],
                'earn_per_pesos' => $earn['per'],
                'points_per_peso' => round($earn['points'] / $earn['per'], 6),
                // No longer rules: points have no peso value of their own
                // and no minimum to redeem (each voucher has its own points
                // price). Kept at 0 for app builds that still read them.
                'point_value' => 0,
                'min_redeem' => 0,
                'redeem_per_visit' => $redeemLimit ?: null,
                'redeems_left' => $redeemsLeft,
                'expiry' => $loyalty->value('expiryMonths', '12 months'),
                'expiring_points' => (int) ($expiring?->points ?? 0),
                'expiring_at' => $expiring?->first_at ? \Illuminate\Support\Carbon::parse($expiring->first_at)->toIso8601String() : null,
                // Always empty now — see bonus_services above. The mobile
                // app hides its "bonus" list when this is empty.
                'bonus' => [],
            ] : null,
            // Each voucher as it was issued: its own copy of the name and
            // deal (ClientVoucher::dealProgram / App\Support\History).
            // Held vouchers first, then a first-visit voucher not yet claimed (it
            // is there for the client's first booking).
            'wallet' => $this->walletEntries($wallet)->concat($this->claimableEntries($client, $catalogue, $where))->values(),
            'vouchers' => $catalogue->where('type', 'voucher')
                ->reject(fn ($p) => $p->condition_kind === 'points' && ! ProgramRewardService::claimableAgain($p)
                    && in_array($p->id, $claimedWithPoints, true))
                ->map(fn ($p) => [
                'program_uuid' => $p->uuid,
                'name' => $p->name,
                'deal' => $this->dealText($p),
                'usable_on' => $p->value('usableOn', 'All services'),
                'valid_for' => $p->value('validDays', '3 months'),
                // `on`: only visits with these services count ("a Massage
                // service"); null when any visit does.
                'earn' => ['kind' => $p->condition_kind, 'value' => $p->condition_value, 'on' => $this->earnOn($p)],
                'in_wallet' => $wallet->where('customer_program_id', $p->id)->count(),
                'channel' => ProgramChannel::of($p),
                'claimable' => $p->condition_kind === 'first_visit' && $this->rewards->firstVisitClaimable($client, $p),
                'progress' => $this->progress($p, $client, $visits),
                'claim_again' => $p->condition_kind === 'points' && ProgramRewardService::claimableAgain($p),
                'redeemable' => $p->condition_kind === 'points' && $loyalty
                    && $client->current_points >= (int) $p->condition_value
                    && ($redeemsLeft === null || $redeemsLeft > 0),
            ])->values(),
            'discounts' => $catalogue->where('type', 'discount')->map(fn ($p) => [
                'program_uuid' => $p->uuid,
                'name' => $p->name,
                'label' => $this->discountText($p),
                'applies_to' => $p->value('appliesTo', 'All services'),
                'eligible' => $p->value('eligible', ''),
                'members_only' => $p->audience === 'members',
                'channel' => ProgramChannel::of($p),
                'locked' => $p->audience === 'members' && ! in_array($p->id, $memberItemIds, true),
            ])->values(),
            'memberships' => $catalogue->where('type', 'membership')->map(fn ($p) => [
                'program_uuid' => $p->uuid,
                'name' => $p->name,
                'price' => (float) $p->value('price', 0),
                'billing' => $p->value('billing', 'Monthly'),
                'perks' => $p->value('perks', ''),
                'channel' => ProgramChannel::of($p),
                'includes' => $p->items->map(fn ($i) => ['program_uuid' => $i->uuid, 'type' => $i->type, 'name' => $i->name])->values(),
                'joined' => $membership?->customer_program_id === $p->id,
                'ends_at' => $membership?->customer_program_id === $p->id ? $membership->ends_at->toIso8601String() : null,
            ])->values(),
        ];
    }

    private function progress(CustomerProgram $voucher, Client $client, array $visits): ?float
    {
        $n = (int) $voucher->condition_value;

        return match ($voucher->condition_kind) {
            'visits' => $n > 0 ? round((VoucherTarget::visits(VoucherTarget::of($voucher->values, 'earn'), $visits) % $n) / $n, 2) : null,
            'points' => $n > 0 ? round(min(1, $client->current_points / $n), 2) : null,
            default => null,
        };
    }

    public function dealText(CustomerProgram $p): string
    {
        $deal = $p->value('dealType', '₱ off');
        if ($deal === 'Free service') {
            $target = VoucherTarget::of($p->values, 'usable');

            // "Free Massage service"; chosen services (and older vouchers,
            // which name the service) read as written: "Free Swedish Massage".
            return 'Free ' . ($target['kind'] === VoucherTarget::CATEGORY ? VoucherTarget::freePhrase($target) : $p->value('usableOn', 'service'));
        }
        $v = (float) $p->value('value', 0);

        return $deal === '% off' ? rtrim(rtrim(number_format($v, 2), '0'), '.') . '% off' : '₱' . number_format($v, $v == floor($v) ? 0 : 2) . ' off';
    }

    // One line describing a program, for lists (spa page, admin overview).
    public function summaryText(CustomerProgram $p): string
    {
        return match ($p->type) {
            'loyalty' => $this->earnText($p),
            // "50% off a Massage service" when it is for certain services.
            'voucher' => $this->dealText($p) . ($p->value('dealType') !== 'Free service' && ! VoucherTarget::isAll(VoucherTarget::of($p->values, 'usable')) ? ' ' . $p->value('usableOn', '') : ''),
            'discount' => $this->discountText($p) . ($p->audience === 'members' ? ' · members' : ''),
            'membership' => '₱' . number_format((float) $p->value('price', 0)) . ' / ' . $p->value('billing', 'Monthly'),
            default => '',
        };
    }

    // What the platform admin sees on a business's page: is the module on,
    // does the plan allow it, what runs, and how much it's used. Read-only.
    public function adminOverview(SpaBusiness $business): array
    {
        $subscription = \App\Support\AppCache::activeSubscription($business->id);
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

    // The services a visit must include to count toward an "after N visits"
    // voucher, as words; null when any visit counts.
    private function earnOn(CustomerProgram $voucher): ?string
    {
        if ($voucher->condition_kind !== 'visits' || VoucherTarget::isAll(VoucherTarget::of($voucher->values, 'earn'))) {
            return null;
        }

        return $voucher->value('earnOn') ?: null;
    }

    public function discountText(CustomerProgram $p): string
    {
        $v = (float) $p->value('percent', 0);

        return $p->value('kind') === '₱' ? '₱' . number_format($v, $v == floor($v) ? 0 : 2) . ' off' : rtrim(rtrim(number_format($v, 2), '0'), '.') . '% off';
    }

    // "Earn 1 pt per ₱10".
    private function earnText(CustomerProgram $loyalty): string
    {
        $plain = fn (float $n) => rtrim(rtrim(number_format($n, 2), '0'), '.');
        $earn = $this->rewards->earnRule($loyalty);

        return 'Earn ' . $plain($earn['points']) . ' pt per ₱' . $plain($earn['per']);
    }

    // ── For ProgramRequestService: a manager's request is held to the same
    //    rules, and tidied the same way, as the owner's own save. ──────────

    /** What is wrong with a program as filled in, or null. */
    public function check(string $type, array $data, SpaBusiness $business): ?string
    {
        return $this->problem($type, $data) ?? $this->targetProblem($type, $data, $business);
    }

    /** A program's values as a save would store them. */
    public function normalise(string $type, $values, SpaBusiness $business): array
    {
        return $this->withTargets($type, $this->withEarnRate($type, $this->cleanValues($values)), $business);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function fill(CustomerProgram $program, array $data, SpaBusiness $business): void
    {
        $program->fill(array_filter([
            'name' => $data['name'] ?? ($program->exists ? null : ($program->type === 'loyalty' ? 'Loyalty Points' : ucfirst($program->type))),
            'is_active' => array_key_exists('active', $data) ? (bool) $data['active'] : ($program->exists ? null : true),
            'audience' => $data['audience'] ?? ($program->exists ? null : 'all'),
            'values' => array_key_exists('values', $data) ? $this->withTargets($program->type, $this->withEarnRate($program->type, $this->cleanValues($data['values'])), $business) : null,
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
            // Made for one branch, and now offered at another: it is no
            // longer that branch's own (see setBranch).
            if ($program->only_branch_id && array_diff($ids, [$program->only_branch_id])) {
                $program->update(['only_branch_id' => null]);
            }
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

    // Loyalty is set as "for every ₱10, 1 point". The rate per ₱1 that
    // older readers use is worked out from those two here, never typed.
    private function withEarnRate(string $type, array $values): array
    {
        $points = (float) ($values['earnPoints'] ?? 0);
        $per = (float) ($values['earnPerPesos'] ?? 0);
        if ($type === 'loyalty' && $points > 0 && $per > 0) {
            $values['pointsPerPeso'] = rtrim(rtrim(number_format($points / $per, 6, '.', ''), '0'), '.');
        }

        return $values;
    }

    // A voucher's two service targets (App\Support\VoucherTarget: what it
    // can be used on, and which visits count toward it), tidied on save. Only
    // what the kind uses is kept, and the readable form is written here so
    // the client app, the wallet and the front desk all show the same words.
    private function withTargets(string $type, array $values, SpaBusiness $business): array
    {
        if ($type !== 'voucher') {
            return $values;
        }

        foreach (['usable' => 'usableOn', 'earn' => 'earnOn'] as $prefix => $label) {
            $target = VoucherTarget::of($values, $prefix);
            $names = [];
            if ($target['kind'] === VoucherTarget::SERVICES) {
                $own = Service::where('spa_business_id', $business->id)->whereIn('uuid', $target['services'])->orderBy('name')->pluck('name', 'uuid');
                $target['services'] = $own->keys()->all();
                $names = $own->values()->all();
            }
            $values[$prefix . 'Kind'] = $target['kind'];
            $values[$prefix . 'Category'] = $target['category'];
            $values[$prefix . 'Services'] = implode(',', $target['services']);

            // An older "Free service" voucher names its service in usableOn
            // and is matched by that name; it stays as typed.
            $typedName = $prefix === 'usable' && VoucherTarget::isAll($target) && ($values['dealType'] ?? '') === 'Free service';
            if (! $typedName) {
                $values[$label] = VoucherTarget::phrase($target, $names);
            }
        }

        return $values;
    }

    // The part of a voucher's rules that needs the business's services: a
    // chosen category has to be one it offers, chosen services its own.
    private function targetProblem(string $type, array $data, SpaBusiness $business): ?string
    {
        if ($type !== 'voucher') {
            return null;
        }
        $values = $data['values'] ?? [];
        $checks = ['usable' => 'this voucher can be used on'];
        if (($data['condition']['kind'] ?? null) === 'visits') {
            $checks['earn'] = 'that count toward this voucher';
        }

        foreach ($checks as $prefix => $what) {
            $kind = (string) ($values[$prefix . 'Kind'] ?? VoucherTarget::ALL);
            if ($kind === VoucherTarget::CATEGORY) {
                $category = trim((string) ($values[$prefix . 'Category'] ?? ''));
                if (! in_array($category, config('service_categories'), true)) {
                    return "Choose the category of services {$what}.";
                }
                if (! Service::where('spa_business_id', $business->id)->where('category', $category)->exists()) {
                    return "You have no {$category} services yet.";
                }
            } elseif ($kind === VoucherTarget::SERVICES) {
                $uuids = VoucherTarget::uuids($values[$prefix . 'Services'] ?? '');
                if (! $uuids) {
                    return "Choose the services {$what}.";
                }
                if (Service::where('spa_business_id', $business->id)->whereIn('uuid', $uuids)->count() !== count($uuids)) {
                    return 'One of the chosen services is no longer in your service list.';
                }
            } elseif ($kind !== VoucherTarget::ALL) {
                return "Choose the services {$what}.";
            }
        }

        return null;
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
            'loyalty' => ! array_key_exists('values', $data) ? null : match (true) {
                filled($v['earnPerPesos'] ?? null) || filled($v['earnPoints'] ?? null)
                    ? ($num('earnPerPesos') <= 0 || $num('earnPoints') <= 0)
                    : $num('pointsPerPeso') <= 0 => 'Set how much a client spends to earn points, and how many points that earns.',
                filled($v['redeemPerVisit'] ?? null) && ($num('redeemPerVisit') < 1 || floor($num('redeemPerVisit')) != $num('redeemPerVisit')) => 'Redemptions per visit must be a whole number, 1 or more. Leave it empty for no limit.',
                default => null,
            },
            'voucher' => match (true) {
                ! in_array($data['condition']['kind'] ?? null, CustomerProgram::CONDITIONS, true) => 'Choose how clients earn this voucher.',
                in_array($data['condition']['kind'], ['min_spend', 'visits', 'points'], true) && (int) ($data['condition']['value'] ?? 0) < 1 => 'Enter the amount needed to earn this voucher.',
                // A free service is one from a category or from chosen
                // services (older vouchers name it in usableOn instead).
                ($v['dealType'] ?? '₱ off') === 'Free service' && ($v['usableKind'] ?? VoucherTarget::ALL) === VoucherTarget::ALL && blank($v['usableOn'] ?? null) => 'Choose which service is free.',
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
        return $this->spaBusinessRepository->branchIdsForUser($user);
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
