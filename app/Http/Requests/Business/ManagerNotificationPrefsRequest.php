<?php

namespace App\Http\Requests\Business;

use App\Support\Staff\ManagerNotificationPrefs;
use Illuminate\Foundation\Http\FormRequest;

// PATCH api/business/notification-preferences (ManagerNotificationPrefsController::update).
class ManagerNotificationPrefsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys(ManagerNotificationPrefs::DEFAULTS), 'sometimes|boolean');
    }
}
