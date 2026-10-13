<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Wraps a BranchPackage — uuid exposed is the Package's (what
// AppointmentService::addPackage expects as package_uuid), price is this
// branch's effective price (custom_price ?? the package's own default_price).
// Mirrors LookupServiceResource.
class LookupPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->package->uuid,
            'name' => $this->package->name,
            'description' => $this->package->description,
            // Its fixed duration: the total of what is in it (PackagePlan).
            'duration_minutes' => $this->package->totalMinutes(),
            'price' => (float) ($this->custom_price ?? $this->package->default_price),
            // What is in it, for the New Appointment page's menu: the names to
            // show, the services a therapist must be able to do, and whether
            // every one of them can be done at a client's home.
            'includes' => $this->package->packageServiceItems
                ->filter(fn ($item) => $item->theService() !== null)
                ->map(fn ($item) => ($item->quantity > 1 ? "{$item->quantity} × " : '') . $item->theService()->name
                    // …and the add-ons that come with that service.
                    . $this->package->includedAddons()->where('service_id', $item->theService()->id)->map(fn ($row) => " + {$row->addon->name}")->implode(''))
                ->values(),
            'service_uuids' => $this->package->packageServiceItems
                ->map(fn ($item) => $item->theService()?->uuid)
                ->filter()->unique()->values(),
            'home_service_available' => $this->package->packageServiceItems->isNotEmpty()
                && $this->package->packageServiceItems->every(fn ($item) => (bool) $item->theService()?->home_service_available),
        ];
    }
}
