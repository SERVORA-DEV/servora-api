<?php

namespace App\Http\Resources\System;

use App\Http\Resources\ServiceVariantResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// An admin-authored service template — the same Service model the business
// catalog uses, so the variant shape businesses already consume carries over
// unchanged and an adopted copy needs no translation.
class ServiceTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'category' => $this->category,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,

            // The suggested duration/price options an owner starts from.
            // setRelation short-circuits ServiceVariantResource's
            // `$this->service->is_active` lookup to the template already in
            // hand, instead of one lazy-loaded query per variant — same trick
            // ServiceResource uses.
            'variants' => $this->whenLoaded('variants', function () {
                $this->variants->each(fn ($variant) => $variant->setRelation('service', $this->resource));
                return ServiceVariantResource::collection($this->variants);
            }),

            // How many business services were started from this template.
            // Only loaded on the admin listing (see
            // ServiceTemplateRepository::paginate) — the owner-facing catalog
            // omits it, so whenCounted keeps it out of that response.
            'adoptions_count' => $this->whenCounted('adoptions'),

            'created_at' => $this->created_at,
        ];
    }
}
