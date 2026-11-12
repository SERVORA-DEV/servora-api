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
            // says (Client::genderShown / ageValue). Null when not known.
            'gender' => $this->resource->genderShown(),
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
                    // The client's own picture (users.profile_photo), unless this
                    // spa's staff took it down (Client::dismissed_photo).
                    'avatar' => \App\Support\ProfilePhoto::forSpa($this->user->profile_photo, \App\Models\Client::hasPhotoDismissal() ? $this->dismissed_photo : null),
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
            // The list's visit figures (ClientRepository::search): finished
            // visits, the last one and what they came to, and the next booked.
            'visits_count' => $this->when(isset($this->visits_count), fn () => (int) $this->visits_count),
            'last_visit_date' => $this->when(array_key_exists('last_visit_date', $this->resource->getAttributes()), fn () => $this->last_visit_date ? substr((string) $this->last_visit_date, 0, 10) : null),
            'visits_total' => $this->when(array_key_exists('visits_total', $this->resource->getAttributes()), fn () => (float) ($this->visits_total ?? 0)),
            'next_visit_date' => $this->when(array_key_exists('next_visit_date', $this->resource->getAttributes()), fn () => $this->next_visit_date ? substr((string) $this->next_visit_date, 0, 10) : null),
            // The list's branch: where they last came, else where they were added (ClientService::search).
            'branch' => $this->when(array_key_exists('list_branch', $this->resource->getAttributes()), fn () => $this->list_branch),
        ];
    }
}
