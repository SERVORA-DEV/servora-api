<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Wraps a User model directly (with staff.branch + permission
// eager-loaded) — an account is a standalone Manager/Front Officer login
// tied to the Staff row it was created for. See AccountRepository.
class AccountResource extends JsonResource
{
    // users.role stores the lowercase/underscored form (also the
    // config('permission.*') key) — the frontend works in these Title Case
    // labels instead (see servora-web's useOwnerAccounts.ts).
    private const ROLE_LABELS = [
        'manager' => 'Manager',
        'front_officer' => 'Front Officer',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'username' => $this->username,
            'email' => $this->email,
            'role' => self::ROLE_LABELS[$this->role] ?? $this->role,
            // The account's own name, and where it works at which level.
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->resource->displayName(),
            'branches' => $this->branchList(),
            // The first branch (what screens that show one read).
            'spa_branch_uuid' => $this->branchList()[0]['uuid'] ?? null,
            'branch_name' => $this->branchList()[0]['name'] ?? null,
            // Before logins were given branches, the employee it was made from.
            'staff_uuid' => \App\Models\AccountBranch::ready() ? null : $this->staff?->uuid,
            'staff_name' => $this->resource->displayName(),
            'account_status' => $this->account_status,
            // Suspended by a smaller plan (PlanFitService), not by the owner.
            'on_hold' => (bool) $this->plan_hold_at,
            'created_at' => $this->created_at,

            'permission' => $this->permission !== null
                ? $this->permission->only(config('permission.' . $this->role))
                : null,
        ];
    }

    private ?array $branches = null;

    private function branchList(): array
    {
        if ($this->branches !== null) {
            return $this->branches;
        }
        if (\App\Models\AccountBranch::ready()) {
            return $this->branches = $this->resource->accountBranches
                ->filter(fn ($r) => $r->branch)
                ->sortBy(fn ($r) => $r->branch->branch_name)
                ->map(fn ($r) => ['uuid' => $r->branch->uuid, 'name' => $r->branch->branch_name, 'level' => $r->level])
                ->values()->all();
        }
        $branch = $this->staff?->branch;

        return $this->branches = $branch ? [['uuid' => $branch->uuid, 'name' => $branch->branch_name, 'level' => $this->role === 'manager' ? 'management' : 'operation']] : [];
    }
}
