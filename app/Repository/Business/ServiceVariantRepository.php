<?php

namespace App\Repository\Business;

use App\Models\ServiceVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($serviceId, $variants) {
            $existing = ServiceVariant::where('service_id', $serviceId)->get()->keyBy('uuid');
            $submitted = collect($variants)->pluck('uuid')->filter()->all();
            ServiceVariant::where('service_id', $serviceId)->whereNotIn('uuid', $submitted)->delete();
            $result = collect();

            foreach ($variants as $row) {
                $attrs = [
                    'duration_minutes' => $row['duration_minutes'],
                    'price' => $row['price'],
                    'service_location' => $row['service_location'] ?? 'In Spa',
                ];

                if (! empty($row['uuid']) && $existing->has($row['uuid'])) {
                    $variant = $existing->get($row['uuid']);
                    $variant->update($attrs);
                } else {
                    $variant = ServiceVariant::create($attrs + ['service_id' => $serviceId]);
                }

                $result->push($variant);
            }

            return $result;
        });
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
