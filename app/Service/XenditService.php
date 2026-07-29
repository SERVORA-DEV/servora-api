<?php

namespace App\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XenditService
{
    public function createPaymentRequest(
        string $referenceId,
        float $amount,
        string $channelCode,
        string $successReturnUrl,
        string $failureReturnUrl
    ): array {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
            ->withHeaders(['api-version' => '2024-11-11'])
            ->post(config('services.xendit.base_url') . '/v3/payment_requests', [
                'reference_id' => $referenceId,
                'type' => 'PAY',
                'country' => 'PH',
                'currency' => 'PHP',
                'request_amount' => $amount,
                'channel_code' => $channelCode,
                'channel_properties' => [
                    'success_return_url' => $successReturnUrl,
                    'failure_return_url' => $failureReturnUrl,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Xendit payment request failed: ' . $response->body());
        }

        $body = $response->json();

        $paymentUrl = collect($body['actions'] ?? [])
            ->firstWhere('descriptor', 'WEB_URL')['value'] ?? null;

        return [
            'payment_request_id' => $body['payment_request_id'] ?? null,
            'status' => $body['status'] ?? null,
            'payment_url' => $paymentUrl,
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
