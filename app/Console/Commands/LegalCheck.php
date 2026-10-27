<?php

namespace App\Console\Commands;

use App\Support\LegalDocuments;
use Illuminate\Console\Command;

// Fails while the Terms or Privacy Policy still show a [placeholder] to
// readers. Run it before a deploy; it names the .env values to set.
class LegalCheck extends Command
{
    protected $signature = 'legal:check';

    protected $description = 'Fail if the Terms or Privacy Policy still contain unfilled [placeholders]';

    public function handle(): int
    {
        $unresolved = LegalDocuments::unresolved();

        if ($unresolved === []) {
            $this->info('The Terms of Service and Privacy Policy have no unfilled placeholders.');

            return self::SUCCESS;
        }

        $this->error('Readers would see these placeholders:');
        foreach ($unresolved as $line) {
            $this->line("  {$line}");
        }
        $this->newLine();
        $this->line('Set LEGAL_OPERATOR_NAME, LEGAL_OPERATOR_ADDRESS, LEGAL_CONTACT_EMAIL, LEGAL_DPO_NAME and LEGAL_VENUE_CITY in .env.');

        return self::FAILURE;
    }
}
