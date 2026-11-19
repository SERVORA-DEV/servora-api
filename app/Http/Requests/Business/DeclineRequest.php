<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Turning down a request, with an optional reason (catalog, program, schedule and transfer requests).
// POST api/business/catalog-requests/{uuid}/decline, POST api/business/program-requests/{uuid}/decline, POST api/business/schedule-requests/{uuid}/reject, POST api/business/transfer-requests/{uuid}/decline (CatalogRequestController::decline, ProgramRequestController::decline, StaffScheduleController::rejectRequest, StaffTransferController::decline).
class DeclineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => 'nullable|string|max:500'];
    }
}
