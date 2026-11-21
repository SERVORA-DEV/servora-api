<?php

namespace App\Http\Requests\Client;

use App\Rules\NoEmoji;
use Illuminate\Foundation\Http\FormRequest;

// Validates PUT /client/home-address: the client's home, picked on the map in the app (the
// address is what is at the pin, read from the map, not typed). Notes are the landmark (required)
// and anything else the therapist needs to find the house; every home-service booking starts with them.
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
            'home_address_notes' => ['required', 'string', 'min:3', 'max:500', new NoEmoji()],
            'home_latitude' => 'required|numeric|between:-90,90',
            'home_longitude' => 'required|numeric|between:-180,180',
        ];
    }

    public function attributes(): array
    {
        return [
            'home_address' => 'home address',
            'home_address_notes' => 'landmark',
            'home_latitude' => 'map pin',
            'home_longitude' => 'map pin',
        ];
    }

    public function messages(): array
    {
        return [
            'home_latitude.required' => 'Please pin your home on the map.',
            'home_longitude.required' => 'Please pin your home on the map.',
            'home_address_notes.required' => 'Please add a landmark so the therapist can find your place.',
            'home_address_notes.min' => 'Please add a landmark so the therapist can find your place.',
        ];
    }
}
