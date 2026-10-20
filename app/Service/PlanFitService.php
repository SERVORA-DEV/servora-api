<?php

namespace App\Service;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\CustomerProgram;
use App\Models\Package;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repository\NotificationRepository;
use App\Support\AppCache;
use App\Support\PlanFit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fitting a business into the plan it is on. Moving to a smaller plan is not
// refused and nothing is deleted: the owner chooses which branches and staff
// logins to keep (App\Support\PlanFit holds the rules), and when the smaller
// plan's term starts the rest goes ON HOLD:
//
//   branch  switched off (operating_status Inactive — clients can't find or
//           book it, as with the owner's own switch) and marked plan_hold_at,
//           which also stops it being switched back on while there is no room
//   login   suspended (account_status Suspended — can't sign in) and marked
//           plan_hold_at; so is every login at a branch on hold
//
// Programs and packages need nothing here: they follow the plan's own
// reward_access / package_access flags.
//
// A bigger plan releases the holds again. The plan_hold_at columns come from
// a migration the owner of the database runs; until then ready() is false
// and callers fall back to the old "refuse the renewal" behaviour.
class PlanFitService
{
    // Still booked and not yet started: these stop a branch being put on hold.
    private const UPCOMING = [Appointment::STATUS_SCHEDULED];

    private const ACCOUNT_ROLES = ['manager', 'front_officer'];

    public function __construct(private NotificationRepository $notifications) {}

    /** Whether the hold columns exist yet (the migration has been run). */
    public function ready(): bool
    {
        if (Cache::get('schema:plan_hold') === true) {
            return true;
        }
        $ready = Schema::hasColumn('spa_branches', 'plan_hold_at')
            && Schema::hasColumn('users', 'plan_hold_at')
            && Schema::hasColumn('subscriptions', 'scheduled_keep');
        // "Not yet" is asked again soon, so running the migration is noticed.
        $ready ? Cache::forever('schema:plan_hold', true) : Cache::put('schema:plan_hold', false, 60);

        return $ready;
    }

    // ── What is in use ────────────────────────────────────────────────────

    /** Branches that are not on hold. */
    public function branches(SpaBusiness $business): Collection
    {
        return SpaBranch::where('spa_business_id', $business->id)
            ->when($this->ready(), fn ($q) => $q->whereNull('plan_hold_at'))
            ->orderBy('id')
            ->get();
    }

    /** Staff logins (manager / front desk) that are not on hold. */
    public function accounts(SpaBusiness $business): Collection
    {
        return $this->ofBusiness(User::whereIn('role', self::ACCOUNT_ROLES), $business)
            ->when($this->ready(), fn ($q) => $q->whereNull('plan_hold_at'))
            ->orderBy('id')
            ->get();
    }

    // Staff logins of a business: through the branches they were given
    // (App\Support\AccountScope), or their employee before that existed.
    private function ofBusiness($query, SpaBusiness $business)
    {
        return \App\Models\AccountBranch::ready()
            ? $query->whereHas('accountBranches.branch', fn ($q) => $q->where('spa_business_id', $business->id))->with('accountBranches.branch')
            : $query->whereHas('staff.branch', fn ($q) => $q->where('spa_business_id', $business->id))->with('staff');
    }

    /** @return int[] the branches a login works at */
    private function branchIdsOf(User $u): array
    {
        return \App\Models\AccountBranch::ready()
            ? $u->accountBranches->pluck('spa_branch_id')->all()
            : array_values(array_filter([$u->staff?->spa_branch_id]));
    }

    public function hasRoomForBranch(SpaBusiness $business, ?SubscriptionPlan $plan): bool
    {
        return ! $plan || PlanFit::hasRoom((int) $plan->max_branches, $this->branches($business)->count());
    }

    public function hasRoomForAccount(SpaBusiness $business, ?SubscriptionPlan $plan): bool
    {
        return ! $plan || PlanFit::hasRoom((int) $plan->max_user_accounts, $this->accounts($business)->count());
    }

