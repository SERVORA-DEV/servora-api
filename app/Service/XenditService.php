<?php

namespace App\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XenditService
{
    // Uses Xendit's Invoice API rather than the Payment Requests API: we
    // don't ask the customer which channel (GCash/Maya/card/etc.) they want
    // before creating the request — Xendit's own hosted invoice page
    // presents every enabled channel and lets the customer pick there.
    public function createInvoice(
        string $externalId,
        float $amount,
        string $description,
        string $successRedirectUrl,
        string $failureRedirectUrl
    ): array {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
            ->post(config('services.xendit.base_url') . '/v2/invoices', [
                'external_id' => $externalId,
                'amount' => $amount,
                'currency' => 'PHP',
                'description' => $description,
                'success_redirect_url' => $successRedirectUrl,
                'failure_redirect_url' => $failureRedirectUrl,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Xendit invoice creation failed: ' . $response->body());
        }

        $body = $response->json();

        return [
            'invoice_id' => $body['id'] ?? null,
            'status' => $body['status'] ?? null,
            'invoice_url' => $body['invoice_url'] ?? null,
        ];
    }

    // Fallback for when the webhook can't reach us (e.g. local dev with no
    // public tunnel) — lets the frontend ask Xendit directly whether an
    // invoice was paid instead of only waiting on the callback.
    public function getInvoiceByExternalId(string $externalId): ?array
    {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
            ->get(config('services.xendit.base_url') . '/v2/invoices', [
                'external_id' => $externalId,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Xendit invoice lookup failed: ' . $response->body());
        }

        return $response->json()[0] ?? null;
    }

    // ── Payment Sessions + Payments API v3 (Servora's own checkout) ───────
    //
    // The checkout page embeds Xendit Components (card / GCash / Maya fields
    // in Xendit-hosted iframes) driven by a Payment Session created here, so
    // card numbers never reach Servora. Saved methods come back as payment
    // tokens, which auto-renewal charges with a v3 payment request.

    private const API_VERSION = '2024-11-11';

    private function http()
    {
        return Http::withBasicAuth(config('services.xendit.secret_key'), '')
            ->baseUrl(config('services.xendit.base_url') ?: 'https://api.xendit.co')
            ->acceptJson()
            ->timeout(30);
    }

    private function v3()
    {
        return $this->http()->withHeaders(['api-version' => self::API_VERSION]);
    }

    // Xendit's own error message, for logs and for the owner when it's safe.
    private function fail(string $what, $response): never
    {
        $body = $response->json();
        throw new XenditRequestException(
            "Xendit {$what} failed: " . ($body['message'] ?? $response->body()),
            $body['error_code'] ?? null,
            $response->status(),
        );
    }

    /** POST /sessions — mode COMPONENTS; see CheckoutService::start. */
    public function createSession(array $payload): array
    {
        $response = $this->http()->post('/sessions', $payload);
        if ($response->failed()) {
            $this->fail('session creation', $response);
        }

        return $response->json();
    }

    public function getSession(string $sessionId): array
    {
        $response = $this->http()->get("/sessions/{$sessionId}");
        if ($response->failed()) {
            $this->fail('session lookup', $response);
        }

        return $response->json();
    }

    public function cancelSession(string $sessionId): void
    {
        $this->http()->post("/sessions/{$sessionId}/cancel");
    }

    /** POST /v3/payment_requests — charging a saved payment token. */
    public function chargeToken(string $referenceId, string $paymentTokenId, float $amount, string $description, bool $isCard): array
    {
        $payload = [
            'reference_id' => $referenceId,
            'type' => 'PAY',
            'country' => 'PH',
            'currency' => 'PHP',
            'request_amount' => $amount,
            'capture_method' => 'AUTOMATIC',
            'payment_token_id' => $paymentTokenId,
            'description' => $description,
        ];
        if ($isCard) {
            // Merchant-initiated renewal on a card the owner saved for it.
            $payload['channel_properties'] = ['skip_three_ds' => true, 'card_on_file_type' => 'RECURRING'];
        }

        $response = $this->v3()->post('/v3/payment_requests', $payload);
        if ($response->failed()) {
            $this->fail('saved-method charge', $response);
        }

        return $response->json();
    }

    public function getPaymentRequest(string $paymentRequestId): array
    {
        $response = $this->v3()->get("/v3/payment_requests/{$paymentRequestId}");
        if ($response->failed()) {
            $this->fail('payment request lookup', $response);
        }

        return $response->json();
    }

    public function getPaymentToken(string $paymentTokenId): array
    {
        $response = $this->v3()->get("/v3/payment_tokens/{$paymentTokenId}");
        if ($response->failed()) {
            $this->fail('payment token lookup', $response);
        }

        return $response->json();
    }

    // Xendit signs every webhook with the account's verification token in the
    // x-callback-token header — this is the only thing standing between "a
    // payment really succeeded" and "anyone can POST a fake success event".
    public function verifyWebhookToken(Request $request): bool
    {
        $expected = config('services.xendit.webhook_token');
        $received = $request->header('x-callback-token');

        return $expected && $received && hash_equals($expected, $received);
    }
}
