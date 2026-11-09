<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'package_uuid' => $this->package?->uuid,
            'package_name' => $this->resource->displayName(),
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'discount_amount' => (float) $this->discount_amount,
            'subtotal' => (float) $this->subtotal,
            'status' => $this->status,
            // A package for several clients (a Couples Massage): how many have it, who, and on the
            // other clients' bookings who paid ("covered by"). Null for an ordinary package.
            'shared' => \App\Support\SharedPackages::summary($this->resource),
        ];
    }
}
