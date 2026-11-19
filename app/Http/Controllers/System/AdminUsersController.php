<?php

namespace App\Http\Controllers\System;

use App\Http\Requests\UserRequest;
use App\Service\System\AdminUsersService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AdminUsersController extends Controller
{
    public function __construct(
        private AdminUsersService $adminUsersService,
    ) {}

    public function index(Request $request)
    {
        return $this->adminUsersService->listAdminUsers($request->input('per_page', 15));
    }

    public function store(UserRequest $request)
    {
        return $this->adminUsersService->createAdminUsers($request->validated(), $request->user());
    }

    public function show(string $uuid)
    {
        return $this->adminUsersService->getAdminUsers($uuid);
    }

    public function update(UserRequest $request, string $uuid)
    {
        return $this->adminUsersService->updateAdminUsers($uuid, $request->validated(), $request->user());
    }

    // Deactivates (see AdminUsersService::deactivateAdminUsers).
    public function destroy(Request $request, string $uuid)
    {
        return $this->adminUsersService->deactivateAdminUsers($uuid, $request->user());
    }
}