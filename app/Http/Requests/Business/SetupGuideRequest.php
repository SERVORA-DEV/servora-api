<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// PUT api/business/setup-guide (SetupGuideController::update): the guide's progress.
class SetupGuideRequest extends FormRequest
{
    // The most steps kept, done or skipped (the controller caps what it saves to this too).
    public const MAX_STEPS = 40;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'done' => ['present', 'array', 'max:' . self::MAX_STEPS],
            'done.*' => ['string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/'],
            'skipped' => ['present', 'array', 'max:' . self::MAX_STEPS],
            'skipped.*' => ['string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/'],
            'finished' => ['required', 'boolean'],
        ];
    }
}
