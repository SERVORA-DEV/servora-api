<?php

namespace App\Service\System;

use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\AdminUsersRepository;
use App\Http\Resources\AdminUsersResource;
use App\Service\NotificationService;
use Illuminate\Support\Arr;

class AdminUsersService
{
    private AdminUsersRepository $adminUsersRepository;
    private NotificationService $notificationService;
    private AuditLogRepository $auditLogRepository;

    public function __construct(
        AdminUsersRepository $adminUsersRepository,
        NotificationService $notificationService,
        AuditLogRepository $auditLogRepository,
    ) {
        $this->adminUsersRepository = $adminUsersRepository;
        $this->notificationService = $notificationService;
        $this->auditLogRepository = $auditLogRepository;
    }

    public function listAdminUsers(int $perPage = 15)
    {
        $collection = $this->adminUsersRepository->paginate($perPage);
        return AdminUsersResource::collection($collection);
    }

    public function createAdminUsers(array $payload, ?User $actor = null)
    {

        $payload['role'] = 'system_administrator';

        $user = $this->adminUsersRepository->createUser($payload);

        $permissionPayload = Arr::only($payload, config('permission.system_administrator'));
        $permissionPayload['user_id'] = $user->id;

        $this->adminUsersRepository->createPermission($permissionPayload);

        $user->markEmailAsVerified();

        $this->auditLogRepository->recordAdminAction('users', $user->id, 'Create', null, [
            'name' => trim("{$user->first_name} {$user->last_name}") ?: $user->email,
            'email' => $user->email,
            'role' => 'system_administrator',
        ]);

        // The actor already knows they just did this — notify every OTHER
        // administrator instead.
        $recipients = $this->adminUsersRepository->allAdministrators()
            ->reject(fn (User $admin) => $actor && $admin->id === $actor->id);
        $this->notificationService->administratorCreated($user, $recipients);

        return new AdminUsersResource($user->load('permission'));
    }

    public function updateAdminUsers(string $uuid, array $payload)
    {
        $before = $this->adminUsersRepository->findByUuid($uuid);
        [$old, $new] = $this->auditLogRepository->diff(
            array_merge($before->getAttributes(), $before->permission?->getAttributes() ?? []),
            $payload,
        );

        $model = $this->adminUsersRepository->update($uuid, $payload);

        if ($new) {
            $this->auditLogRepository->recordAdminAction('users', $model->id, 'Update', $old, array_merge($new, [
                'name' => trim("{$model->first_name} {$model->last_name}") ?: $model->email,
            ]));
        }

        return new AdminUsersResource($model);
    }

    // public function deleteAdminUsers(string $uuid)
    // {
    //     $this->adminUsersRepository->delete($uuid);
    //     return true;
    // }

    // public function restoreAdminUsers(string $uuid)
    // {
    //     $model = $this->adminUsersRepository->restore($uuid);
    //     return new AdminUsersResource($model);
    // }

    public function getAdminUsers(string $uuid)
    {
        $model = $this->adminUsersRepository->findByUuid($uuid);
        return new AdminUsersResource($model);
    }

    public function getAdminUsersByField(string $field, $value)
    {
        $model = $this->adminUsersRepository->findByField($field, $value);
        return new AdminUsersResource($model);
    }
}