<?php

namespace App\Http\Requests\Business\BranchSettings;

use App\Models\SpaBranchPhoto;
use Illuminate\Foundation\Http\FormRequest;

// Validates PATCH /business/branch/{uuid}/photos/order — the gallery's photo
// uuids in the order the owner wants them. That the list is exactly this
// branch's photos is checked in BranchSettingsService::reorderPhotos.
class BranchPhotoOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => 'required|array|min:1|max:' . SpaBranchPhoto::MAX_PER_BRANCH,
            'photos.*' => 'required|uuid|distinct',
        ];
    }
}
