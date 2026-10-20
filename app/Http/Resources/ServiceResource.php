<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
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
            'category' => $this->category,
            // time | procedure | facility (App\Support\ServiceKind).
            'service_type' => \App\Support\ServiceKind::of($this->service_type),
            // A facility has to be chosen before the front desk may start it.
            'facility_required' => \App\Models\Service::hasFacilityRequirement()
                && \App\Support\ServiceKind::needsFacility($this->service_type, (bool) $this->facility_required),
            'description' => $this->description,

            // source_template_id is deliberately not exposed: it's provenance
            // for the admin's adoption counter (withCount('adoptions')), and
            // nothing on the owner's side reads it. Surfacing it would mean
            // eager-loading the template on every list just to print a uuid.
            'image_path' => $this->image_path,
            'image_url' => ImageUploadService::url($this->image_path),
            'is_active' => (bool) $this->is_active,
            // The owner's "can be done at a client's home" tick.
            'home_service_available' => (bool) $this->home_service_available,

            // Each bookable duration/price/points option — see
            // ServiceVariantResource. Always loaded by the repository
            // (unlike its nested `branches`, which stays optional).
            //
            // setRelation short-circuits ServiceVariantResource's
            // `$this->service->is_active` lookup to the Service instance
            // already in hand here, instead of one lazy-loaded query per
            // variant.
            'variants' => $this->whenLoaded('variants', function () {
                $this->variants->each(fn ($variant) => $variant->setRelation('service', $this->resource));
                return ServiceVariantResource::collection($this->variants);
            }),

            'created_at' => $this->created_at,
        ];
    }
}
