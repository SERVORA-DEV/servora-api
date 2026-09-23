<?php

namespace App\Service;

use App\Http\Resources\SubscriptionPlanResource;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\System\AdminUsersRepository;

// When an admin edits a plan that already has subscribers, the plan is
// versioned (SubscriptionPlanRepository::archiveAndVersion) — subscribers
// keep the archived version's terms until their current term ends, and the
// new version only applies from their next renewal. This service tells
// those owners and records their answer:
//   pending  — notified, no answer yet (treated as accepted: a renewal can
//              only ever buy the new version, the archived one is gone)
//   accepted — renew onto the new version
//   declined — let the subscription end at expires_at: no grace period, no
//              more reminders (see SubscriptionRepository's notDeclined)
class PlanChangeService
{
    // Feature flags compared in describeChanges(), with display labels.
    private const FEATURES = [
        'package_access' => 'Packages',
        'reward_access' => 'Rewards',
        'review_access' => 'Reviews',
        'report_access' => 'Reports',
        'report_export' => 'Report export',
        'mobile_app_access' => 'Mobile app',
    ];

    // API convention for "no limit" on max_branches / max_user_accounts.
    private const UNLIMITED = 9999;

    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private SpaBusinessRepository $businessRepository,
        private AdminUsersRepository $adminUsersRepository,
        private NotificationService $notificationService,
    ) {}

    // Called from SubscriptionPlanService::updateSubscriptionPlan's
    // versioned branch. Also re-targets owners who were already pending on
    // $archived (the admin edited the plan again before they answered) —
    // any earlier answer is reset, since it was about different terms.
    public function notifySubscribers(SubscriptionPlan $archived, SubscriptionPlan $newVersion): int
    {
        $graceDays = SystemSetting::current()->subscription_grace_period_days;
        $subscriptions = $this->subscriptionRepository->findLiveOnPlan($archived->id, $graceDays);

        foreach ($subscriptions as $subscription) {
            $subscription->update([
                'pending_plan_id' => $newVersion->id,
                'plan_change_status' => 'pending',
                'plan_change_responded_at' => null,
            ]);

            if ($subscription->business?->owner) {
                $this->notificationService->subscriptionPlanChanged(
                    $subscription,
                    $newVersion,
                    $this->describeChanges($subscription->plan, $newVersion),
                );
            }
        }

        return $subscriptions->count();
    }

    // The owner's answer — only while their current term is still running
    // (after expires_at the question is moot: renewing means buying the
    // current version anyway). Can be changed any number of times until then.
    public function respond(User $owner, string $decision)
    {
        $subscription = $this->currentPendingFor($owner);

        if (! $subscription) {
            return response()->json([
                'message' => 'There is no plan change waiting for your answer.',
            ], 422);
        }

        $subscription->update([
            'plan_change_status' => $decision === 'accept' ? 'accepted' : 'declined',
            'plan_change_responded_at' => now(),
        ]);

        $subscription->loadMissing(['business', 'pendingPlan']);
        $this->notificationService->planChangeResponded(
            $subscription,
            $decision,
            $this->adminUsersRepository->allAdministrators(),
        );

        return response()->json([
            'message' => $decision === 'accept'
                ? 'You\'ll move to the updated plan when you renew.'
                : 'Your subscription will end on ' . $subscription->expires_at->format('F j, Y') . '.',
            'plan_change' => $this->present($subscription),
        ], 200);
    }

    // Shape returned inside GET /business/subscription as `plan_change`,
    // or null when there's nothing to show.
    public function present(Subscription $subscription): ?array
    {
        if (! $subscription->pending_plan_id || ! $this->isStillRunning($subscription)) {
            return null;
        }

        $subscription->loadMissing(['plan', 'pendingPlan']);

        if (! $subscription->pendingPlan || ! $subscription->plan) {
            return null;
        }

        return [
            'status' => $subscription->plan_change_status,
            'responded_at' => $subscription->plan_change_responded_at,
            'effective_at' => $subscription->expires_at,
            'current_plan' => new SubscriptionPlanResource($subscription->plan),
            'new_plan' => new SubscriptionPlanResource($subscription->pendingPlan),
            'changes' => $this->describeChanges($subscription->plan, $subscription->pendingPlan),
        ];
    }

    /**
     * Human-readable differences from the owner's CURRENT plan (not just the
     * previous version) to the new one — what will actually change for them.
     *
     * @return string[]
     */
    public function describeChanges(SubscriptionPlan $from, SubscriptionPlan $to): array
    {
        $changes = [];

        if ($from->name !== $to->name) {
            $changes[] = "Name: {$from->name} → {$to->name}";
        }

        // Compared on the DISPLAYED value, so e.g. 9999 → 10000 branches
        // (both "Unlimited") isn't reported as a change.
        foreach (['monthly_price' => 'Monthly price', 'yearly_price' => 'Yearly price'] as $field => $label) {
            [$old, $new] = [$this->money($from->{$field}), $this->money($to->{$field})];
            if ($old !== $new) {
                $changes[] = "{$label}: {$old} → {$new}";
            }
        }

        foreach (['max_branches' => 'Max branches', 'max_user_accounts' => 'Max user accounts'] as $field => $label) {
            [$old, $new] = [$this->limit($from->{$field}), $this->limit($to->{$field})];
            if ($old !== $new) {
                $changes[] = "{$label}: {$old} → {$new}";
            }
        }

        foreach (self::FEATURES as $field => $label) {
            if ((bool) $from->{$field} !== (bool) $to->{$field}) {
                $changes[] = $label . ': ' . ($to->{$field} ? 'added' : 'removed');
            }
        }

        return $changes;
    }

    private function currentPendingFor(User $owner): ?Subscription
    {
        $business = $this->businessRepository->findByOwnerId($owner->id);
        $subscription = $business ? $this->subscriptionRepository->findLatestForBusiness($business->id) : null;

        if (! $subscription || ! $subscription->pending_plan_id || $subscription->status !== 'Active') {
            return null;
        }

        return $this->isStillRunning($subscription) ? $subscription : null;
    }

    private function isStillRunning(Subscription $subscription): bool
    {
        return $subscription->status === 'Active'
            && ($subscription->expires_at === null || $subscription->expires_at->isFuture());
    }

    private function money($value): string
    {
        return $value === null ? 'Not offered' : '₱' . number_format((float) $value, 2);
    }

    private function limit($value): string
    {
        return (int) $value >= self::UNLIMITED ? 'Unlimited' : (string) (int) $value;
    }
}
