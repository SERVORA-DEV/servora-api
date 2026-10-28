<?php

namespace App\Service\System;

use App\Models\AccountBranch;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

// Who an announcement can reach, decided here and only here (never by the
// screen that asks): every audience is built from the same eligibility rules
// the platform already uses, and a "selected" list is checked against them, so
// an administrator cannot reach anyone outside an audience by sending ids.
//
//   admins      every other active system administrator
//   businesses  the owner of each verified business (not suspended) whose
//               owner account is active and which has a live subscription
//   branches    the owner and the branch managers of each verified branch of
//               a verified business
//   clients     every active client account (the mobile app's users)
//
// Recipients are people, not rows: someone who would be reached twice (the
// owner of two selected branches) is reached once.
class AnnouncementAudience
{
    /** Verified, not suspended, active owner, subscribed. */
    public function businesses(): Builder
    {
        return SpaBusiness::query()
            ->where('verification_status', 'Verified')
            ->whereHas('owner', fn ($q) => $q->where('account_status', 'Active'))
            ->whereHas('subscriptions', fn ($q) => $q
                ->where('status', 'Active')
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now())));
    }

    /** Verified branches of a verified business. */
    public function branches(): Builder
    {
        return SpaBranch::query()
            ->where('verification_status', 'Verified')
            ->whereHas('business', fn ($q) => $q->where('verification_status', 'Verified'));
    }

    public function clients(): Builder
    {
        return User::query()->where('role', 'client')->where('account_status', 'Active');
    }

    /**
     * The people an announcement reaches.
     *
     * @param  array<int, string>  $targets  uuids, when $scope is "selected"
     * @return Collection<int, User>
     *
     * @throws ValidationException when a selected recipient is not in the audience
     */
    public function recipients(User $actor, string $audience, string $scope, array $targets = []): Collection
    {
        $selected = $scope === 'selected';
        $targets = array_values(array_unique($targets));

        $users = match ($audience) {
            'admins' => User::query()
                ->where('role', 'system_administrator')
                ->where('account_status', 'Active')
                ->where('id', '!=', $actor->id)
                ->get(),
            'businesses' => $this->businessOwners($selected ? $targets : null),
            'branches' => $this->branchPeople($selected ? $targets : null),
            'clients' => $this->clientUsers($selected ? $targets : null),
            default => collect(),
        };

        return $users->unique('id')->values();
    }

    /** Pick-list for the screen: id (uuid), a label and a line to tell two apart. */
    public function options(string $audience, ?string $search, int $page, int $perPage = 20): LengthAwarePaginator
    {
        $like = $search ? '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($search))).'%' : null;

        return match ($audience) {
            'businesses' => $this->businesses()->with('owner:id,first_name,last_name')
                ->when($like, fn ($q) => $q->whereRaw('LOWER(business_name) LIKE ?', [$like]))
                ->orderBy('business_name')
                ->paginate($perPage, ['*'], 'page', $page)
                ->through(fn (SpaBusiness $b) => [
                    'id' => $b->uuid,
                    'label' => $b->business_name,
                    'sub' => $this->fullName($b->owner),
                ]),
            'branches' => $this->branches()->with('business:id,business_name')
                ->when($like, fn ($q) => $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(branch_name) LIKE ?', [$like])
                    ->orWhereHas('business', fn ($b) => $b->whereRaw('LOWER(business_name) LIKE ?', [$like]))))
                ->orderBy('branch_name')
                ->paginate($perPage, ['*'], 'page', $page)
                ->through(fn (SpaBranch $b) => [
                    'id' => $b->uuid,
                    'label' => $b->branch_name,
                    'sub' => $b->business?->business_name,
                ]),
            'clients' => $this->clients()
                ->when($like, fn ($q) => $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])))
                ->orderBy('first_name')->orderBy('last_name')
                ->paginate($perPage, ['*'], 'page', $page)
                ->through(fn (User $u) => [
                    'id' => $u->uuid,
                    // The name and a masked address: enough to tell two people apart,
                    // not the whole address of every client on the platform.
                    'label' => $this->fullName($u) ?: 'Client',
                    'sub' => $this->maskEmail($u->email),
                ]),
            default => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page),
        };
    }

    // ── Per audience ─────────────────────────────────────────────────────

    private function businessOwners(?array $uuids): Collection
    {
        $query = $this->businesses();
        if ($uuids !== null) {
            $this->assertAllEligible($uuids, (clone $query)->whereIn('uuid', $uuids)->pluck('uuid')->all());
            $query->whereIn('uuid', $uuids);
        }

        return User::query()->whereIn('id', $query->pluck('owner_id'))->where('account_status', 'Active')->get();
    }

    private function branchPeople(?array $uuids): Collection
    {
        $query = $this->branches();
        if ($uuids !== null) {
            $this->assertAllEligible($uuids, (clone $query)->whereIn('uuid', $uuids)->pluck('uuid')->all());
            $query->whereIn('uuid', $uuids);
        }

        $branches = $query->with('business:id,owner_id')->get(['id', 'spa_business_id']);

        $ids = $branches->map(fn (SpaBranch $b) => $b->business?->owner_id)->filter()->all();
        // The people who run a branch, when logins have been given branches.
        if ($branches->isNotEmpty() && AccountBranch::ready()) {
            $managers = AccountBranch::query()
                ->whereIn('spa_branch_id', $branches->pluck('id'))
                ->whereIn('level', AccountBranch::MANAGER_LEVELS)
                ->pluck('user_id')
                ->all();
            $ids = array_merge($ids, $managers);
        }

        return User::query()->whereIn('id', array_unique($ids))->where('account_status', 'Active')->get();
    }

    private function clientUsers(?array $uuids): Collection
    {
        $query = $this->clients();
        if ($uuids !== null) {
            $this->assertAllEligible($uuids, (clone $query)->whereIn('uuid', $uuids)->pluck('uuid')->all());
            $query->whereIn('uuid', $uuids);
        }

        return $query->get();
    }

    /** A selection must be real, eligible recipients of the chosen audience. */
    private function assertAllEligible(array $wanted, array $found): void
    {
        if (array_diff($wanted, $found)) {
            throw ValidationException::withMessages([
                'targets' => 'Some of the selected recipients cannot receive this announcement.',
            ]);
        }
    }

    private function fullName(?User $user): string
    {
        return trim(($user?->first_name ?? '').' '.($user?->last_name ?? ''));
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($name) - 1))).'@'.$domain;
    }
}
