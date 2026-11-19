<?php

namespace App\Http\Requests\Business;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST /business/attendance — always an upsert keyed on
// (staff_uuid, attendance_date), never a PATCH-by-id, so every field here is
// simply "required" rather than split create/update like StaffRequest.
// staff_uuid is resolved (and branch-scoped) server-side in
// AttendanceService::markAttendance, same pattern StaffService uses for
// spa_branch_uuid on staff creation.
class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_uuid' => ['required', 'uuid', 'exists:staff,uuid'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', Rule::in([AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::Absent->value, AttendanceStatus::HalfDay->value, AttendanceStatus::OnLeave->value, AttendanceStatus::Holiday->value, AttendanceStatus::FillIn->value])],
            'check_in_at' => ['nullable', 'date_format:H:i'],
            'check_out_at' => ['nullable', 'date_format:H:i', 'after:check_in_at'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
