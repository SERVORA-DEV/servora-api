<?php

namespace App\Service\System;

use App\Models\SystemSetting;
use App\Repository\AuditLogRepository;
use App\Support\AppCache;
use App\Support\ListingPolicy;
use Illuminate\Support\Facades\Schema;

class SystemSettingService
{
    public function __construct(private AuditLogRepository $auditLogRepository) {}

    public function getSettings(): array
    {
        return $this->present(SystemSetting::current());
    }

    public function updateSettings(array $payload): array
    {
        $settings = SystemSetting::current();

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
        $old += $policyAudit[0];
        $new += $policyAudit[1];
        $settings->update($payload);

        if ($new) {
            $this->auditLogRepository->recordAdminAction('system_settings', $settings->id, 'Update', $old, $new);
        }

        // Which branches clients can see just changed.
        if ($policyChanged) {
            AppCache::bump('marketplace');
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
