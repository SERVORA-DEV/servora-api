<?php

namespace App\Service;

use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\NotificationRepository;

// In-app only (writes to the app's own `notifications` table) — no email.
// See NotificationRepository/App\Models\Notification for the schema.
class NotificationService
{
    public function __construct(private NotificationRepository $notificationRepository) {}

    public function branchRegistrationSubmitted(SpaBranch $branch, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'New Branch Registration',
                "\"{$branch->branch_name}\" submitted a registration request for review.",
                'System'
            );
        }
    }

    public function branchRegistrationApproved(SpaBranch $branch, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Registration Approved',
            "\"{$branch->branch_name}\" has been approved and is now bookable.",
            'System'
        );
    }

    public function branchRegistrationRejected(SpaBranch $branch, User $owner, string $reason): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Registration Rejected',
            "\"{$branch->branch_name}\" was rejected: {$reason}",
            'System'
        );
    }
}
