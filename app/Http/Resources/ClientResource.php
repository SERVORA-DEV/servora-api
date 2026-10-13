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
            // Linked to a Servora app account (Client::usesApp): the "App
            // user" label, and who earns loyalty points.
            'uses_app' => $this->resource->usesApp(),
            // What the spa recorded, or else what the client's own account
            // says (Client::genderValue / ageValue). Null when not known.
            'gender' => $this->resource->genderValue(),
            'age' => $this->resource->ageValue(),
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

            // Customer programs: the membership running now (null when none)
            // and how many usable vouchers are in the wallet — present when
            // the caller loaded them (ClientRepository search / find).
            'membership' => $this->when($this->relationLoaded('activeMembership'), fn () => $this->activeMembership ? [
                'uuid' => $this->activeMembership->uuid,
                'name' => $this->activeMembership->displayName(),
                'ends_at' => $this->activeMembership->ends_at?->toIso8601String(),
            ] : null),
            'vouchers_available' => $this->when(isset($this->vouchers_available_count), fn () => (int) $this->vouchers_available_count),
            // Bookings this client missed (the no-show policy counts them).
            'no_show_count' => $this->when(isset($this->no_show_count), fn () => (int) $this->no_show_count),

            // The client's preferred therapist: the one picked for them by
            // hand (Client::preferredTherapist), or else the one their
            // bookings ask for most (ClientPreferenceService). Omitted
            // entirely (not null) when there is none or it wasn't loaded,
            // same convention as `account` above. `_source` says which of
            // the two it is; `_requests` how often they asked, when it was
            // worked out from that.
            'preferred_therapist' => $this->when((bool) $this->preferredPick(), fn () => [
                'uuid' => $this->preferredPick()->uuid,
                'name' => trim("{$this->preferredPick()->first_name} {$this->preferredPick()->last_name}"),
            ]),
            'preferred_therapist_source' => $this->when((bool) $this->preferredPick(), fn () => $this->pickedByHand() ? 'manual' : 'automatic'),
            'preferred_therapist_requests' => $this->when(
                $this->preferredPick() && ! $this->pickedByHand(),
                fn () => (int) $this->preferredPick()->requests_from_client
            ),
        ];
    }

    private function pickedByHand(): ?\App\Models\Staff
    {
        return $this->preferred_staff_id && $this->relationLoaded('preferredTherapist') ? $this->preferredTherapist : null;
    }

    private function preferredPick(): ?\App\Models\Staff
    {
        return $this->pickedByHand() ?? ($this->relationLoaded('frequentTherapist') ? $this->frequentTherapist : null);
    }
}
