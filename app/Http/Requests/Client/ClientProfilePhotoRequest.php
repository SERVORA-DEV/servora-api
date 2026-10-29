<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// Validates POST /client/profile/photo: the picture a client sets from the app.
class ClientProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', config('uploads.allowed_extensions')),
                'max:'.config('uploads.max_size_kb'),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['photo' => 'picture'];
    }
}
