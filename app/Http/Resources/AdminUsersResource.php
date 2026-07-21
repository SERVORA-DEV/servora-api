<?php

namespace App\Http\Resources;

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

            'permission' => $this
            ->permission !== null ?
            $this->permission->only(config('permission.' . $this->role)) : 
            null
        ];
    }
}