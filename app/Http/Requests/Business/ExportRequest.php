<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/reports/export-data (PlanAccessController::export).
class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => 'required|in:csv,pdf,excel',
            'type' => 'required|in:sales,appointments,clients,services,staff,branches',
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'branch_uuid' => 'nullable|uuid',
            // What the list on screen is narrowed to: its dropdown, and its search box.
            'list_filter' => 'nullable|in:paid,unpaid,refunded,walkin,booking,app,member,service,package',
            'search' => 'nullable|string|max:100',
        ];
    }
}
