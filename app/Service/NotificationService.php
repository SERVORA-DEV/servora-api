<?php

namespace App\Service;

use App\Models\SpaBranch;
use App\Models\SpaBusiness;
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

    public function ownerVerificationSubmitted(SpaBusiness $business, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'New Owner Verification Submitted',
                "\"{$business->business_name}\" submitted owner identity and business verification for review.",
                'Verification'
            );
        }
    }

    public function ownerIdentityResubmitted(SpaBusiness $business, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'Owner Identity Resubmitted',
                "\"{$business->business_name}\" resubmitted a corrected owner identity document for review.",
                'Verification'
            );
        }
    }

    public function ownerBusinessResubmitted(SpaBusiness $business, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'Business Verification Resubmitted',
                "\"{$business->business_name}\" resubmitted a corrected business registration document for review.",
                'Verification'
            );
        }
    }

    public function ownerIdentityApproved(User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Owner Identity Verified',
            'Your government ID and live face scan have been verified.',
            'Verification'
        );
    }

    public function ownerIdentityRejected(User $owner, string $reason): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Owner Identity Verification Rejected',
            "Your identity verification requires attention: {$reason}",
            'Verification'
        );
    }

    public function ownerBusinessApproved(SpaBusiness $business, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Business Verification Approved',
            "\"{$business->business_name}\" has been verified.",
            'Verification'
        );
    }

    public function ownerBusinessRejected(SpaBusiness $business, User $owner, string $reason): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Business Verification Rejected',
            "\"{$business->business_name}\" verification requires attention: {$reason}",
            'Verification'
        );
    }

    public function ownerVerificationFullyApproved(User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Account Fully Verified',
            'Your owner identity and business verification are both approved. Welcome to your SERVORA dashboard.',
            'Verification'
        );
    }
}
