<?php

namespace App\Http\Requests\System;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// GET api/system/announcements (AnnouncementController::index).
class AnnouncementListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'audience' => ['nullable', Rule::in(Announcement::AUDIENCES)],
            'priority' => ['nullable', Rule::in(Announcement::PRIORITIES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
