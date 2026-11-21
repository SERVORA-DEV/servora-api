<?php

namespace App\Http\Controllers;

use App\Http\Requests\PushSubscriptionRequest;
use App\Http\Requests\PushUnsubscribeRequest;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Service\Notifications\PushSender;
use Illuminate\Http\Request;

// The signed-in user's browsers and phones that get a pop-up for each new
// notification while Servora isn't open (App\Service\Notifications\PushSender). Any role:
// the web app's staff and administrators register browsers, the client app
// registers phones.
class PushSubscriptionController extends Controller
{
    // What this server can send, so an app only offers what will work.
    public function config(PushSender $sender)
    {
        return response()->json(['data' => [
            'web_public_key' => $sender->webReady() ? config('push.web.public_key') : null,
            'fcm' => $sender->fcmReady(),
        ]]);
    }

    // One row per browser or phone: signing in as someone else there moves
    // it to them, so the last account signed in is the one it pops up for.
    public function store(PushSubscriptionRequest $request)
    {
        $data = $request->validated();
        if ($data['kind'] === PushSubscription::KIND_WEB && ! str_starts_with($data['endpoint'], 'https://')) {
            return response()->json(['message' => 'A browser push address must be https.'], 422);
        }

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'kind' => $data['kind'],
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'] ?? null,
                'auth' => $data['keys']['auth'] ?? null,
                'quiet' => (bool) ($data['quiet'] ?? false),
                'user_agent' => substr((string) $request->userAgent(), 0, 300),
            ],
        );

        return response()->json(['message' => 'Pop-ups are on for this device.']);
    }

    // "Send a test pop-up": a test notification to the caller's own browsers
    // and phones, sent now, with what each push service answered — so a
    // missing server key or a dropped device shows on the screen instead of
    // nothing happening. It lands in their notification list like any other.
    public function test(Request $request, PushSender $sender)
    {
        $user = $request->user();
        $devices = PushSubscription::where('user_id', $user->id)->count();

        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Test pop-up',
            'message' => 'If you can see this with Servora closed or in the background, pop-ups work on this device.',
            'type' => 'System',
        ]);
        $sender->send($notification->id, always: true);
        $results = $sender->results();

        $ok = collect($results)->contains(fn ($line) => str_contains($line, 'accepted'));

        return response()->json(['data' => [
            'sent' => $ok,
            'devices' => $devices,
            'web_ready' => $sender->webReady(),
            'fcm_ready' => $sender->fcmReady(),
            'results' => $results,
        ]]);
    }

    // Turned off, or signing out: only ever the caller's own row.
    public function destroy(PushUnsubscribeRequest $request)
    {
        $data = $request->validated();

        PushSubscription::where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Pop-ups are off for this device.']);
    }
}
