<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ServoraStart extends Command
{
    protected $signature = 'servora:start';
    protected $description = 'Start Apache, MySQL, and Laravel';

    public function handle(): int
    {

        $xampp = 'C:\\xampp\\xampp-control.exe';

        if (file_exists($xampp)) {
            pclose(popen('start "" "' . $xampp . '"', 'r'));
            
            $this->info('Starting Apache...');
            exec('cmd /c "C:\xampp\apache_start.bat"');

            return self::SUCCESS;
        } else {
            return self::FAILURE;
        }

        
    }
}