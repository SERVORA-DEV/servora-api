<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/account/photo, POST api/system/profile/photo (ProfilePhotoController::store).
class ProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['photo' => [
            'required', 'file', 'image',
            'mimes:'.implode(',', config('uploads.allowed_extensions')),
            'max:'.config('uploads.max_size_kb'),
        ]];
    }
}
