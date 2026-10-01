<?php

namespace App\Console\Commands;

use App\Service\SubscriptionRenewalService;
use Illuminate\Console\Command;

// Scheduled hourly (bootstrap/app.php) — charges the saved payment method of
// every subscription with auto-renew on that's due, and settles charges
// still processing from an earlier run. See SubscriptionRenewalService.
// In dev, run it directly: `php artisan subscriptions:auto-renew`.
class AutoRenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:auto-renew';
    protected $description = 'Charge saved payment methods for subscriptions due for automatic renewal';

    public function handle(SubscriptionRenewalService $renewals): int
    {
        $stats = $renewals->run();
        $this->info("Auto-renew: {$stats['attempted']} due, {$stats['renewed']} renewed, {$stats['failed']} failed.");

        return self::SUCCESS;
    }
}
