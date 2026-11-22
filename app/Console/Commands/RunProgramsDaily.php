<?php

namespace App\Console\Commands;

use App\Service\Business\ProgramRewardService;
use Illuminate\Console\Command;

class RunProgramsDaily extends Command
{
    protected $signature = 'programs:daily';

    protected $description = 'Customer programs upkeep: expire memberships, vouchers and points past their date, hand out birthday-month vouchers, and give members the perks since added to their membership.';

    public function handle(ProgramRewardService $rewards): int
    {
        $counts = $rewards->runDaily();

        $this->info(sprintf(
            'Expired %d membership(s), %d voucher(s), %d point(s); issued %d birthday voucher(s); %d member(s) got new perks.',
            $counts['memberships'], $counts['vouchers'], $counts['points'], $counts['birthday'], $counts['member_perks'],
        ));

        return self::SUCCESS;
    }
}
