<?php

namespace App\Http\Controllers;

use App\Service\RoleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    private RoleService $roleService;

    public function __construct(RoleService $roleService)
    {
        $this->roleService = $roleService;
    }

    public function index(Request $request)
    {
        return $this->roleService->listRole($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->roleService->createRole($request->all());
    }

    public function show(string $uuid)
    {
        return $this->roleService->getRole($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->roleService->updateRole($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->roleService->deleteRole($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->roleService->restoreRole($uuid);
    }
}