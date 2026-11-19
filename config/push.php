<?php

// Pop-ups for new notifications while Servora isn't open (App\Service\Notifications\PushSender).
// Each half stays off until its keys are set.
return [
    // Browsers (Web Push). Make a key pair once with `php artisan push:vapid`.
    // On Windows XAMPP, PHP can only encrypt a push when OPENSSL_CONF points
    // at an openssl.cnf before the server starts (see README/.env.example).
    'web' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // Who to contact about this sender: a mailto: or the site's URL.
        'subject' => env('VAPID_SUBJECT', 'mailto:support@servora.app'),
    ],

    // Phones (Firebase Cloud Messaging, HTTP v1): the Firebase project id and
    // its service account key (Project settings → Service accounts), given
    // either as the key's JSON itself — raw or base64 — in FCM_CREDENTIALS_JSON
    // (for a server built from git, where the key file never is: Coolify), or
    // as the path to the file in FCM_CREDENTIALS (a PC). The JSON wins.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_json' => env('FCM_CREDENTIALS_JSON'),
        'credentials' => env('FCM_CREDENTIALS'),
    ],
];
