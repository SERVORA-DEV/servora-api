<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// One customer program in the shape the web Programs page edits:
// `values` keeps the form's own keys; `condition` for vouchers; `includes`
// for memberships; `branch_uuids` = where it's offered (a manager/front desk
// only sees their own branch in it — see onlyBranches()).
class CustomerProgramResource extends JsonResource
{
    private ?array $visibleBranchIds = null;

    public function onlyBranches(?array $branchIds): static
    {
        $this->visibleBranchIds = $branchIds;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $branches = $this->branches;
        if ($this->visibleBranchIds !== null) {
            $branches = $branches->whereIn('id', $this->visibleBranchIds);
        }
        $items = $this->type === 'membership' ? $this->items : collect();

        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'name' => $this->name,
            'active' => (bool) $this->is_active,
            'audience' => $this->audience,
            'values' => (object) ($this->values ?? []),
            'condition' => $this->type === 'voucher' ? [
                'kind' => $this->condition_kind,
                'value' => $this->condition_value,
            ] : null,
            'includes' => $this->type === 'membership' ? [
                'voucher_uuids' => $items->where('type', 'voucher')->pluck('uuid')->values(),
                'discount_uuids' => $items->where('type', 'discount')->pluck('uuid')->values(),
            ] : null,
            'branch_uuids' => $branches->pluck('uuid')->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
