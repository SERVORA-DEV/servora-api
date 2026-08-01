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

    // Xendit's Invoice callback isn't wrapped in an {event, data} envelope
    // like the Payment Requests API — it POSTs the invoice object itself
    // (id, external_id, status, paid_amount, payment_method,
    // payment_channel, ...) directly at the top level.
    public function handle(Request $request)
    {
        Log::info('xendit.webhook.received', [
            'external_id' => $request->input('external_id'),
            'status' => $request->input('status'),
            'has_callback_token_header' => $request->hasHeader('x-callback-token'),
        ]);

        if (! $this->xenditService->verifyWebhookToken($request)) {
            Log::warning('xendit.webhook.rejected', ['reason' => 'invalid or missing x-callback-token']);
            abort(403, 'Invalid webhook token.');
        }

        $status = $request->input('status');
        $externalId = $request->input('external_id');

        if ($status === 'PAID') {
            $activated = $this->subscriptionService->activateSubscriptionFromPayment(
                $externalId,
                $request->input('id'),
                $request->input('payment_method'),
                $request->input('payment_channel'),
                (float) ($request->input('paid_amount') ?? 0),
            );

            if ($activated) {
                Log::info('xendit.webhook.activated', ['reference_id' => $externalId]);
            }
        } else {
            Log::info('xendit.webhook.ignored', ['external_id' => $externalId, 'status' => $status]);
        }

        return response()->json(['message' => 'ok'], 200);
    }
}
