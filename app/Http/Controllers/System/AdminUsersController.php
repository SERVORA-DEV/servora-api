<?php

namespace App\Http\Controllers\System;

use App\Http\Requests\UserRequest;
use App\Service\System\AdminUsersService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AdminUsersController extends Controller
{
    private AdminUsersService $adminUsersService;

    public function __construct(AdminUsersService $adminUsersService)
    {
        $this->adminUsersService = $adminUsersService;
    }

    public function index(Request $request)
    {
        return $this->adminUsersService->listAdminUsers($request->input('per_page', 15));
    }

    public function store(UserRequest $request)
    {
        return $this->adminUsersService->createAdminUsers($request->validated());
    }

    public function show(string $uuid)
    {
        return $this->adminUsersService->getAdminUsers($uuid);
    }

    public function update(UserRequest $request, string $uuid)
    {
        return $this->adminUsersService->updateAdminUsers($uuid, $request->validated());
    }

    // public function destroy(string $uuid)
    // {
    //     $this->adminUsersService->deleteAdminUsers($uuid);
    //     return response()->json(['message' => 'Deleted successfully'], 200);
    // }
    
    // public function restore(string $uuid)
    // {
    //     return $this->adminUsersService->restoreAdminUsers($uuid);
    // }
}