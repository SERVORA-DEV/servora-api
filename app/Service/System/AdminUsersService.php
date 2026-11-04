<?php

namespace App\Service\System;

use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\AdminUsersRepository;
use App\Http\Resources\AdminUsersResource;
use App\Service\NotificationService;
use App\Services\ImageUploadService;
use App\Support\AdminPermissions;
use App\Support\ProfilePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

        [$payload, $upload] = $this->prepareProfilePhoto($payload, null, 'administrators/profile');
        try {
            $user = DB::transaction(function () use ($payload, $permissionPayload) {
                $user = $this->adminUsersRepository->createUser($payload);
                $this->adminUsersRepository->createPermission([...$permissionPayload, 'user_id' => $user->id]);
                $user->markEmailAsVerified();

                return $user;
            });
        } catch (\Throwable $e) {
            $this->cleanUpPhoto($upload);
            throw $e;
        }

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
        [$payload, $upload] = $this->prepareProfilePhoto($payload, $before->profile_photo, "administrators/{$before->uuid}/profile");
        [$old, $new] = $this->auditLogRepository->diff(
            array_merge($before->getAttributes(), $before->permission?->getAttributes() ?? []),
            $payload,
        );

        try {
            // A permission-save failure must also roll back the photo change.
            $model = DB::transaction(fn () => $this->adminUsersRepository->update($uuid, $payload));
        } catch (\Throwable $e) {
            $this->cleanUpPhoto($upload);
            throw $e;
        }
        if ($before->profile_photo !== $model->profile_photo) {
            $this->cleanUpPhoto($before->profile_photo);
        }

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

    /** Keep the JSON upload contract, but store new image bytes through Cloudinary. */
    private function prepareProfilePhoto(array $payload, ?string $stored, string $folder): array
    {
        if (! array_key_exists('profile_photo', $payload)) {
            return [$payload, null];
        }
        $photo = $payload['profile_photo'];
        if ($photo === null || $photo === '') {
            $payload['profile_photo'] = null;

            return [$payload, null];
        }
        // Older clients send the displayed URL back on every edit. Never save
        // that URL over the public ID needed for later replacement/removal.
        if ($stored && ($photo === $stored || $photo === ProfilePhoto::url($stored))) {
            $payload['profile_photo'] = $stored;

            return [$payload, null];
        }
        if (! preg_match('/\Adata:(image\/(?:jpeg|png|webp));base64,/', $photo, $match)) {
            throw ValidationException::withMessages(['profile_photo' => 'Upload a JPEG, PNG, or WebP image.']);
        }
        $encoded = substr($photo, strlen($match[0]));
        $maxKb = (int) config('uploads.max_size_kb', 5120);
        if (strlen($encoded) > 4 * ceil($maxKb * 1024 / 3)) {
            throw ValidationException::withMessages(['profile_photo' => "The profile photo must not exceed {$maxKb} KB."]);
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['profile_photo' => 'The profile photo could not be read. Please choose it again.']);
        }

        // Decode only to a temporary file; private Laravel storage stays private.
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new \RuntimeException('Could not prepare the profile photo.');
        }
        try {
            if (fwrite($temporary, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Could not prepare the profile photo.');
            }
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$match[1]];
            $file = new UploadedFile(stream_get_meta_data($temporary)['uri'], "profile.{$extension}", $match[1], UPLOAD_ERR_OK, true);
            Validator::make(['profile_photo' => $file], ['profile_photo' => [
                'required', 'file', 'image',
                'mimes:'.implode(',', config('uploads.allowed_extensions')),
                'max:'.$maxKb,
            ]])->validate();

            $path = app(ImageUploadService::class)->store($file, $folder);
        } finally {
            fclose($temporary);
        }
        $payload['profile_photo'] = $path;

        return [$payload, $path];
    }

    private function cleanUpPhoto(?string $path): void
    {
        if (! $path || ProfilePhoto::isExternal($path)) {
            return;
        }
        try {
            app(ImageUploadService::class)->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
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
