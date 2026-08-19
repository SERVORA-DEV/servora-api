<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class OwnerVerificationRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 'field' is only meaningful on the identity-reject endpoint (a UX hint
     * for which sub-item to highlight) — silently ignored by the
     * business-reject endpoint, which has no equivalent sub-item.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
            'field' => 'nullable|string|in:id_document,face_scan,both',
        ];
    }
}
