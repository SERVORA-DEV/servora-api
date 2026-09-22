<?php

namespace App\Service\System;

use App\Models\User;
use App\Repository\System\AdminUsersRepository;
use App\Http\Resources\AdminUsersResource;
use App\Service\NotificationService;
use Illuminate\Support\Arr;

class AdminUsersService
{
    private AdminUsersRepository $adminUsersRepository;
    private NotificationService $notificationService;

    public function __construct(AdminUsersRepository $adminUsersRepository, NotificationService $notificationService)
    {
        $this->adminUsersRepository = $adminUsersRepository;
        $this->notificationService = $notificationService;
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

        // The actor already knows they just did this — notify every OTHER
        // administrator instead.
        $recipients = $this->adminUsersRepository->allAdministrators()
            ->reject(fn (User $admin) => $actor && $admin->id === $actor->id);
        $this->notificationService->administratorCreated($user, $recipients);

        return new AdminUsersResource($user->load('permission'));
    }

    public function updateAdminUsers(string $uuid, array $payload)
    {
        $model = $this->adminUsersRepository->update($uuid, $payload);
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