<?php

namespace App\Console\Commands;

use App\Service\Business\DayCloser;
use Illuminate\Console\Command;

// Scheduled every ten minutes (see bootstrap/app.php's withSchedule): once a
// day has ended, a booking still "booked" becomes a no-show, a visit still in
// service is finished at its estimated end and a checked-in one never served is
// released. The same sweep also runs when a
// front desk or manager's bell asks for news — see DayCloser.
class ClosePastDays extends Command
{
    protected $signature = 'appointments:close-past-days';

    protected $description = 'Close what a finished day left open: no-shows, visits left in service, checked-in visits never served.';

    public function handle(DayCloser $closer): int
    {
        $done = $closer->sweep();
        $this->info("Marked {$done['no_shows']} no-show(s), closed {$done['finished']} visit(s) left in service and released {$done['released']} checked-in visit(s) never served and returned {$done['vouchers']} voucher(s) from visits that were never billed.");

        return self::SUCCESS;
    }
}
