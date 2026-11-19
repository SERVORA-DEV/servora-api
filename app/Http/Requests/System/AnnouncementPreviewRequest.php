<?php

namespace App\Http\Requests\System;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// POST api/system/announcements/preview (AnnouncementController::preview).
class AnnouncementPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'scope' => ['required', Rule::in(Announcement::SCOPES)],
            'targets' => ['nullable', 'array', 'max:500'],
            'targets.*' => ['string', 'uuid'],
        ];
    }
}
