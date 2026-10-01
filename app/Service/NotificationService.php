<?php

namespace App\Service;

use App\Http\Resources\NotificationResource;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\System\AdminUsersRepository;
use App\Service\Business\StaffNotifier;

// In-app only (writes to the app's own `notifications` table) — no email.
// See NotificationRepository/App\Models\Notification for the schema.
class NotificationService
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private AdminUsersRepository $adminUsersRepository,
        private AuditLogRepository $auditLogRepository,
        private StaffNotifier $staffNotifier,
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

    // One of the caller's own notifications (the full-page detail view) —
    // another user's id is a 404, never their row.
    public function showForUser(User $user, int $id)
    {
        $notification = $this->notificationRepository->findForUser($user->id, $id);
        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return new NotificationResource($notification);
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

    public function delete(User $user, int $id)
    {
        if (! $this->notificationRepository->deleteForUser($user->id, $id)) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json(['message' => 'Notification deleted.'], 200);
    }

    public function clearRead(User $user)
    {
        $deleted = $this->notificationRepository->deleteReadForUser($user->id);

        return response()->json([
            'message' => 'Read notifications cleared.',
            'deleted' => $deleted,
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

        $this->auditLogRepository->record($actor->id, 'announcements', null, 'Create', null, [
            'name' => $payload['title'],
            'recipients' => $recipients->count(),
        ], request());

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

    // A verified branch uploaded a renewed permit (Branch Settings →
    // Registration & Permits). The branch stays listed; admins can review
    // the new document.
    public function branchPermitRenewed(SpaBranch $branch, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'Branch Permit Renewed',
                "\"{$branch->branch_name}\" uploaded a renewed permit (expires {$branch->permit_expiration_date?->toDateString()}).",
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

        foreach ($business->branches as $branch) {
            $this->staffNotifier->branch($branch, 'Business Suspended', "\"{$business->business_name}\" has been suspended by Servora. Contact your spa owner for details.", includeOwner: false, critical: true);
        }
    }

    public function businessReactivated(SpaBusiness $business, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Business Reactivated',
            "\"{$business->business_name}\" has been reactivated and is no longer suspended.",
            'System'
        );

        foreach ($business->branches as $branch) {
            $this->staffNotifier->branch($branch, 'Business Reactivated', "\"{$business->business_name}\" is active again.", includeOwner: false, critical: true);
        }
    }

    public function branchSuspended(SpaBranch $branch, User $owner, string $reason): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Suspended',
            "\"{$branch->branch_name}\" has been suspended: {$reason}",
            'System'
        );

        $this->staffNotifier->branch($branch, 'Branch Suspended', "\"{$branch->branch_name}\" has been suspended by Servora. Contact your spa owner for details.", includeOwner: false, critical: true);
    }

    public function branchReactivated(SpaBranch $branch, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Reactivated',
            "\"{$branch->branch_name}\" has been reactivated and is no longer suspended.",
            'System'
        );

        $this->staffNotifier->branch($branch, 'Branch Reactivated', "\"{$branch->branch_name}\" is active again.", includeOwner: false, critical: true);
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

    // Fired from NotifyAlmostDueSubscriptions (the daily scheduled command —
    // see Console/Commands) for a subscription within the renewal reminder
    // window. Before expires_at it's a "renews in N days" reminder; after it
    // (repeat reminders continue through the grace period) it's an overdue
    // notice with how long access lasts.
    public function subscriptionAlmostDue(Subscription $subscription, int $graceDays): void
    {
        $planName = $subscription->plan->name ?? 'subscription';

        if ($subscription->expires_at->isPast()) {
            // diffInDays returns a float (fractional days) — round for
            // whole-day display.
            $daysLate = (int) round($subscription->expires_at->diffInDays(now()));
            $daysLeft = max(0, $graceDays - $daysLate);
            $until = $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'within 1 day' : "within {$daysLeft} days");

            $title = 'Subscription Payment Overdue';
            $message = "Your {$planName} plan expired on {$subscription->expires_at->format('F j, Y')}. Renew {$until} to keep access.";
        } else {
            $daysLeft = max(0, (int) round(now()->diffInDays($subscription->expires_at, false)));
            $when = $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'in 1 day' : "in {$daysLeft} days");

            $title = 'Subscription Renewal Reminder';
            $method = $subscription->auto_renew ? $subscription->paymentMethod?->label : null;
            $message = $method
                ? "Your {$planName} plan renews automatically {$when} — we'll charge {$method}."
                : "Your {$planName} plan renews {$when}. Renew soon to avoid losing access.";
        }

        // Admin changed the plan and the owner hasn't declined — the
        // renewal will be on the new version (see PlanChangeService).
        if ($subscription->pendingPlan && in_array($subscription->plan_change_status, ['pending', 'accepted'], true)) {
            $price = $subscription->billing_cycle === 'Yearly'
                ? $subscription->pendingPlan->yearly_price
                : $subscription->pendingPlan->monthly_price;
            $per = $subscription->billing_cycle === 'Yearly' ? 'year' : 'month';

            $message .= " Your renewal will use the updated {$subscription->pendingPlan->name} plan"
                . ($price !== null ? ' (₱' . number_format((float) $price, 2) . "/{$per})." : '.');
        }

        // Owner's own downgrade / cycle switch (PlanSwitchService) — takes
        // precedence over the above for what the renewal will be.
        if ($subscription->scheduledPlan) {
            $message .= " Your renewal will be on the {$subscription->scheduledPlan->name} plan"
                . ($subscription->scheduled_billing_cycle ? " ({$subscription->scheduled_billing_cycle})" : '') . ', as you chose.';
        }

        $this->notificationRepository->create($subscription->business->owner->id, $title, $message, 'Subscription');
    }

    // Fired from PlanChangeService::notifySubscribers — the admin edited the
    // plan this owner is subscribed to (creating a new version). Their
    // current terms stay until expires_at; they're asked to accept the new
    // version for renewal or let the subscription end.
    public function subscriptionPlanChanged(Subscription $subscription, SubscriptionPlan $newPlan, array $changes): void
    {
        $count = count($changes);
        $summary = $count > 0 ? ' (' . $count . ' ' . ($count === 1 ? 'change' : 'changes') . ')' : '';
        $until = $subscription->expires_at?->format('F j, Y');

        $this->notificationRepository->create(
            $subscription->business->owner->id,
            'Your Plan Has Been Updated',
            "The {$newPlan->category} plan you're subscribed to has been updated{$summary}. "
                . ($until ? "Your current plan stays the same until {$until}. " : '')
                . 'Open your dashboard to accept the updated plan for your renewal, or cancel at the end of your term.',
            'Subscription'
        );
    }

    // Fired from PlanChangeService::respond — tells the admins how an owner
    // answered the prompt above.
    public function planChangeResponded(Subscription $subscription, string $decision, iterable $admins): void
    {
        $businessName = $subscription->business->business_name ?? 'A business';
        $planName = $subscription->pendingPlan->name ?? 'updated';
        $until = $subscription->expires_at?->format('F j, Y');

        $message = $decision === 'accept'
            ? "\"{$businessName}\" accepted the updated {$planName} plan for their next renewal."
            : "\"{$businessName}\" declined the updated {$planName} plan — their subscription ends" . ($until ? " on {$until}." : '.');

        foreach ($admins as $admin) {
            $this->notificationRepository->create($admin->id, 'Plan Change Response', $message, 'Subscription');
        }
    }

    // Fired from PlanSwitchService — an owner upgraded (paid, effective now),
    // scheduled a downgrade for their next billing, or took one back. Admins
    // get the fact; on an upgrade the owner gets a confirmation too.
    public function planSwitched(Subscription $subscription, string $event, string $fromPlan, string $toPlan, iterable $admins): void
    {
        $businessName = $subscription->business->business_name ?? 'A business';
        $on = $subscription->expires_at?->format('F j, Y');

        [$title, $message] = match ($event) {
            'upgraded' => ['Plan Upgraded', "\"{$businessName}\" upgraded from {$fromPlan} to {$toPlan}."],
            'downgrade_scheduled' => ['Downgrade Scheduled', "\"{$businessName}\" will move from {$fromPlan} to {$toPlan}" . ($on ? " when their term ends on {$on}." : ' at renewal.')],
            default => ['Downgrade Withdrawn', "\"{$businessName}\" is staying on {$fromPlan} instead of moving to {$toPlan}."],
        };

        foreach ($admins as $admin) {
            $this->notificationRepository->create($admin->id, $title, $message, 'Subscription');
        }

        if ($event === 'upgraded' && $subscription->business?->owner) {
            $this->notificationRepository->create(
                $subscription->business->owner->id,
                'Plan Upgraded',
                "You're now on the {$toPlan} plan." . ($on ? " Your renewal date stays {$on}." : ''),
                'Subscription'
            );
        }
    }

    // Fired from CheckoutService once an automatic renewal charge succeeds.
    public function subscriptionAutoRenewed(Subscription $subscription, float $amount): void
    {
        $subscription->loadMissing(['plan', 'business.owner', 'paymentMethod']);
        if (! $subscription->business?->owner) {
            return;
        }

        $method = $subscription->paymentMethod?->label;
        $this->notificationRepository->create(
            $subscription->business->owner->id,
            'Subscription Renewed',
            "Your {$subscription->plan?->name} plan renewed until {$subscription->expires_at?->format('F j, Y')}. "
                . '₱' . number_format($amount, 2) . ' was charged' . ($method ? " to {$method}" : '') . '.',
            'Subscription'
        );
    }

    // Fired from SubscriptionRenewalService when an automatic renewal can't
    // be charged — the owner fixes it (new method / smaller usage) or renews
    // by hand; the job retries daily through the grace period.
    public function subscriptionRenewalFailed(Subscription $subscription, string $reason): void
    {
        if (! $subscription->business?->owner) {
            return;
        }

        $this->notificationRepository->create(
            $subscription->business->owner->id,
            'Auto-Renewal Failed',
            "We couldn't renew your plan automatically. {$reason}"
                . ($subscription->expires_at ? ' Your plan ends ' . $subscription->expires_at->format('F j, Y') . ' — renew before then to keep access.' : ''),
            'Subscription'
        );
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
