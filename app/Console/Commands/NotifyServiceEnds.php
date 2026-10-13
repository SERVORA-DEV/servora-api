<?php

namespace App\Console\Commands;

use App\Service\Business\ServiceEndNotifier;
use Illuminate\Console\Command;

// Scheduled every minute (see bootstrap/app.php's withSchedule): tells each
// branch's front desk and manager when a service in progress has run its
// time. The same check also runs when their bell asks for news — see
// ServiceEndNotifier for why there are two ways in, and why neither repeats.
class NotifyServiceEnds extends Command
{
    protected $signature = 'appointments:notify-service-ends';

    protected $description = 'Notify front desk and managers when a service in progress has run its time.';

    public function handle(ServiceEndNotifier $notifier): int
    {
        $sent = $notifier->sweep();
        $this->info("Sent {$sent} service-end notice(s).");

        return self::SUCCESS;
    }
}
