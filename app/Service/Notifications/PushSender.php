<?php

namespace App\Service\Notifications;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Support\ClientNotificationLink;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

// Pops a new notification up on its user's browsers and phones while Servora
// isn't open there (push_subscriptions). NotificationRepository::create hands
// every new row here; the open web app and phone app show their own pop-up
// instead (the browser's service worker stays quiet while a Servora tab is
// in view).
//
// Sent after the response, so nobody waits on a push service, and never
// throws: a pop-up that can't be sent is logged, not an error for whoever
// caused the notification. Each half (config/push.php) is off until its keys
// are set, and says so in the log when a device was waiting for it.
class PushSender
{
    // What happened to each device on the last send, for `php artisan push:test`.
    private array $results = [];

    private bool $always = false;

    public function results(): array
    {
        return $this->results;
    }

    public function queue(Notification $notification): void
    {
        $id = $notification->id;
        dispatch(fn () => app(self::class)->send($id))->afterResponse();
    }

    /**
     * @param  bool  $always  a test: browsers show it even while a Servora tab is in front
     *                        (normally that tab's own pop-up shows it instead)
     */
    public function send(int $notificationId, bool $always = false): void
    {
        $this->results = [];
        $this->always = $always;
        try {
            // Gone if it was written inside a transaction that rolled back.
            $notification = Notification::with('user:id,role')->find($notificationId);
            if (! $notification) {
                return;
            }
            $subscriptions = PushSubscription::where('user_id', $notification->user_id)->get();
            if ($subscriptions->isEmpty()) {
                $this->results[] = 'No browser or phone has pop-ups on for this account.';

                return;
            }

            $web = $subscriptions->where('kind', PushSubscription::KIND_WEB);
            if ($web->isNotEmpty() && $this->webReady()) {
                $this->sendWeb($notification, $web);
            } elseif ($web->isNotEmpty()) {
                $this->results[] = 'Browsers skipped: this server has no VAPID keys (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY).';
                Log::warning('Push skipped for '.$web->count().' browser(s): no VAPID keys on this server.');
            }
            $fcm = $subscriptions->where('kind', PushSubscription::KIND_FCM);
            if ($fcm->isNotEmpty() && $this->fcmReady()) {
                $this->sendFcm($notification, $fcm);
            } elseif ($fcm->isNotEmpty()) {
                // Phones are waiting for pop-ups this server can't send: say so
                // instead of dropping them quietly.
                $this->results[] = 'Phones skipped: this server has no Firebase key (FCM_PROJECT_ID and FCM_CREDENTIALS_JSON or FCM_CREDENTIALS).';
                Log::warning('Push skipped for '.$fcm->count().' phone(s): no Firebase key on this server (set FCM_PROJECT_ID and FCM_CREDENTIALS_JSON).');
            }
        } catch (\Throwable $e) {
            $this->results[] = 'Failed: '.$e->getMessage();
            Log::warning('Push for notification '.$notificationId.' failed: '.$e->getMessage());
        }
    }

    public function webReady(): bool
    {
        return filled(config('push.web.public_key')) && filled(config('push.web.private_key'));
    }

    public function fcmReady(): bool
    {
        return filled(config('push.fcm.project_id')) && $this->fcmCredentials() !== null;
    }

    // The Firebase service account key (config/push.php): the JSON from the
    // environment, raw or base64, else the file it names. Null when neither is
    // usable.
    private function fcmCredentials(): ?array
    {
        $json = trim((string) config('push.fcm.credentials_json'));
        if ($json !== '') {
            $key = json_decode(str_starts_with($json, '{') ? $json : (string) base64_decode($json, true), true);

            return is_array($key) && isset($key['client_email'], $key['private_key']) ? $key : null;
        }
        $path = config('push.fcm.credentials');
        if (! filled($path) || ! is_file($path)) {
            return null;
        }
        $key = json_decode((string) file_get_contents($path), true);

        return is_array($key) ? $key : null;
    }

    // ── Browsers ─────────────────────────────────────────────────────────

