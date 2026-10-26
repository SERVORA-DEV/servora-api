<?php

// Pop-ups for new notifications while Servora isn't open (App\Service\PushSender).
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
    // the path to its service account key (Project settings → Service accounts).
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
    ],
];
