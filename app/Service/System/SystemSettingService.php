<?php

namespace App\Service\System;

use App\Models\SystemSetting;
use App\Repository\AuditLogRepository;

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
        [$old, $new] = $this->auditLogRepository->diff($settings->only(array_keys($payload)), $payload);
        $settings->update($payload);

        if ($new) {
            $this->auditLogRepository->recordAdminAction('system_settings', $settings->id, 'Update', $old, $new);
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
            ],
        ];
    }
}
