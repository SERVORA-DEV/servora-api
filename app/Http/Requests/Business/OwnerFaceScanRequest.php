<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class OwnerFaceScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * frames[] are the still captures taken by LivenessCapture.vue at fixed
     * points in the guided sequence; liveness_sequence[] is the whitelist of
     * step keys the frontend's useLivenessCapture composable can emit — kept
     * in sync with LivenessCapture.vue's deterministic step list.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'frames' => 'required|array|min:2|max:6',
            'frames.*' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.document_max_size_kb', 8192),

            'liveness_sequence' => 'required|array|min:1',
            'liveness_sequence.*' => 'required|string|in:center,look_left,look_right,blink,smile,move_closer',
        ];
    }
}
