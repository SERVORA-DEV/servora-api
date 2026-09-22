<?php

namespace App\Service;

use App\Http\Resources\NotificationResource;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repository\NotificationRepository;
use App\Repository\System\AdminUsersRepository;

// In-app only (writes to the app's own `notifications` table) — no email.
// See NotificationRepository/App\Models\Notification for the schema.
class NotificationService
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    // ── Read side: the signed-in user's own feed (Settings > Notifications /
    // the TopBar bell) ───────────────────────────────────────────────────
    public function listForUser(User $user, int $perPage = 15, ?string $type = null)
    {
        $paginator = $this->notificationRepository->paginateForUser($user->id, $perPage, $type);
        $unreadCount = $this->notificationRepository->countUnreadForUser($user->id);

        return NotificationResource::collection($paginator)->additional([
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(User $user, int $id)
    {
        if (! $this->notificationRepository->markReadForUser($user->id, $id)) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json(['message' => 'Notification marked as read.'], 200);
    }

    public function markAllRead(User $user)
    {
        $updated = $this->notificationRepository->markAllReadForUser($user->id);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated' => $updated,
        ], 200);
    }

    // Dashboard Quick Actions > Send Announcement. The only real, working
    // notification inbox in the app today is this admin one (the owner-side
    // useOwnerNotifications.ts has no backend route and is never actually
    // wired into business/TopBar.vue), so that's the real audience — every
    // OTHER system administrator, same "not the actor" rule as
    // administratorCreated.
    public function sendAnnouncement(User $actor, array $payload)
    {
        $senderName = trim("{$actor->first_name} {$actor->last_name}") ?: $actor->email;
        $message = "{$payload['message']}\n\n— {$senderName}";

        $recipients = $this->adminUsersRepository->allAdministrators()
            ->reject(fn (User $admin) => $admin->id === $actor->id);

        foreach ($recipients as $admin) {
            $this->notificationRepository->create($admin->id, $payload['title'], $message, 'System');
        }

        return response()->json([
            'message' => 'Announcement sent.',
            'recipients' => $recipients->count(),
        ], 200);
    }

    // ── Write side: fired from the real actions below as they happen ────

    public function branchRegistrationSubmitted(SpaBranch $branch, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'New Branch Registration',
                "\"{$branch->branch_name}\" submitted a registration request for review.",
                'Registration'
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

    public function businessSuspended(SpaBusiness $business, User $owner, string $reason): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Business Suspended',
            "\"{$business->business_name}\" has been suspended: {$reason}",
            'System'
        );
    }

    public function businessReactivated(SpaBusiness $business, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Business Reactivated',
            "\"{$business->business_name}\" has been reactivated and is no longer suspended.",
            'System'
        );
    }

    public function branchSuspended(SpaBranch $branch, User $owner, string $reason): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Suspended',
            "\"{$branch->branch_name}\" has been suspended: {$reason}",
            'System'
        );
    }

    public function branchReactivated(SpaBranch $branch, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Reactivated',
            "\"{$branch->branch_name}\" has been reactivated and is no longer suspended.",
            'System'
        );
    }

    // Fired from AdminUsersService::createAdminUsers — notifies every OTHER
    // administrator (the actor already knows; see the ->reject() there).
    public function administratorCreated(User $newAdmin, iterable $admins): void
    {
        $name = trim("{$newAdmin->first_name} {$newAdmin->last_name}") ?: $newAdmin->email;

        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'New Administrator Added',
                "{$name} was added as a system administrator.",
                'System'
            );
        }
    }

    // Fired from SubscriptionService::activateSubscriptionFromPayment — a
    // webhook-confirmed Xendit payment activating a business's subscription.
    // Reports the same real event as two separate facts, matching the two
    // admin-facing categories: a subscription started, and money moved.
    public function subscriptionActivated(SpaBusiness $business, SubscriptionPlan $plan, string $billingCycle, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'New Subscription',
                "\"{$business->business_name}\" subscribed to the {$plan->name} plan ({$billingCycle}).",
                'Subscription'
            );
        }
    }

    public function paymentReceived(SpaBusiness $business, float $amount, iterable $admins): void
    {
        $formatted = number_format($amount, 2);

        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'Payment Received',
                "Received ₱{$formatted} from \"{$business->business_name}\".",
                'Payment'
            );
        }
    }
}
