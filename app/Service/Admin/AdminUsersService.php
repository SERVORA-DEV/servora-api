<?php

namespace App\Service\Admin;

use App\Repository\Admin\AdminUsersRepository;
use App\Http\Resources\AdminUsersResource;

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
        $model = $this->adminUsersRepository->create($payload);
        return new AdminUsersResource($model);
    }

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

    public function updateAdminUsers(string $uuid, array $payload)
    {
        $model = $this->adminUsersRepository->update($uuid, $payload);
        return new AdminUsersResource($model);
    }

    public function deleteAdminUsers(string $uuid)
    {
        $this->adminUsersRepository->delete($uuid);
        return true;
    }

    public function restoreAdminUsers(string $uuid)
    {
        $model = $this->adminUsersRepository->restore($uuid);
        return new AdminUsersResource($model);
    }
}