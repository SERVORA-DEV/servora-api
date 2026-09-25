<?php

namespace App\Service\System;

use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\AdminUsersRepository;
use App\Http\Resources\AdminUsersResource;
use App\Service\NotificationService;
use App\Support\AdminPermissions;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

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

        $permissionPayload = Arr::only($payload, AdminPermissions::keys());

        // Setting someone's permissions is itself a permission.
        if ($permissionPayload && $actor && ! AdminPermissions::allows($actor, 'permission_update')) {
            abort(403, 'You need the Update Permissions permission to set what a new administrator can do.');
        }

        $user = $this->adminUsersRepository->createUser($payload);

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

    public function updateAdminUsers(string $uuid, array $payload, ?User $actor = null)
    {
        $before = $this->adminUsersRepository->findByUuid($uuid);
        $isSelf = $actor && $actor->id === $before->id;

        // Only flags that actually change count as a permission edit — the
        // edit form sends every flag back even when just the name changed.
        $current = AdminPermissions::effective($before);
        $permissionChanges = collect(Arr::only($payload, AdminPermissions::keys()))
            ->filter(fn ($value, $key) => (bool) $value !== ($current[$key] ?? false))
            ->all();

        if ($permissionChanges) {
            if ($actor && ! AdminPermissions::allows($actor, 'permission_update')) {
                abort(403, 'You need the Update Permissions permission to change what an administrator can do.');
            }
            if ($isSelf) {
                throw ValidationException::withMessages([
                    'permissions' => 'You can\'t change your own permissions. Ask another administrator.',
                ]);
            }
        }

        $statusChange = isset($payload['account_status']) && $payload['account_status'] !== $before->account_status;
        if ($statusChange && $isSelf) {
            throw ValidationException::withMessages([
                'account_status' => 'You can\'t change your own account status.',
            ]);
        }

        $this->assertKeepsAnAccountManager($before, [
            ...$current,
            ...$permissionChanges,
        ], $payload['account_status'] ?? $before->account_status);
        [$old, $new] = $this->auditLogRepository->diff(
            array_merge($before->getAttributes(), $before->permission?->getAttributes() ?? []),
            $payload,
        );

        $model = $this->adminUsersRepository->update($uuid, $payload);

        if ($statusChange && $model->account_status !== 'Active') {
            $model->tokens()->delete();
        }

        if ($new) {
            $this->auditLogRepository->recordAdminAction('users', $model->id, 'Update', $old, array_merge($new, [
                'name' => trim("{$model->first_name} {$model->last_name}") ?: $model->email,
            ]));
        }

        return new AdminUsersResource($model);
    }

    // "Delete Admins" deactivates rather than deletes, so the account's
    // history and audit trail stay intact and it can be reactivated later
    // (an update back to account_status Active). Signs the account out
    // everywhere straight away.
    public function deactivateAdminUsers(string $uuid, User $actor)
    {
        $admin = $this->adminUsersRepository->findByUuid($uuid);

        if ($admin->id === $actor->id) {
            throw ValidationException::withMessages([
                'account_status' => 'You can\'t deactivate your own account.',
            ]);
        }

        if ($admin->account_status !== 'Inactive') {
            $this->assertKeepsAnAccountManager($admin, AdminPermissions::effective($admin), 'Inactive');

            $old = $admin->account_status;
            $admin->forceFill(['account_status' => 'Inactive'])->save();
            $admin->tokens()->delete();

            $this->auditLogRepository->recordAdminAction('users', $admin->id, 'Deactivate', ['account_status' => $old], [
                'account_status' => 'Inactive',
                'name' => trim("{$admin->first_name} {$admin->last_name}") ?: $admin->email,
            ]);
        }

        return new AdminUsersResource($admin->load('permission'));
    }

    // There must always be at least one active administrator who can manage
    // administrator accounts and their permissions — otherwise nobody could
    // ever undo a mistake without touching the database.
    private function assertKeepsAnAccountManager(User $target, array $targetPermissions, string $targetStatus): void
    {
        $canManage = fn (array $p) => ($p['admin_update'] ?? false) && ($p['permission_update'] ?? false);

        if ($targetStatus === 'Active' && $canManage($targetPermissions)) {
            return;
        }

        $others = $this->adminUsersRepository->allAdministrators()
            ->filter(fn (User $admin) => $admin->id !== $target->id && $admin->account_status === 'Active')
            ->contains(fn (User $admin) => $canManage(AdminPermissions::effective($admin)));

        if (! $others) {
            throw ValidationException::withMessages([
                'permissions' => 'At least one active administrator must keep Update Admins and Update Permissions.',
            ]);
        }
    }

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