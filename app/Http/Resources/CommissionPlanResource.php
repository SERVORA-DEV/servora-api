<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'rate' => (float) $this->rate,
            // How many staff are on it (only when the query counted them).
            'staff_count' => $this->whenCounted('staff'),
        ];
    }
}
