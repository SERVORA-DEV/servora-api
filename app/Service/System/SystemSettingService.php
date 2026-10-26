<?php

namespace App\Service\System;

use App\Models\SystemSetting;
use App\Repository\AuditLogRepository;
use App\Support\AppCache;
use App\Repository\NotificationRepository;
use App\Service\Business\StaffNotifier;
use App\Support\ListingPolicy;
use App\Support\PlatformSettingImpact;
use Illuminate\Support\Facades\Schema;

class SystemSettingService
{
    public function __construct(private AuditLogRepository $auditLogRepository) {}

    public function getSettings(): array
    {
        return $this->present(SystemSetting::current());
    }

    // What saving these settings would do to businesses that are operating, so the
    // administrator can see it first: branches that would stop being listed, and
    // owners who would lose access at once.
    public function preview(array $payload): array
    {
        $hidden = PlatformSettingImpact::branchesHiddenBy($payload['listing_policy'] ?? null);
        $cut = PlatformSettingImpact::subscriptionsCutBy(isset($payload['subscription_grace_period_days']) ? (int) $payload['subscription_grace_period_days'] : null);

        return ['data' => [
            'branches_hidden' => [
                'count' => $hidden->count(),
                'names' => $hidden->take(8)->map(fn ($b) => trim(($b->business?->business_name ?? '') . ' · ' . $b->branch_name, ' ·'))->values()->all(),
            ],
            'owners_locked_out' => [
                'count' => $cut->count(),
                'names' => $cut->take(8)->map(fn ($s) => $s->business?->business_name ?? 'A business')->values()->all(),
            ],
        ]];
    }

    public function updateSettings(array $payload): array
    {
        $settings = SystemSetting::current();

        // Worked out against what is in force now, before it changes.
        $hidden = PlatformSettingImpact::branchesHiddenBy($payload['listing_policy'] ?? null);
        $added = PlatformSettingImpact::newlyRequired($payload['listing_policy'] ?? null);
        $cut = PlatformSettingImpact::subscriptionsCutBy(isset($payload['subscription_grace_period_days']) ? (int) $payload['subscription_grace_period_days'] : null);

        // Marketplace Policy: always stored whole, and logged item by item so
        // the audit trail reads "promo: optional → required".
        $policyChanged = false;
        $policyAudit = [[], []];
        if (array_key_exists('listing_policy', $payload)) {
            if (! Schema::hasColumn('system_settings', 'listing_policy')) {
                abort(422, 'The marketplace policy can\'t be saved yet: the database is behind the code. Run `php artisan migrate` first.');
            }
            $before = $settings->listingPolicy();
            $after = ListingPolicy::normalize(array_merge($before, $payload['listing_policy']));
            $policyChanged = $after !== $before;
            foreach ($after as $item => $value) {
                if ($before[$item] !== $value) {
                    $policyAudit[0]["listing_policy.{$item}"] = $before[$item];
                    $policyAudit[1]["listing_policy.{$item}"] = $value;
                }
            }
            $payload['listing_policy'] = $after;
        }

        $scalars = array_diff_key($payload, ['listing_policy' => true]);
        [$old, $new] = $this->auditLogRepository->diff($settings->only(array_keys($scalars)), $scalars);
        // A save of only the marketplace policy has no scalar changes: the diff is null then.
        $old = ($old ?? []) + $policyAudit[0];
        $new = ($new ?? []) + $policyAudit[1];
        $settings->update($payload);

        if ($new) {
            $this->auditLogRepository->recordAdminAction('system_settings', $settings->id, 'Update', $old, $new);
        }

        // Which branches clients can see just changed.
        if ($policyChanged) {
            AppCache::bump('marketplace');
        }

        // Tell the owners it reached: a branch no longer listed, access paused.
        foreach ($hidden as $branch) {
            try {
                app(StaffNotifier::class)->branch(
                    $branch,
                    'Your branch is no longer shown to clients',
                    "{$branch->branch_name} is not listed in the app until it has: " . implode(', ', PlatformSettingImpact::missingLabels($branch, $added)) . '. Servora now requires it. Add it in Branch Settings to be listed again.',
                    type: 'System',
                    roles: ['manager'],
                    critical: true,
                    reference: $branch->uuid,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
        foreach ($cut as $subscription) {
            try {
                if ($owner = $subscription->business?->owner) {
                    app(NotificationRepository::class)->create(
                        $owner->id,
                        'Your access is paused',
                        'Servora shortened the grace period after a subscription ends, and yours had already ended. Renew your subscription to get your access back.',
                        'Subscription',
                        $subscription->business->uuid,
                    );
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->present($settings);
    }

    private function present(SystemSetting $settings): array
    {
        return [
            'data' => [
                'subscription_grace_period_days' => $settings->subscription_grace_period_days,
                'almost_due_notify_enabled' => $settings->almost_due_notify_enabled,
                'almost_due_notify_days_before' => $settings->almost_due_notify_days_before,
                'almost_due_repeat_enabled' => $settings->almost_due_repeat_enabled,
                'almost_due_repeat_every_days' => $settings->almost_due_repeat_every_days,
                'plan_changes_enabled' => $settings->plan_changes_enabled,
                'listing_policy' => $settings->listingPolicy(),
            ],
        ];
    }
}
