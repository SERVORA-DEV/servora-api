<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim("{$this->first_name} {$this->last_name}"),
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'current_points' => (int) $this->current_points,
            'lifetime_points' => (int) $this->lifetime_points,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at,

            // The fuller profile (middle name, suffix, gender, birth date,
            // avatar) isn't stored on clients — it lives on the linked
            // Servora account and is only ever present here when this
            // client has one AND the caller eager-loaded it (see
            // ClientRepository::search/findByUuidForBusiness). Omitted
            // entirely (not null) for a walk-in with no account.
            'account' => $this->when(
                $this->user_id && $this->relationLoaded('user') && $this->user,
                fn () => [
                    'middle_name' => $this->user->middle_name,
                    'suffix' => $this->user->suffix,
                    'gender' => $this->user->gender,
                    'birth_date' => optional($this->user->birth_date)->format('Y-m-d'),
                    'avatar' => $this->user->avatar,
                ]
            ),

            // The standing preferred-therapist relationship (see
            // Client::preferredTherapist) — omitted entirely (not null) when
            // unset or not eager-loaded, same convention as `account` above.
            'preferred_therapist' => $this->when(
                $this->preferred_staff_id && $this->relationLoaded('preferredTherapist') && $this->preferredTherapist,
                fn () => [
                    'uuid' => $this->preferredTherapist->uuid,
                    'name' => trim("{$this->preferredTherapist->first_name} {$this->preferredTherapist->last_name}"),
                ]
            ),
        ];
    }
}
