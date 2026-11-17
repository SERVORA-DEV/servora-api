<?php

namespace App\Http\Requests\Client;

use App\Rules\NoEmoji;
use Illuminate\Foundation\Http\FormRequest;

// Validates PUT /client/home-address: the client's home, picked on the map in the app (the
// address is what is at the pin, read from the map, not typed). Notes are the landmark or gate
// code the therapist needs.
class ClientHomeAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'home_address' => ['required', 'string', 'max:500', new NoEmoji()],
            'home_address_notes' => ['nullable', 'string', 'max:500', new NoEmoji()],
            'home_latitude' => 'required|numeric|between:-90,90',
            'home_longitude' => 'required|numeric|between:-180,180',
        ];
    }

    public function attributes(): array
    {
        return [
            'home_address' => 'home address',
            'home_address_notes' => 'notes',
            'home_latitude' => 'map pin',
            'home_longitude' => 'map pin',
        ];
    }

    public function messages(): array
    {
        return [
            'home_latitude.required' => 'Please pin your home on the map.',
            'home_longitude.required' => 'Please pin your home on the map.',
        ];
    }
}
