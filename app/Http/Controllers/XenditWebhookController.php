<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Service\CheckoutService;
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
        // Verify first: nothing from an unauthenticated caller is logged, so
        // the endpoint can't be used to flood or poison the logs.
        if (! $this->xenditService->verifyWebhookToken($request)) {
            Log::warning('xendit.webhook.rejected', ['reason' => 'invalid or missing x-callback-token']);
            abort(403, 'Invalid webhook token.');
        }

        // Only the identifying fields at info level (payloads can carry
        // payment PII). The full raw payload is at debug level: if Xendit
        // ever changes the callback shape (e.g. back to the Payment Requests
        // {event, data} envelope), external_id/status silently read as null
        // and the payment is "ignored", and the raw payload is what makes
        // that visible when debugging.
        Log::info('xendit.webhook.received', [
            'external_id' => $request->input('external_id'),
            'status' => $request->input('status'),
        ]);
        Log::debug('xendit.webhook.payload', ['payload' => $request->all()]);

        // Servora's own checkout (Payment Sessions / Payments API v3) sends
        // {event, data} envelopes; the older hosted-invoice callbacks below
        // are flat. Both are matched to what was being paid for by reference.
        if ($request->filled('event')) {
            $this->handleCheckoutEvent($request->input('event'), (array) $request->input('data', []));

            return response()->json(['message' => 'ok'], 200);
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

    private function handleCheckoutEvent(string $event, array $data): void
    {
        $reference = $data['reference_id'] ?? null;
        $session = $reference ? CheckoutSession::where('reference_id', $reference)->first() : null;
        if (! $session) {
            Log::info('xendit.webhook.unknown_checkout', ['event' => $event, 'reference_id' => $reference]);

            return;
        }

        $checkout = app(CheckoutService::class);
        match ($event) {
            'payment_session.completed' => $checkout->complete($session, $data),
            'payment_session.expired' => $session->isFinal() || $session->update(['status' => 'expired', 'failure_reason' => "The payment wasn't finished in time."]),
            // A saved-method charge (checkout "Saved" or auto-renewal):
            // re-read the payment request so the amount and status come from
            // Xendit, not the webhook body.
            'payment.capture', 'payment.succeeded', 'payment.failure', 'payment.failed' => $checkout->refresh($session),
            default => Log::info('xendit.webhook.ignored_event', ['event' => $event]),
        };
    }
}
