<?php

namespace App\Repository\Business;

use App\Models\ServiceVariant;
use Illuminate\Support\Collection;

class ServiceVariantRepository
{
    // Update-or-create-and-soft-delete-missing — never delete+recreate,
    // since service_variants.id is FK'd from branch_services and
    // package_services (cascadeOnDelete). A row with no uuid is always
    // treated as a new variant, even if its duration_minutes matches a
    // soft-deleted sibling — "does this address an existing row" is
    // payload-driven only, never inferred from duration.
    public function syncForService(int $serviceId, array $variants): Collection
    {
        $existing = ServiceVariant::where('service_id', $serviceId)->get()->keyBy('uuid');
        $kept = [];
        $result = collect();

        foreach ($variants as $row) {
            $attrs = [
                'duration_minutes' => $row['duration_minutes'],
                'price' => $row['price'],
                'commission_amount' => $row['commission_amount'] ?? null,
                'loyalty_points' => $row['loyalty_points'] ?? null,
            ];

            if (! empty($row['uuid']) && $existing->has($row['uuid'])) {
                $variant = $existing->get($row['uuid']);
                $variant->update($attrs);
            } else {
                $variant = ServiceVariant::create($attrs + ['service_id' => $serviceId]);
            }

            $kept[] = $variant->uuid;
            $result->push($variant);
        }

        // Soft-delete anything this payload dropped. Its branch_services/
        // package_services rows are left in place (soft delete, not a
        // cascading hard delete) — same "orphaned but not destroyed"
        // convention appointment_services/rewards already accept for their
        // own loose service_variant_id references.
        ServiceVariant::where('service_id', $serviceId)->whereNotIn('uuid', $kept)->delete();

        return $result;
    }

    public function findByUuidForBusiness(string $uuid, int $spaBusinessId, ?array $branchIds = null)
    {
        // Eager-loaded (not just filtered by whereHas) since
        // ServiceVariantResource now reads is_active off this relation.
        $query = ServiceVariant::with('service')
            ->where('uuid', $uuid)
            ->whereHas('service', fn ($q) => $q->where('spa_business_id', $spaBusinessId));

        if ($branchIds !== null) {
            $query->with(['branchServices' => fn ($q) => $q->whereIn('spa_branch_id', $branchIds)->with('branch')]);
        }

        return $query->firstOrFail();
    }
}
