<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Read through config rather than letting the Cloudinary SDK fall back to
    // its own getenv() lookup: that bypasses Laravel's env handling entirely,
    // so it can't be overridden per-environment in tests and depends on the
    // variable reaching the PHP process (not guaranteed under every SAPI).
    // "Continue with Google" on the web app's owner sign-in. Must be the same
    // OAuth client ID the frontend uses — it is the audience every Google ID
    // token is checked against (UserService::verifyGoogleIdToken).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'cloudinary' => [
        'url' => env('CLOUDINARY_URL'),
    ],

    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'base_url' => env('XENDIT_BASE_URL'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
    ],

];
