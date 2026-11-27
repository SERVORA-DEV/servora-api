<?php

namespace App\Service\Subscription;

use App\Enums\CheckoutStatus;
use App\Mail\AccountNoticeMail;
use App\Models\CheckoutSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SubscriptionRenewalEmail
{
    public function retryPending(): void
    {
        foreach ($this->pending()->select('id')->lazyById(100) as $checkout) {
            $this->sendPending($checkout->id);
        }
    }

    public function sendPending(int $checkoutId): void
    {
        try {
            Cache::store('durable')->lock("subscription-renewal-email:{$checkoutId}", 600)->get(function () use ($checkoutId) {
                $checkout = $this->pending()->with('business.owner')->find($checkoutId);
                $address = trim((string) $checkout?->business?->owner?->email);
                if ($address === '') {
                    return;
                }

                $message = $checkout->pending_renewal_email;
                Mail::to($address)->send(new AccountNoticeMail(
                    $message['heading'],
                    $message['body'],
                    'View my subscription',
                    '/business/subscription',
                ));

                $checkout->forceFill(['pending_renewal_email' => null])->saveQuietly();
            });
        } catch (Throwable $error) {
            Log::warning('Subscription renewal confirmation email is pending.', [
                'checkout_id' => $checkoutId,
                'exception' => $error::class,
            ]);
        }
    }

    private function pending(): Builder
    {
        return CheckoutSession::where('purpose', 'auto_renew')
            ->where('status', CheckoutStatus::Completed->value)
            ->whereNotNull('pending_renewal_email');
    }
}
