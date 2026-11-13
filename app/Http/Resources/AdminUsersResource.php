<?php

namespace App\Http\Resources;

use App\Support\AdminPermissions;
use App\Support\ProfilePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUsersResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_primary_admin' => $this->resource->isPrimaryAdmin(),
            'profile_photo' => ProfilePhoto::url($this->profile_photo),

            'permission' => $this->role === 'system_administrator'
                ? AdminPermissions::effective($this->resource)
                : $this->permission?->only(config('permission.' . $this->role)),
        ];
    }
}
