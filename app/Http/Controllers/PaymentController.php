<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function testXendit()
    {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )->get('https://api.xendit.co/balance', [
            'account_type' => 'CASH',
            'currency' => 'PHP'
        ]);

        return response()->json([
            'status' => $response->status(),
            'body' => $response->json(),
        ]);
    }

    public function createPaymentRequest()
    {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
        ->withHeaders([
            'api-version' => '2024-11-11',
        ])
        ->post(
            config('services.xendit.base_url') . '/v3/payment_requests',
            [
                "reference_id" => "SERVORA-" . time(),
                "type" => "PAY",
                "country" => "PH",
                "currency" => "PHP",
                "request_amount" => 499,

                // <-- ROOT LEVEL
                "channel_code" => "GCASH",

                // <-- ROOT LEVEL
                "channel_properties" => [
                    "success_return_url" => "http://localhost:3000/payment/success",
                    "failure_return_url" => "http://localhost:3000/payment/failed",
                ]
            ]
        );

        return response()->json([
            'status' => $response->status(),
            'body' => $response->json(),
        ]);
    }

    public function returnSuccess()
    {
        return ['message' => 'success'];
    }

    public function returnFailed()
    {
        return (['message' => 'failed']);
    }
}
