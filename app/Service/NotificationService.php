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
use App\Support\SubscriptionReminderSchedule;

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
    //
    // What an administrator is told carries the uuid of what it is about
    // (the branch, the business, the new administrator), so a click on it
    // opens that record and not just the list it is in — see
    // notificationDestination() in the web app's types/system-notification.ts.

    public function branchRegistrationSubmitted(SpaBranch $branch, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'New Branch Registration',
                "\"{$branch->branch_name}\" submitted a registration request for review.",
                'Registration',
                $branch->uuid,
            );
        }
    }

    // ── Staff schedule changes (StaffScheduleService) ───────────────────

    // The reference is the staff member, so a click opens their page.
    public function scheduleChangeRequested(User $owner, string $staffName, string $managerName, ?string $staffUuid = null): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Schedule Change Needs Approval',
            "{$managerName} changed {$staffName}'s weekly schedule. Open their page in Employees to approve or reject it.",
            'Attendance',
            $staffUuid,
        );
    }

    public function scheduleChangeDecided(User $manager, string $staffName, bool $approved, ?string $reason, ?string $staffUuid = null): void
    {
        $this->notificationRepository->create(
            $manager->id,
            $approved ? 'Schedule Change Approved' : 'Schedule Change Rejected',
            $approved
                ? "Your change to {$staffName}'s weekly schedule was approved and is now in effect."
                : "Your change to {$staffName}'s weekly schedule was rejected." . ($reason ? " Reason: {$reason}" : ''),
            'Attendance',
            $staffUuid,
        );
    }

    // ── Moving a staff member to another branch (StaffTransferService) ──
    // The reference is the staff member, so a click opens their page.

    public function staffTransferRequested(User $owner, string $staffName, string $managerName, ?string $fromBranch, string $toBranch, string $staffUuid): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Staff Transfer Request',
            "{$managerName} asked to move {$staffName}" . ($fromBranch ? " from {$fromBranch}" : '') . " to {$toBranch}. Open their page in Employees to confirm or decline it.",
            'System',
            $staffUuid
        );
    }

    public function staffTransferDecided(User $manager, string $staffName, string $toBranch, bool $approved, ?string $reason, string $staffUuid): void
    {
        $this->notificationRepository->create(
            $manager->id,
            $approved ? 'Staff Transfer Approved' : 'Staff Transfer Declined',
            $approved
                ? "{$staffName} was moved to {$toBranch}."
                : "Your request to move {$staffName} to {$toBranch} was declined." . ($reason ? " Reason: {$reason}" : ''),
            'System',
            // Once moved they are no longer this manager's to open.
            $approved ? null : $staffUuid
        );
    }

    // ── A manager asking for a new service or package (CatalogRequestService) ──
    // The titles are what the web app looks for to send a click on one of
    // these to Services & Packages (see staffNotificationDestination).

    public function catalogRequested(User $owner, string $kind, string $name, string $managerName, ?string $branchName): void
    {
        $from = $branchName ? "{$managerName} at {$branchName}" : $managerName;

        $this->notificationRepository->create(
            $owner->id,
            'New ' . ($kind === 'package' ? 'Package' : ($kind === 'addon' ? 'Add-on' : 'Service')) . ' Request',
            "{$from} asked for a new " . ($kind === 'addon' ? 'add-on' : $kind) . ": \"{$name}\". Open Requests to read it and add it or decline.",
            'System'
        );
    }

    public function catalogRequestDecided(User $manager, string $kind, string $name, bool $added, ?string $reason, ?string $branchName): void
    {
        $label = $kind === 'package' ? 'Package' : ($kind === 'addon' ? 'Add-on' : 'Service');
        $where = $branchName ?: 'your branch';

        $this->notificationRepository->create(
            $manager->id,
            $added ? "{$label} Request Added" : "{$label} Request Declined",
            $added
                ? "\"{$name}\" is now in the catalog. Open Services & Packages to set it up for {$where}."
                : "The owner declined your request for \"{$name}\"." . ($reason ? " Reason: {$reason}" : ''),
            'System'
        );
    }

    // A manager asking for a new voucher, discount or membership for their
    // branch (ProgramRequestService).
    public function programRequested(User $owner, string $type, string $name, string $managerName, ?string $branchName): void
    {
        $from = $branchName ? "{$managerName} at {$branchName}" : $managerName;

        $this->notificationRepository->create(
            $owner->id,
            'New ' . ucfirst($type) . ' Request',
            "{$from} asked for a new {$type}: \"{$name}\". Open Programs to approve it or decline.",
            'System'
        );
    }

    public function programRequestDecided(User $manager, string $type, string $name, bool $approved, ?string $reason, ?string $branchName): void
    {
        $where = $branchName ?: 'your branch';

        $this->notificationRepository->create(
            $manager->id,
            ucfirst($type) . ' Request ' . ($approved ? 'Approved' : 'Declined'),
            $approved
                ? "\"{$name}\" is now offered at {$where}. Open Programs to see it."
                : "The owner declined your request for \"{$name}\"." . ($reason ? " Reason: {$reason}" : ''),
            'System'
        );
    }

    public function branchRegistrationApproved(SpaBranch $branch, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Registration Approved',
            "\"{$branch->branch_name}\" has been approved and is now bookable.",
            'System',
            $branch->uuid,
        );
    }

    public function branchRegistrationRejected(SpaBranch $branch, User $owner, string $reason): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Registration Rejected',
            "\"{$branch->branch_name}\" was rejected: {$reason}",
            'System',
            $branch->uuid,
        );
    }

    public function ownerVerificationSubmitted(SpaBusiness $business, iterable $admins): void
    {
        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'New Owner Verification Submitted',
                "\"{$business->business_name}\" submitted owner identity and business verification for review.",
                'Verification',
                $business->uuid,
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
                'Verification',
                $business->uuid,
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
                'Verification',
                $business->uuid,
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
            'System',
            $branch->uuid,
        );

        $this->staffNotifier->branch($branch, 'Branch Suspended', "\"{$branch->branch_name}\" has been suspended by Servora. Contact your spa owner for details.", includeOwner: false, critical: true);
    }

    public function branchReactivated(SpaBranch $branch, User $owner): void
    {
        $this->notificationRepository->create(
            $owner->id,
            'Branch Reactivated',
            "\"{$branch->branch_name}\" has been reactivated and is no longer suspended.",
            'System',
            $branch->uuid,
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
                'System',
                $newAdmin->uuid,
            );
        }
    }

    // Fired from SubscriptionService::activateSubscriptionFromPayment — a
    // webhook-confirmed Xendit payment activating a business's subscription.
    // Reports the same real event as two separate facts, matching the two
    // admin-facing categories: a subscription started, and money moved.
    // A business bought a plan. ONE notification for the one thing that
    // happened: who, which plan, and what they paid. (It used to come with a
    // separate "Payment Received" for the same purchase.) $renewal: they have
    // paid for a plan before.
    public function subscriptionActivated(SpaBusiness $business, SubscriptionPlan $plan, string $billingCycle, iterable $admins, ?float $amount = null, bool $renewal = false): void
    {
        $did = $renewal ? 'renewed' : 'subscribed to';
        $paid = $amount !== null ? ' and paid ₱' . number_format($amount, 2) : '';

        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                $renewal ? 'Subscription Renewed' : 'New Subscription',
                "\"{$business->business_name}\" {$did} the {$plan->name} plan ({$billingCycle}){$paid}.",
                'Subscription',
                $business->uuid,
            );
        }
    }

    // Fired from NotifyAlmostDueSubscriptions (the daily scheduled command)
    // with the moment SubscriptionReminderSchedule says is due. Writes the
    // bell notification and hands back its title and message, so the email
    // that goes with it says exactly the same thing.
    /** @return array{0: string, 1: string} */
    public function subscriptionReminder(Subscription $subscription, string $moment, int $graceDays): array
    {
        [$title, $message] = $this->subscriptionReminderText($subscription, $moment, $graceDays);

        $this->notificationRepository->create($subscription->business->owner->id, $title, $message, 'Subscription');

        return [$title, $message];
    }

    // What each moment says. One sentence the owner can act on: what is
    // happening, when, and what to do.
    /** @return array{0: string, 1: string} */
    public function subscriptionReminderText(Subscription $subscription, string $moment, int $graceDays, ?\Carbon\Carbon $now = null): array
    {
        $now ??= now();
        $date = $subscription->expires_at->format('F j, Y');
        $days = SubscriptionReminderSchedule::daysLeft($subscription->expires_at, $now);
        $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");
        $ended = $moment === SubscriptionReminderSchedule::ENDED;

        // A free trial: nothing to renew, a plan to choose.
        if ($subscription->is_trial) {
            return $ended
                ? ['Free Trial Ended', "Your free trial ended on {$date}. Choose a plan to keep using Servora."]
                : ['Free Trial Ending', ($days === 0 ? 'Your free trial ends today.' : "Your free trial ends {$when}, on {$date}.") . ' Choose a plan to keep using Servora.'];
        }

        $planName = $subscription->plan->name ?? 'subscription';

        if ($ended) {
            return ['Subscription Access Paused', "Your {$planName} plan ended on {$date} and was not renewed, so your access is paused. Renew to pick up where you left off."];
        }

        if ($moment === SubscriptionReminderSchedule::OVERDUE) {
            // diffInDays returns a float (fractional days) — round up, so
            // "within 1 day" is still said on the last day.
            $left = max(0, (int) ceil($now->diffInDays($subscription->expires_at->copy()->addDays($graceDays), false)));
            $until = $left === 0 ? 'today' : ($left === 1 ? 'within 1 day' : "within {$left} days");

            $title = 'Subscription Payment Overdue';
            $message = "Your {$planName} plan expired on {$date}. Renew {$until} to keep access.";
        } elseif ($subscription->auto_renew && $subscription->paymentMethod) {
            // Auto-renew: nothing to do, just what will happen.
            $title = 'Subscription Renewal Reminder';
            $message = "Your {$planName} plan renews automatically on {$date}. We'll charge {$subscription->paymentMethod->label}.";
        } else {
            $title = 'Subscription Renewal Reminder';
            $message = $moment === SubscriptionReminderSchedule::DUE_TODAY
                ? "Your {$planName} plan is due today. Renew now to keep access."
                : "Your {$planName} plan is due {$when}, on {$date}. Renew before then to keep access.";
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

        return [$title, $message];
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
            $this->notificationRepository->create($admin->id, 'Plan Change Response', $message, 'Subscription', $subscription->business?->uuid);
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
            $this->notificationRepository->create($admin->id, $title, $message, 'Subscription', $subscription->business?->uuid);
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

    // A payment that has no notification of its own: an upgrade or an
    // automatic renewal. A plan purchase says what was paid in its own
    // notification (subscriptionActivated) and does not send this as well.
    public function paymentReceived(SpaBusiness $business, float $amount, iterable $admins): void
    {
        $formatted = number_format($amount, 2);

        foreach ($admins as $admin) {
            $this->notificationRepository->create(
                $admin->id,
                'Payment Received',
                "Received ₱{$formatted} from \"{$business->business_name}\".",
                'Payment',
                $business->uuid,
            );
        }
    }
}
