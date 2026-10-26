<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Service\PushSender;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// The signed-in user's browsers and phones that get a pop-up for each new
// notification while Servora isn't open (App\Service\PushSender). Any role:
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
    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([PushSubscription::KIND_WEB, PushSubscription::KIND_FCM])],
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required_if:kind,web', 'nullable', 'string', 'max:200'],
            'keys.auth' => ['required_if:kind,web', 'nullable', 'string', 'max:100'],
            // The device's Notification sound setting, re-sent when it changes.
            'quiet' => ['sometimes', 'boolean'],
        ]);
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

    // Turned off, or signing out: only ever the caller's own row.
    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Pop-ups are off for this device.']);
    }
}
