<?php

namespace App\Service;

use App\Repository\RoleRepository;
use App\Http\Resources\RoleResource;

class RoleService
{
    private RoleRepository $roleRepository;

    public function __construct(RoleRepository $roleRepository) 
    {
        $this->roleRepository = $roleRepository;
    }

    public function listRole(int $perPage = 15)
    {
        $collection = $this->roleRepository->paginate($perPage);
        return RoleResource::collection($collection);
    }

    public function createRole(array $payload)
    {
        $model = $this->roleRepository->create($payload);
        return new RoleResource($model);
    }

    public function getRole(string $uuid)
    {
        $model = $this->roleRepository->findByUuid($uuid);
        return new RoleResource($model);
    }

    public function getRoleByField(string $field, $value)
    {
        $model = $this->roleRepository->findByField($field, $value);
        return new RoleResource($model);
    }

    public function updateRole(string $uuid, array $payload)
    {
        $model = $this->roleRepository->update($uuid, $payload);
        return new RoleResource($model);
    }

    public function deleteRole(string $uuid)
    {
        $this->roleRepository->delete($uuid);
        return true;
    }

    public function restoreRole(string $uuid)
    {
        $model = $this->roleRepository->restore($uuid);
        return new RoleResource($model);
    }
}