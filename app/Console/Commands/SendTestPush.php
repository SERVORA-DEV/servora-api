<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\User;
use App\Service\PushSender;
use Illuminate\Console\Command;

// Pops a test notification up on every browser and phone that has pop-ups on
// for one account, and says what Firebase / the browser's push service
// answered for each — to check pop-ups with the app closed. The test also
// lands in that account's notification list, like any other.
class SendTestPush extends Command
{
    protected $signature = 'push:test
        {account : the account\'s email, username or id}
        {--role= : which of the accounts with that email (client, business_owner, …)}';

    protected $description = 'Send a test notification pop-up to an account\'s browsers and phones';

    public function handle(PushSender $sender): int
    {
        $key = $this->argument('account');
        $user = User::query()
            ->where(fn ($q) => $q->where('email', $key)->orWhere('username', $key)->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key)))
            ->when($this->option('role'), fn ($q, $role) => $q->where('role', $role))
            ->orderBy('id')
            ->get();

        if ($user->isEmpty()) {
            $this->error('No account matches that.');

            return self::FAILURE;
        }
        if ($user->count() > 1) {
            $this->error('More than one account matches ('.$user->pluck('role')->implode(', ').'): add --role=…');

            return self::FAILURE;
        }
        $user = $user->first();

        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Test notification',
            'message' => 'If you can see this with Servora closed, pop-ups work on this device.',
            'type' => 'System',
        ]);

        $this->line("Sending to {$user->email} ({$user->role}, #{$user->id})…");
        $sender->send($notification->id);
        foreach ($sender->results() as $line) {
            $this->line('  '.$line);
        }
        $this->line('"Accepted" means it is on its way; if it still doesn\'t show, the phone or browser is holding it back.');

        return self::SUCCESS;
    }
}
