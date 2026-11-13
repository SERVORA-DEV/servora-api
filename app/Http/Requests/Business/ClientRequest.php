<?php

namespace App\Http\Requests\Business;

use App\Rules\ValidEmail;
use App\Rules\PersonName;
use App\Models\Client;
use App\Support\ClientProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\NoEmoji;
use App\Rules\PhMobile;
use App\Support\Gender;

// Validates POST/PATCH /business/client (register/update a client). Also
// reused as the shape of the nested "client" object inside AppointmentRequest
// when creating an appointment for a walk-in that doesn't exist yet.
// Deliberately minimal — clients only stores simple operational attributes;
// an account holder's fuller profile lives on `users` instead (see
// ClientResource's `account` block), so there's nothing to collect here
// beyond how to identify/contact them — and, since they are the spa's own to
// know, the client's gender (asked of every new client) and their age
// (optional) — App\Support\ClientProfile.
class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'first_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'last_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'phone_number' => ['nullable', 'string', 'max:20', new NoEmoji(), new PhMobile()],
            'email' => ['nullable', 'email', new ValidEmail(), 'max:255', new NoEmoji()],
            'notes' => 'nullable|string',
            'gender' => [$isCreate && Client::hasProfile() ? 'required' : 'nullable', Rule::in(ClientProfile::GENDERS)],
            'age' => 'nullable|integer|min:0|max:' . ClientProfile::OLDEST,
        ];
    }

    // "Prefer not to say" is kept as 'Other' (App\Support\Gender: clients.gender is too short for it).
    protected function prepareForValidation(): void
    {
        if ($this->filled('gender')) {
            $this->merge(['gender' => Gender::forShortColumn($this->input('gender'))]);
        }
    }

    public function messages(): array
    {
        return [
            'gender.required' => 'Choose the client\'s gender.',
            'gender.in' => 'Choose Male or Female.',
        ];
    }
}
