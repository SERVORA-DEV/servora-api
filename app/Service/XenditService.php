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
