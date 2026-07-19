<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            // User
            'role' => [
                'required',
                Rule::in([
                    'system_administrator',
                    'business_owner',
                    'staff',
                    'client'
                ]),
            ],

            'username' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'username')->ignore($user),
            ],

            'first_name' => 'nullable|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'suffix' => 'nullable|string|max:20',

            'gender' => [
                'nullable',
                Rule::in(['Male', 'Female']),
            ],

            'birth_date' => 'nullable|date',

            'phone_number' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone_number')->ignore($user),
            ],

            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user),
            ],

            'password' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string',
                'min:8',
            ],

            'profile_photo' => 'nullable|string',

            'account_status' => [
                'sometimes',
                Rule::in([
                    'Pending',
                    'Active',
                    'Inactive',
                    'Suspended',
                ]),
            ],

            // Permissions
            'dashboard_view' => 'sometimes|boolean',

            'admin_manage' => 'sometimes|boolean',
            'permission_manage' => 'sometimes|boolean',

            'subscription_plan_manage' => 'sometimes|boolean',
            'subscription_manage' => 'sometimes|boolean',

            'spa_business_manage' => 'sometimes|boolean',
            'spa_branch_manage' => 'sometimes|boolean',

            'staff_manage' => 'sometimes|boolean',
            'attendance_manage' => 'sometimes|boolean',

            'service_manage' => 'sometimes|boolean',
            'package_manage' => 'sometimes|boolean',
            'facility_manage' => 'sometimes|boolean',

            'client_manage' => 'sometimes|boolean',

            'appointment_manage' => 'sometimes|boolean',
            'queue_manage' => 'sometimes|boolean',

            'billing_manage' => 'sometimes|boolean',
            'payment_manage' => 'sometimes|boolean',
            'commission_manage' => 'sometimes|boolean',

            'loyalty_manage' => 'sometimes|boolean',
            'review_manage' => 'sometimes|boolean',

            'report_view' => 'sometimes|boolean',
            'report_export' => 'sometimes|boolean',

            'notification_manage' => 'sometimes|boolean',

            'audit_log_view' => 'sometimes|boolean',
        ];
    }
}