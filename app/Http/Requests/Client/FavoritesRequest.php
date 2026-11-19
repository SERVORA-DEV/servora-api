<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// GET api/client/favorites (ClientAccountController::favorites).
class FavoritesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
