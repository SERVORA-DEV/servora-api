<?php

namespace App\Service\Notifications;

use App\Mail\AccountNoticeMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RegistrationDecisionEmail
{
    public function afterCommit(
        ?User $owner,
        string $registration,
        string $actionPath,
        bool $approved,
        ?string $recordName = null,
        ?string $nextStep = null,
    ): void {
        if (! $owner?->hasVerifiedEmail() || blank($owner->email)) {
            return;
        }

        $decision = $approved ? 'Approved' : 'Rejected';
        $body = $approved
            ? 'Your submission was approved. Open Servora to see what comes next.'
            : trim(($nextStep ?? '').' Open Servora to see why your submission was rejected and send it again.');

        DB::afterCommit(function () use ($owner, $registration, $decision, $body, $actionPath, $recordName) {
            try {
                Mail::to($owner->email)->send(new AccountNoticeMail(
                    "{$registration} {$decision}", $body, 'View review status', $actionPath, $recordName,
                ));
            } catch (Throwable $error) {
                report($error);
            }
        });
    }
}
