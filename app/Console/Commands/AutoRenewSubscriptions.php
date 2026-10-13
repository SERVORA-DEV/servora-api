<?php

namespace App\Console\Commands;

use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\User;
use App\Service\CheckoutService;
use App\Service\SubscriptionRenewalService;
use App\Service\XenditService;
use Illuminate\Console\Command;

// Scheduled hourly (bootstrap/app.php) — charges the saved payment method of
// every subscription with auto-renew on that's due, and settles charges
// still processing from an earlier run. See SubscriptionRenewalService.
// In dev, run it directly: `php artisan subscriptions:auto-renew`.
//
// To SEE an automatic payment without waiting for the due date (demo, Xendit
// test key only): `php artisan subscriptions:auto-renew --demo=owner@email`.
// It renews that owner's plan now, exactly as the hourly run would on the due
// day: the saved (test) payment method is charged through Xendit's test mode
// and the next term starts.
class AutoRenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:auto-renew
        {--demo= : Demo, Xendit test key only: renew this owner\'s plan now, whatever its due date (the owner\'s email)}';
    protected $description = 'Charge saved payment methods for subscriptions due for automatic renewal';

    public function handle(SubscriptionRenewalService $renewals, XenditService $xendit, CheckoutService $checkout): int
    {
        if ($email = $this->option('demo')) {
            return $this->demo((string) $email, $renewals, $xendit, $checkout);
        }

        $stats = $renewals->run();
        $this->info("Auto-renew: {$stats['attempted']} due, {$stats['renewed']} renewed, {$stats['failed']} failed.");

        return self::SUCCESS;
    }

    private function demo(string $email, SubscriptionRenewalService $renewals, XenditService $xendit, CheckoutService $checkout): int
    {
        if (! $xendit->isTestMode()) {
            $this->error('--demo only runs with a Xendit TEST key (xnd_development_...). Nothing was charged.');

            return self::FAILURE;
        }

        $owner = User::where('email', $email)->where('role', 'business_owner')->first();
        $business = $owner ? SpaBusiness::where('owner_id', $owner->id)->first() : null;
        $subscription = $business
            ? Subscription::with(['plan', 'scheduledPlan', 'pendingPlan', 'paymentMethod', 'business.owner'])
                ->where('spa_business_id', $business->id)->where('status', 'Active')->orderByDesc('id')->first()
            : null;
        if (! $subscription) {
            $this->error("No business owner with an active plan was found for {$email}.");

            return self::FAILURE;
        }
        if (! $subscription->auto_renew || ! $subscription->paymentMethod) {
            $this->error('Auto-renew is off for this plan, or it has no saved payment method. Save one on the checkout page (with the test card) and switch auto-renew on in My Subscription first.');

            return self::FAILURE;
        }

        $this->line("Demo: renewing {$business->business_name}'s {$subscription->plan?->name} plan now with {$subscription->paymentMethod->label} (Xendit test mode, no real money).");
        $session = $renewals->attempt($subscription);
        if (! $session) {
            $this->error('The renewal could not be started. The owner has been told why in their notifications.');

            return self::FAILURE;
        }

        // A test charge usually settles at once; give a slow one a moment.
        for ($i = 0; $i < 5 && $session->fresh()->status === 'pending'; $i++) {
            sleep(3);
            $checkout->refresh($session->fresh());
        }

        $session = $session->fresh();
        match ($session->status) {
            'completed' => $this->info('Renewed. The new term, the receipt and the owner\'s notification are in place.'),
            'pending' => $this->warn('The test charge is still processing. Run `php artisan subscriptions:auto-renew` in a couple of minutes to settle it.'),
            default => $this->error('The test charge failed: ' . ($session->failure_reason ?? 'no reason given') . '.'),
        };

        return $session->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
