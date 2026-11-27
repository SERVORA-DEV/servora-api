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
        bool $resubmission = false,
    ): void {
        if (! $owner?->hasVerifiedEmail() || blank($owner->email)) {
            return;
        }

        $decision = $approved ? 'Approved' : ($resubmission ? 'Changes Requested' : 'Declined');
        $body = match ($decision) {
            'Approved' => 'A SERVORA administrator approved your submission. Sign in to see the updated status and your next steps.',
            'Changes Requested' => 'A SERVORA administrator asked you to make changes to your submission. Sign in to read the review notes and resubmit.',
            'Declined' => 'A SERVORA administrator declined your submission. Sign in to read the review notes and see how to resubmit.',
        };

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
