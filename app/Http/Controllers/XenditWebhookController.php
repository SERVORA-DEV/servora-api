<?php

namespace App\Http\Controllers;

use App\Service\SubscriptionService;
use App\Service\XenditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XenditWebhookController extends Controller
{
    private XenditService $xenditService;
    private SubscriptionService $subscriptionService;

    public function __construct(XenditService $xenditService, SubscriptionService $subscriptionService)
    {
        $this->xenditService = $xenditService;
        $this->subscriptionService = $subscriptionService;
    }

    public function handle(Request $request)
    {
        Log::info('xendit.webhook.received', [
            'event' => $request->input('event'),
            'data' => $request->input('data', []),
            'has_callback_token_header' => $request->hasHeader('x-callback-token'),
        ]);

        if (! $this->xenditService->verifyWebhookToken($request)) {
            Log::warning('xendit.webhook.rejected', ['reason' => 'invalid or missing x-callback-token']);
            abort(403, 'Invalid webhook token.');
        }

        $event = $request->input('event');
        $data = $request->input('data', []);

        if ($event === 'payment.capture' && ($data['status'] ?? null) === 'SUCCEEDED') {
            $activated = $this->subscriptionService->activateSubscriptionFromPayment(
                $data['reference_id'],
                $data['payment_request_id'],
                $data['channel_code'] ?? '',
                (float) ($data['request_amount'] ?? 0),
            );

            if ($activated) {
                Log::info('xendit.webhook.activated', ['reference_id' => $data['reference_id'] ?? null]);
            }
        } else {
            Log::info('xendit.webhook.ignored', ['event' => $event, 'status' => $data['status'] ?? null]);
        }

        return response()->json(['message' => 'ok'], 200);
    }
}
