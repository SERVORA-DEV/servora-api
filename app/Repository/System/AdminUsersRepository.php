<?php

namespace App\Repository\System;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminUsersRepository
{
    public function paginate(int $perPage = 15)
    {
        return User::with('permission')
        ->latest()
        ->paginate($perPage);
    }

    public function createUser(array $payload)
    {
        return User::create($payload);
    }

    public function createPermission(array $payload)
    {
        return UserPermission::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return User::with('permission')
        ->where('uuid', $uuid)
        ->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return User::with('permission')
        ->where($field, $value)
        ->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = User::where('uuid', $uuid)->firstOrFail();

        $userData = Arr::only($payload, [
            'username',
            'first_name',
            'middle_name',
            'last_name',
            'suffix',
            'gender',
            'birth_date',
            'phone_number',
            'email',
            'password',
            'profile_photo',
            'account_status',
        ]);

        $permissionData = Arr::only($payload, [
            'dashboard_view',
            'admin_manage',
            'permission_manage',
            'subscription_plan_manage',
            'subscription_manage',
            'spa_business_manage',
            'spa_branch_manage',
            'staff_manage',
            'attendance_manage',
            'service_manage',
            'package_manage',
            'facility_manage',
            'client_manage',
            'appointment_manage',
            'queue_manage',
            'billing_manage',
            'payment_manage',
            'commission_manage',
            'loyalty_manage',
            'review_manage',
            'report_view',
            'report_export',
            'notification_manage',
            'audit_log_view',
        ]);

        $model->update($userData);
        $model->permission()->update($permissionData);

        return $model->load('permission');
    }

    // public function delete(string $uuid)
    // {
    //     $model = $this->findByUuid($uuid);
    //     return $model->delete();
    // }

    // public function restore(string $uuid)
    // {
    //     $model = AdminUsers::withTrashed()->where('uuid', $uuid)->firstOrFail();
    //     $model->restore();
    //     return $model;
    // }
}