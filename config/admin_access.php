<?php

// How the system administrators' door is guarded, on top of their password.
//
// The administrator sign-in is its own route (POST /api/auth/admin/login) with
// its own rate limit, and the owner sign-in (POST /api/auth/login) refuses
// administrator accounts, so an admin password can only be tried at the admin
// door. These two settings add to that.
return [
    // Comma-separated addresses or CIDR ranges allowed to reach the admin
    // sign-in and the whole /api/system area (e.g. "203.0.113.7,10.0.0.0/8").
    // Empty (the default) means no restriction. Set this when the admins always
    // work from known places: a stolen password or token is then useless from
    // anywhere else.
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_ALLOWED_IPS', ''))))),

    // Every administrator must have two-factor authentication on to use the
    // /api/system area. Without it they can sign in, but only reach Settings →
    // Security to turn it on (App\Http\Middleware\RequireAdminTwoFactor).
    'require_two_factor' => (bool) env('ADMIN_REQUIRE_TWO_FACTOR', true),
];
