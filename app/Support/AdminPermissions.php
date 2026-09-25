<?php

namespace App\Support;

use App\Models\User;

// What a system administrator may actually do: the role bundle in
// config/permission.php is the ceiling and the admin's own user_permissions
// row narrows it (Settings → Administrators → Permissions). An admin with no
// row keeps the whole bundle, same rule EnsurePermission applies to
// owner-created staff accounts.
class AdminPermissions
{
    public static function keys(): array
    {
        return config('permission.system_administrator', []);
    }

    /** @return array<string, bool> */
    public static function effective(User $user): array
    {
        $row = $user->permission;

        return collect(self::keys())
            ->mapWithKeys(fn (string $key) => [$key => $row ? $row->getAttribute($key) === true : true])
            ->all();
    }

    public static function allows(User $user, string $key): bool
    {
        return self::effective($user)[$key] ?? false;
    }
}
