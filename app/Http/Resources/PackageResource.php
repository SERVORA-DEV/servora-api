<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            // Its fixed duration: the total of what is in it (PackagePlan).
            'duration_minutes' => $this->resource->totalMinutes() ?: null,
            'default_price' => (float) $this->default_price,
            'is_active' => (bool) $this->is_active,
            // False until the migration has run: minutes then snap to the
            // nearest length on the menu and add-ons aren't kept.
            'by_service' => \App\Models\Package::byService(),

            // What is in it: each service with the minutes it runs in this
            // package and the add-ons that come with it. unit_price is the
            // menu's price for the nearest length — a guide only, as is
            // original_price ("separately these cost about…"): the package
            // is charged at its own price.
            'services' => $this->whenLoaded('packageServiceItems', fn () => $this->packageServiceItems
                ->filter(fn ($item) => $item->theService() !== null)
                ->values()
                ->map(fn ($item) => [
                    'service_uuid' => $item->theService()->uuid,
                    'name' => $item->theService()->name,
                    'category' => $item->theService()->category,
                    'service_type' => \App\Support\ServiceKind::of($item->theService()->service_type),
                    'duration_minutes' => $item->minutes(),
                    'quantity' => (int) $item->quantity,
                    // What one is worth in this package: the owner's price, else the menu's.
                    'unit_price' => $item->unitPrice(),
                    // True when the owner set it (false: it is the menu price).
                    'price_set' => \App\Models\Package::hasLinePrice() && $item->price !== null,
                    'addons' => $this->resource->includedAddons()
                        ->where('service_id', $item->theService()->id)
                        ->values()
                        ->map(fn ($row) => [
                            'uuid' => $row->addon->uuid,
                            'name' => $row->addon->name,
                            'price' => (float) $row->addon->price,
                            'duration_minutes' => (int) $row->addon->duration_minutes,
                        ]),
                ])),
            'original_price' => $this->whenLoaded('packageServiceItems', fn () => \App\Support\PackagePlan::estimate(
                $this->packageServiceItems
                    ->filter(fn ($item) => $item->theService() !== null)
                    ->map(fn ($item) => ['price' => $item->unitPrice(), 'quantity' => $item->quantity])
                    ->all(),
                $this->resource->includedAddons()->map(fn ($row) => $row->addon->price)->all(),
            )),

            // Per-branch availability/price override — see
            // ServiceResource::toArray's identical field for the scoping
            // rationale.
            'branches' => $this->whenLoaded('branchPackages', fn () => $this->branchPackages->map(fn ($bp) => [
                'branch_uuid' => $bp->branch?->uuid,
                'branch_name' => $bp->branch?->branch_name,
                'is_available' => (bool) $bp->is_available,
                'custom_price' => $bp->custom_price !== null ? (float) $bp->custom_price : null,
            ])),

            'created_at' => $this->created_at,
        ];
    }
}