    /** The plan the business is on right now, or null with no live subscription. */
    public function currentPlan(SpaBusiness $business): ?SubscriptionPlan
    {
        return AppCache::activeSubscription($business->id)?->plan;
    }

    // ── Fitting a plan ────────────────────────────────────────────────────

    /**
     * What moving to $plan on $effectiveAt means, for the owner to read and
     * choose from before confirming.
     */
    public function fit(SpaBusiness $business, SubscriptionPlan $plan, ?CarbonInterface $effectiveAt = null): array
    {
        $effectiveAt ??= now();
        [$branches, $accounts] = $this->inUse($business, $effectiveAt);
        $branchUuid = $branches->pluck('uuid', 'id');

        return [
            'needs_choice' => $this->ready() && PlanFit::needsChoice((int) $plan->max_branches, (int) $plan->max_user_accounts, $branches->all(), $accounts->all()),
            'effective_at' => $effectiveAt->toIso8601String(),
            'branches' => [
                'limit' => (int) $plan->max_branches,
                'items' => $branches->map(fn ($b) => ['uuid' => $b['uuid'], 'name' => $b['name'], 'upcoming' => $b['upcoming']])->values(),
            ],
            'accounts' => [
                'limit' => (int) $plan->max_user_accounts,
                'items' => $accounts->map(fn ($a) => [
                    'uuid' => $a['uuid'], 'name' => $a['name'], 'role' => $a['role'], 'branch_uuid' => $branchUuid[$a['branch_id']] ?? null,
                ])->values(),
            ],
            // What the plan itself switches off (nothing is deleted).
            'paused' => [
                'programs' => $plan->reward_access ? null : $this->programsAtStake($business),
                'packages' => $plan->package_access ? null : $this->packagesAtStake($business),
            ],
        ];
    }

    /**
     * Everything wrong with the owner's choice of what to keep, as sentences.
     * Empty when the business fits anyway or the choice is valid.
     *
     * @param  ?array{branch_uuids?: string[], account_uuids?: string[]}  $keep
     * @return string[]
     */
    public function problems(SpaBusiness $business, SubscriptionPlan $plan, ?array $keep, ?CarbonInterface $effectiveAt = null): array
    {
        if (! $this->ready()) {
            return [];
        }
        [$branches, $accounts] = $this->inUse($business, $effectiveAt ?? now());
        if (! PlanFit::needsChoice((int) $plan->max_branches, (int) $plan->max_user_accounts, $branches->all(), $accounts->all())) {
            return [];
        }
        if ($keep === null) {
            return ["Choose what to keep on the {$plan->name} plan before continuing."];
        }
        [$branchIds, $accountIds] = $this->ids($keep, $branches, $accounts);

        return PlanFit::problems((int) $plan->max_branches, (int) $plan->max_user_accounts, $branches->all(), $accounts->all(), $branchIds, $accountIds);
    }

