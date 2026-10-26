<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

// Prints a key pair for browser pop-ups (config/push.php → web). Make it once
// per server and keep it: browsers that turned pop-ups on are tied to the
// public key, and a new pair means each has to turn them on again.
class MakeVapidKeys extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Make a VAPID key pair for browser pop-ups (put it in .env)';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->error('Could not make the keys: '.$e->getMessage());
            $this->line('On Windows XAMPP, set OPENSSL_CONF to C:\\xampp\\php\\extras\\ssl\\openssl.cnf and run it again.');

            return self::FAILURE;
        }

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
