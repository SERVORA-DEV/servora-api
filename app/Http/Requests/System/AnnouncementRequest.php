<?php

namespace App\Http\Requests\System;

use App\Models\Announcement;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Creates an announcement (POST /system/announcements). Who may send is the
// route's notification_send permission; who may RECEIVE is decided server-side
// by AnnouncementAudience, so `targets` is only checked here for shape.
class AnnouncementRequest extends FormRequest
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
            'targets' => ['required_if:scope,selected', 'array', 'max:500'],
            'targets.*' => ['string', 'uuid'],
            'priority' => ['required', Rule::in(Announcement::PRIORITIES)],
            // notifications.title is varchar(150) — see the notifications table migration.
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            // One id per form opening: a repeated click or retry is the same announcement.
            'request_id' => ['required', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'message' => is_string($this->message) ? trim($this->message) : $this->message,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Administrators are one group: "selected" has no meaning for them.
                if ($this->input('audience') === 'admins' && $this->input('scope') === 'selected') {
                    $validator->errors()->add('scope', 'System administrators are sent to all at once.');
                }
                if ($this->input('scope') === 'selected' && count((array) $this->input('targets', [])) === 0) {
                    $validator->errors()->add('targets', 'Choose at least one recipient.');
                }
            },
        ];
    }
}