    /**
     * The start of a term on $plan: put what doesn't fit on hold (with a
     * valid $keep), or release earlier holds when there is room again.
     * Returns the names put on hold, for the notification and the caller.
     *
     * @return array{branches: string[], accounts: string[]}
     */
    public function settle(SpaBusiness $business, SubscriptionPlan $plan, ?array $keep): array
    {
        $none = ['branches' => [], 'accounts' => []];
        if (! $this->ready()) {
            return $none;
        }

        [$branches, $accounts] = $this->inUse($business, now());
        if (! PlanFit::needsChoice((int) $plan->max_branches, (int) $plan->max_user_accounts, $branches->all(), $accounts->all())) {
            $this->release($business, $plan);

            return $none;
        }
        if ($keep === null) {
            // Callers check problems() before taking payment; reaching here
            // without a choice means nothing can be decided for the owner.
            return $none;
        }

        [$keepBranches, $keepAccounts] = $this->ids($keep, $branches, $accounts);
        $out = PlanFit::outcome($branches->all(), $accounts->all(), $keepBranches, $keepAccounts);

        return DB::transaction(function () use ($business, $plan, $branches, $accounts, $out) {
            $now = now();
            SpaBranch::whereIn('id', $out['hold_branches'])->update(['operating_status' => 'Inactive', 'plan_hold_at' => $now]);
            User::whereIn('id', $out['hold_accounts'])->update(['account_status' => 'Suspended', 'plan_hold_at' => $now]);
            // A suspended login is signed out everywhere, as when the owner suspends one.
            DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $out['hold_accounts'])->delete();

            $held = [
                'branches' => $branches->whereIn('id', $out['hold_branches'])->pluck('name')->values()->all(),
                'accounts' => $accounts->whereIn('id', $out['hold_accounts'])->pluck('name')->values()->all(),
            ];
            $this->tellOwner($business, $plan, $held);

            return $held;
        });
    }

    /** Lift every hold, when all of it fits $plan again. */
    public function release(SpaBusiness $business, SubscriptionPlan $plan): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $heldBranches = SpaBranch::where('spa_business_id', $business->id)->whereNotNull('plan_hold_at')->get();
        $heldAccounts = $this->heldAccounts($business);
        if ($heldBranches->isEmpty() && $heldAccounts->isEmpty()) {
            return false;
        }
        $fits = PlanFit::hasRoom((int) $plan->max_branches, $this->branches($business)->count(), $heldBranches->count())
            && PlanFit::hasRoom((int) $plan->max_user_accounts, $this->accounts($business)->count(), $heldAccounts->count());
        if (! $fits) {
            return false;
        }

        SpaBranch::whereIn('id', $heldBranches->pluck('id'))->update(['operating_status' => 'Active', 'plan_hold_at' => null]);
        User::whereIn('id', $heldAccounts->pluck('id'))->update(['account_status' => 'Active', 'plan_hold_at' => null]);
        if ($business->owner_id) {
            $this->notifications->create(
                $business->owner_id, 'Branches and Logins Are Back',
                "Your {$plan->name} plan has room for everything that was on hold, so it is all switched back on.", 'Subscription',
            );
        }

        return true;
    }

    /** Bring one branch back by hand. Returns why not, or null when done. */
    public function bringBackBranch(SpaBusiness $business, SpaBranch $branch): ?string
    {
        if (! $this->ready() || ! $branch->plan_hold_at) {
            return 'That branch is not on hold.';
        }
        $plan = $this->currentPlan($business);
        if (! $this->hasRoomForBranch($business, $plan)) {
            return 'Your ' . ($plan->name ?? 'current') . ' plan has no room for another branch. Upgrade to bring it back.';
        }
        $branch->update(['operating_status' => 'Active', 'plan_hold_at' => null]);

        return null;
    }

    /** Bring one staff login back by hand. Returns why not, or null when done. */
    public function bringBackAccount(SpaBusiness $business, User $account): ?string
    {
        if (! $this->ready() || ! $account->plan_hold_at) {
            return 'That login is not on hold.';
        }
        // Held only while every branch it works at is: bring one back first.
        $branches = \App\Models\AccountBranch::ready()
            ? $account->accountBranches()->with('branch')->get()->pluck('branch')->filter()
            : collect([$account->staff?->branch])->filter();
        if ($branches->isNotEmpty() && $branches->every(fn ($b) => $b->plan_hold_at)) {
            return $branches->count() === 1
                ? "{$branches->first()->branch_name} is on hold. Bring the branch back first."
                : 'Every branch this login works at is on hold. Bring one of them back first.';
        }
        $plan = $this->currentPlan($business);
        if (! $this->hasRoomForAccount($business, $plan)) {
            return 'Your ' . ($plan->name ?? 'current') . ' plan has no room for another staff login. Upgrade to bring it back.';
        }
        $account->update(['account_status' => 'Active', 'plan_hold_at' => null]);

        return null;
    }

    /** The owner's stored choice, as the uuids the pages work with. */
    public function presentKeep(SpaBusiness $business, ?array $keep): ?array
    {
        if (! $keep) {
            return null;
        }

        return [
            'branch_uuids' => array_values($keep['branch_uuids'] ?? []),
            'account_uuids' => array_values($keep['account_uuids'] ?? []),
        ];
    }

    // ── internals ────────────────────────────────────────────────────────

    // [branches, accounts] not on hold, as the plain rows PlanFit takes.
    private function inUse(SpaBusiness $business, CarbonInterface $effectiveAt): array
    {
        $branches = $this->branches($business);
        $upcoming = Appointment::whereIn('spa_branch_id', $branches->pluck('id'))
            ->whereIn('status', self::UPCOMING)
            ->whereDate('appointment_date', '>=', $effectiveAt->toDateString())
            ->selectRaw('spa_branch_id, COUNT(*) as total')
            ->groupBy('spa_branch_id')
            ->pluck('total', 'spa_branch_id');

        return [
            $branches->map(fn (SpaBranch $b) => [
                'id' => $b->id, 'uuid' => $b->uuid, 'name' => $b->branch_name, 'upcoming' => (int) ($upcoming[$b->id] ?? 0),
            ]),
            $this->accounts($business)->map(fn (User $u) => [
                'id' => $u->id, 'uuid' => $u->uuid,
                // A login with several branches is kept when any of them is.
                'branch_ids' => $ids = $this->branchIdsOf($u),
                'branch_id' => $ids[0] ?? null,
                'name' => $u->displayName(),
                'role' => $u->role,
            ]),
        ];
    }

    // The chosen uuids as ids. One that isn't the owner's becomes 0, which
    // PlanFit then refuses as "not one of yours".
    private function ids(array $keep, Collection $branches, Collection $accounts): array
    {
        $branchId = $branches->pluck('id', 'uuid');
        $accountId = $accounts->pluck('id', 'uuid');

        return [
            array_map(fn ($uuid) => (int) ($branchId[(string) $uuid] ?? 0), array_values($keep['branch_uuids'] ?? [])),
            array_map(fn ($uuid) => (int) ($accountId[(string) $uuid] ?? 0), array_values($keep['account_uuids'] ?? [])),
        ];
    }

    private function heldAccounts(SpaBusiness $business): Collection
    {
        return $this->ofBusiness(User::whereIn('role', self::ACCOUNT_ROLES), $business)
            ->whereNotNull('plan_hold_at')
            ->get();
    }

    private function programsAtStake(SpaBusiness $business): ?array
    {
        $programs = CustomerProgram::where('spa_business_id', $business->id)->count();
        if (! $programs) {
            return null;
        }
        $clients = Client::where('spa_business_id', $business->id);

        return [
            'count' => $programs,
            'clients_with_points' => (clone $clients)->where('current_points', '>', 0)->count(),
            'paid_memberships' => ClientMembership::whereIn('client_id', (clone $clients)->select('id'))
                ->where('status', 'active')->where('ends_at', '>', now())->count(),
        ];
    }

    private function packagesAtStake(SpaBusiness $business): ?array
    {
        $count = Package::where('spa_business_id', $business->id)->where('is_active', true)->count();

        return $count ? ['count' => $count] : null;
    }

    private function tellOwner(SpaBusiness $business, SubscriptionPlan $plan, array $held): void
    {
        $parts = [];
        if ($held['branches']) {
            $parts[] = implode(', ', $held['branches']) . (count($held['branches']) === 1 ? ' is' : ' are') . ' on hold';
        }
        if ($held['accounts']) {
            $n = count($held['accounts']);
            $parts[] = $n . ($n === 1 ? ' staff login is' : ' staff logins are') . ' suspended';
        }
        if (! $parts || ! $business->owner_id) {
            return;
        }
        $this->notifications->create(
            $business->owner_id, 'Your Plan Changed',
            "You are now on the {$plan->name} plan: " . implode(' and ', $parts) . '. Nothing was deleted. Upgrade to bring them back.',
            'Subscription',
        );
    }
}
