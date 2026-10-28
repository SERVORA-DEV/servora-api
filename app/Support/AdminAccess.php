<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

// The rules behind the administrator door, kept apart from the middleware so
// they can be tested without a request or a database.
class AdminAccess
{
    // The only part of the admin area an administrator without two-factor can
    // reach: their own Settings → Security, where it is turned on.
    public const TWO_FACTOR_SETUP_PATH = '*/system/security/*';

    /**
     * Whether an address may reach the admin door. An empty list allows every
     * address; otherwise the address must match an entry (a single address or
     * a CIDR range, IPv4 or IPv6).
     *
     * @param  list<string>  $allowed
     */
    public static function ipAllowed(?string $ip, array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }

        return $ip !== null && $ip !== '' && IpUtils::checkIp($ip, $allowed);
    }

    /**
     * Whether an administrator must be turned back because two-factor is not
     * on yet. Only the setup pages stay open to them.
     */
    public static function twoFactorBlocks(bool $required, ?string $role, bool $twoFactorOn, bool $onSetupPath): bool
    {
        return $required
            && $role === 'system_administrator'
            && ! $twoFactorOn
            && ! $onSetupPath;
    }
}
