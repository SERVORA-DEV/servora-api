<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// Validates the query string of the public GET /spas/{uuid}/therapists/free-times
// lookup: the client booking flow's staff step, which comes before the date,
// asks when each therapist is next free for the visit it is building.
// Unauthenticated like the rest of the /spas/* lookups.
class PublicTherapistFreeTimesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The first day to look at (today when left out), and how many days on.
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'days' => 'nullable|integer|min:1|max:14',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            // What is being booked, so only the therapists who can perform it are listed.
            'service_variant_uuids' => 'nullable|array|max:20',
            'service_variant_uuids.*' => 'uuid',
            'package_uuid' => 'nullable|uuid',
            // A home service visit: only the staff who take home service are offered.
            'home_service' => 'nullable|boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'from' => 'start date',
            'duration_minutes' => 'duration',
        ];
    }
}
