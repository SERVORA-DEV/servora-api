<?php

namespace App\Service;

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
// are set.
class PushSender
{
    public function queue(Notification $notification): void
    {
        if (! $this->webReady() && ! $this->fcmReady()) {
            return;
        }
        $id = $notification->id;
        dispatch(fn () => app(self::class)->send($id))->afterResponse();
    }

    public function send(int $notificationId): void
    {
        try {
            // Gone if it was written inside a transaction that rolled back.
            $notification = Notification::with('user:id,role')->find($notificationId);
            if (! $notification) {
                return;
            }
            $subscriptions = PushSubscription::where('user_id', $notification->user_id)->get();
            if ($subscriptions->isEmpty()) {
                return;
            }

            $web = $subscriptions->where('kind', PushSubscription::KIND_WEB);
            if ($web->isNotEmpty() && $this->webReady()) {
                $this->sendWeb($notification, $web);
            }
            $fcm = $subscriptions->where('kind', PushSubscription::KIND_FCM);
            if ($fcm->isNotEmpty() && $this->fcmReady()) {
                $this->sendFcm($notification, $fcm);
            }
        } catch (\Throwable $e) {
            Log::warning('Push for notification '.$notificationId.' failed: '.$e->getMessage());
        }
    }

    public function webReady(): bool
    {
        return filled(config('push.web.public_key')) && filled(config('push.web.private_key'));
    }

    public function fcmReady(): bool
    {
        $path = config('push.fcm.credentials');

        return filled(config('push.fcm.project_id')) && filled($path) && is_file($path);
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
        ];

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                'contentEncoding' => 'aes128gcm',
            ]), json_encode($payload + ['silent' => $sub->quiet]));
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }
            // The browser dropped it (site data cleared, permission revoked).
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', PushSubscription::hashOf($report->getEndpoint()))->delete();
            } else {
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
                continue;
            }
            $status = $response->json('error.status');
            // The app was uninstalled or its token replaced.
            if ($response->status() === 404 || $status === 'UNREGISTERED' || $status === 'INVALID_ARGUMENT') {
                $sub->delete();
            } else {
                Log::warning('FCM push failed: '.$response->status().' '.$response->body());
            }
        }
    }

    private function fcmAccessToken(): string
    {
        return Cache::remember('push:fcm-access-token', now()->addMinutes(50), function () {
            $credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                json_decode(file_get_contents(config('push.fcm.credentials')), true),
            );

            return $credentials->fetchAuthToken()['access_token'];
        });
    }
}