    private function sendWeb(Notification $notification, Collection $subscriptions): void
    {
        $webPush = new WebPush(['VAPID' => [
            'subject' => config('push.web.subject'),
            'publicKey' => config('push.web.public_key'),
            'privateKey' => config('push.web.private_key'),
        ]], ['TTL' => 86400, 'urgency' => 'high']);

        $payload = [
            'id' => $notification->id,
            'title' => $notification->title,
            'body' => $notification->message,
            'type' => $notification->type,
            'url' => $this->webPath($notification),
            // A test (send(always: true)): shown even with a Servora tab in front (public/sw.js).
            'always' => $this->always,
        ];

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                'contentEncoding' => 'aes128gcm',
            ]), json_encode($payload + ['silent' => $sub->quiet]));
        }

        foreach ($webPush->flush() as $report) {
            $host = parse_url($report->getEndpoint(), PHP_URL_HOST);
            if ($report->isSuccess()) {
                $this->results[] = "Browser ({$host}): accepted";

                continue;
            }
            // The browser dropped it (site data cleared, permission revoked).
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', PushSubscription::hashOf($report->getEndpoint()))->delete();
                $this->results[] = "Browser ({$host}): gone, removed";
            } else {
                $this->results[] = "Browser ({$host}): failed — ".$report->getReason();
                Log::warning('Web push failed: '.$report->getReason());
            }
        }
    }

    // The notification's own page in the web app, by who it is for (the same
    // paths as staffNotificationsBase and the admin's list on the web).
    private function webPath(Notification $notification): string
    {
        return match ($notification->user?->role) {
            'system_administrator' => '/system/notifications/'.$notification->id,
            'front_officer' => '/frontoffice/notifications/'.$notification->id,
            'business_owner', 'manager' => '/business/notifications/'.$notification->id,
            default => '/',
        };
    }

    // ── Phones ───────────────────────────────────────────────────────────

    private function sendFcm(Notification $notification, Collection $subscriptions): void
    {
        $token = $this->fcmAccessToken();
        $url = 'https://fcm.googleapis.com/v1/projects/'.config('push.fcm.project_id').'/messages:send';
        // The queue call opens the queue, a rewards notice that spa's rewards.
        $kind = ClientNotificationLink::kind($notification);
        $businessUuid = $kind === 'reward' ? ClientNotificationLink::businessUuid($notification) : null;

        foreach ($subscriptions as $sub) {
            $response = Http::withToken($token)->timeout(10)->post($url, ['message' => [
                'token' => $sub->endpoint,
                'notification' => [
                    'title' => $notification->title,
                    'body' => $notification->message,
                ],
                // What the app needs to open it when the pop-up is tapped.
                'data' => [
                    'notification_id' => (string) $notification->id,
                    'type' => (string) $notification->type,
                    'reference_uuid' => (string) ($notification->reference_uuid ?? ''),
                    'kind' => (string) ($kind ?? ''),
                    'business_uuid' => (string) ($businessUuid ?? ''),
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => array_filter([
                        // The app's pop-up channels (LocalNotifier): with the
                        // chime (android/app/src/main/res/raw/notification_chime.wav),
                        // or without a sound when the phone has it turned off.
                        'channel_id' => $sub->quiet ? 'servora_push_quiet' : 'servora_push',
                        'sound' => $sub->quiet ? null : 'notification_chime',
                        'tag' => 'servora-'.$notification->id,
                    ]),
                ],
                'apns' => ['payload' => ['aps' => $sub->quiet ? ['alert' => ['title' => $notification->title, 'body' => $notification->message]] : ['sound' => 'default']]],
            ]]);

            if ($response->successful()) {
                $this->results[] = "Phone #{$sub->id} (since {$sub->created_at}): accepted by Firebase";

                continue;
            }
            $status = $response->json('error.status');
            // The app was uninstalled or its token replaced. INVALID_ARGUMENT is only that when
            // Firebase says the token is the problem: it is also what a bad message gets, and a
            // bad message must not delete every phone it was sent to.
            $badToken = $status === 'INVALID_ARGUMENT'
                && str_contains(strtolower((string) $response->json('error.message')), 'registration token');
            if ($response->status() === 404 || $status === 'UNREGISTERED' || $badToken) {
                $sub->delete();
                $this->results[] = "Phone #{$sub->id}: no longer installed or signed in there, removed";
            } else {
                $this->results[] = "Phone #{$sub->id}: failed — ".$response->status().' '.$response->json('error.message');
                Log::warning('FCM push failed: '.$response->status().' '.$response->body());
            }
        }
    }

    private function fcmAccessToken(): string
    {
        return Cache::remember('push:fcm-access-token', now()->addMinutes(50), function () {
            $credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                $this->fcmCredentials(),
            );

            return $credentials->fetchAuthToken()['access_token'];
        });
    }
}
