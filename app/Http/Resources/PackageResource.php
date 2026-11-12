<?php

namespace App\Http\Resources;

use App\Models\Package;
use App\Support\PackagePlan;
use App\Support\ServiceKind;
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
            'home_service_available' => (bool) $this->home_service_available,
            'home_price' => $this->home_price !== null ? (float) $this->home_price : null,
            'home_duration_minutes' => $this->resource->totalMinutes(true),
            'default_price' => (float) $this->default_price,
            'is_active' => (bool) $this->is_active,
            // False until the migration has run: minutes then snap to the
            // nearest length on the menu and add-ons aren't kept.
<<<<<<< HEAD
<<<<<<< HEAD
            'by_service' => Package::byService(),
=======
            'by_service' => \App\Models\Package::byService(),
            // How many clients one booking of it is for (a Couples Massage: 2).
            'client_count' => $this->resource->clients(),
>>>>>>> main
=======
            'by_service' => Package::byService(),
            // How many clients one booking of it is for (a Couples Massage: 2).
            'client_count' => $this->resource->clients(),
>>>>>>> main

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
                    'service_type' => ServiceKind::of($item->theService()->service_type),
                    'duration_minutes' => $item->minutes(),
                    'home_duration_minutes' => $item->home_duration_minutes,
                    'home_price' => $item->home_price !== null ? (float) $item->home_price : null,
                    'quantity' => (int) $item->quantity,
                    // What one is worth in this package: the owner's price, else the menu's.
                    'unit_price' => $item->unitPrice(),
                    // True when the owner set it (false: it is the menu price).
<<<<<<< HEAD
<<<<<<< HEAD
                    'price_set' => Package::hasLinePrice() && $item->price !== null,
=======
                    'price_set' => \App\Models\Package::hasLinePrice() && $item->price !== null,
                    // A package for several clients: which of them get it (null: every client).
                    'client_slots' => \App\Models\Package::hasSharing() ? $item->client_slots : null,
>>>>>>> main
=======
                    'price_set' => Package::hasLinePrice() && $item->price !== null,
                    // A package for several clients: which of them get it (null: every client).
                    'client_slots' => Package::hasSharing() ? $item->client_slots : null,
>>>>>>> main
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
            'original_price' => $this->whenLoaded('packageServiceItems', fn () => PackagePlan::estimate(
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
                'home_custom_price' => $bp->home_custom_price !== null ? (float) $bp->home_custom_price : null,
                'custom_price' => $bp->custom_price !== null ? (float) $bp->custom_price : null,
            ])),

            'created_at' => $this->created_at,
        ];
    }
}
