<?php

namespace App\Http\Requests\Business\BranchSettings;

use App\Models\SpaBranchPhoto;
use Illuminate\Foundation\Http\FormRequest;

class BranchPhotoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The 8-photo cap across what's already stored is checked in
     * BranchSettingsService, which knows the current count.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photos' => 'required|array|min:1|max:'.SpaBranchPhoto::MAX_PER_BRANCH,
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),
        ];
    }
}
