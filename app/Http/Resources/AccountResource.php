<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Wraps a User model directly (with accountBranch.branch + permission
// eager-loaded) — an account is a standalone Manager/Front Officer login,
// not a Staff row. See AccountRepository.
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
            'spa_branch_uuid' => $this->accountBranch?->branch?->uuid,
            'branch_name' => $this->accountBranch?->branch?->branch_name,
            'account_status' => $this->account_status,
            'created_at' => $this->created_at,

            'permission' => $this->permission !== null
                ? $this->permission->only(config('permission.' . $this->role))
                : null,
        ];
    }
}
