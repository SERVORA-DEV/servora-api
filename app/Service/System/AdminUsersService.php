<?php

namespace App\Service\System;

use App\Repository\System\AdminUsersRepository;
use App\Http\Resources\AdminUsersResource;
use Illuminate\Support\Arr;

class AdminUsersService
{
    private AdminUsersRepository $adminUsersRepository;

    public function __construct(AdminUsersRepository $adminUsersRepository)
    {
        $this->adminUsersRepository = $adminUsersRepository;
    }

    public function listAdminUsers(int $perPage = 15)
    {
        $collection = $this->adminUsersRepository->paginate($perPage);
        return AdminUsersResource::collection($collection);
    }

    public function createAdminUsers(array $payload)
    {

        $payload['role'] = 'system_administrator';

        $user = $this->adminUsersRepository->createUser($payload);

        $permissionPayload = Arr::only($payload, config('permission.system_administrator'));
        $permissionPayload['user_id'] = $user->id;

        $this->adminUsersRepository->createPermission($permissionPayload);

        $user->markEmailAsVerified();

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