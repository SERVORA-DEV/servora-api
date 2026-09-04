<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Wraps an associative array (not a single Eloquent model) — the detail
// view needs the Attendance row plus AttendanceStatusCalculator's derived
// fields plus the audit_logs history for this record, assembled once in
// AttendanceService::showDetail() rather than re-derived on the frontend.
class AttendanceDetailResource extends JsonResource
{
    // Manager/front_officer login accounts commonly have no first/last name
    // of their own — the linked Staff record is the name the branch
    // actually knows them by (same convention as UserResource::toArray).
    private function displayName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $staffName = trim("{$user->staff?->first_name} {$user->staff?->last_name}");
        if ($staffName) {
            return $staffName;
        }

        return trim("{$user->first_name} {$user->last_name}") ?: null;
    }

    public function toArray(Request $request): array
    {
        $attendance = $this->resource['attendance'];
        $calculated = $this->resource['calculated'];
        $history = $this->resource['history'];
        $staff = $attendance->staff;

        return [
            'uuid' => $attendance->uuid,
            'staff_uuid' => $staff?->uuid,
            'staff_name' => trim("{$staff?->first_name} {$staff?->last_name}"),
            'position' => $staff?->role,
            'branch_uuid' => $staff?->branch?->uuid,
            'branch_name' => $staff?->branch?->branch_name,
            'attendance_date' => $attendance->attendance_date->format('Y-m-d'),
            'scheduled_start' => $calculated['scheduled_start'],
            'scheduled_end' => $calculated['scheduled_end'],
            'check_in_at' => $attendance->check_in_at?->format('H:i'),
            'check_out_at' => $attendance->check_out_at?->format('H:i'),
            'work_minutes' => $calculated['work_minutes'],
            'status' => $calculated['status'],
            'late_minutes' => $calculated['late_minutes'],
            'is_day_off' => $calculated['is_day_off'],
            'missing_check_in' => $calculated['missing_check_in'],
            'missing_check_out' => $calculated['missing_check_out'],
            'remarks' => $attendance->remarks,
            'created_by' => $this->displayName($attendance->creator),
            'created_at' => $attendance->created_at?->toIso8601String(),
            'updated_by' => $this->displayName($attendance->updater),
            'updated_at' => $attendance->updated_at?->toIso8601String(),
            'history' => $history->map(fn ($log) => [
                'action' => $log->action,
                'user_name' => $this->displayName($log->user),
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
