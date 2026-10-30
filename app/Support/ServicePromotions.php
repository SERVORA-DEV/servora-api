<?php

namespace App\Support;

// Which of the business's vouchers and discounts cover a service, for the little "promotion"
// mark on the front desk's service menu. Only the fact and a one-line label: the programs
// themselves (conditions, validity, who qualifies) stay on the Programs page.
//
// A promo is a plain array so this can be tested without a database:
//   ['type' => 'voucher', 'name' => …, 'label' => '20% off', 'target' => VoucherTarget::of(…), 'free_named' => ?string, 'members_only' => bool]
//   ['type' => 'discount', 'name' => …, 'label' => '10% off', 'applies_to' => 'All services', 'members_only' => bool]
final class ServicePromotions
{
    /**
     * @param  array<int, array<string, mixed>>  $promos
     * @return array<int, array{type: string, name: string, label: string, members_only: bool}>
     */
    public static function forService(array $promos, ?string $serviceUuid, ?string $category, ?string $serviceName): array
    {
        $found = [];
        foreach ($promos as $promo) {
            if (self::covers($promo, $serviceUuid, $category, $serviceName)) {
                $found[] = [
                    'type' => (string) $promo['type'],
                    'name' => (string) $promo['name'],
                    'label' => (string) $promo['label'],
                    'members_only' => (bool) ($promo['members_only'] ?? false),
                ];
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $promo */
    public static function covers(array $promo, ?string $serviceUuid, ?string $category, ?string $serviceName): bool
    {
        if (($promo['type'] ?? '') === 'voucher') {
            // An older free-service voucher for "all services" names its one service.
            $named = trim((string) ($promo['free_named'] ?? ''));
            if ($named !== '') {
                return $serviceName !== null && strcasecmp($named, trim($serviceName)) === 0;
            }

            return VoucherTarget::matches($promo['target'] ?? [], $serviceUuid, $category);
        }

        // A discount's "applies to" is free text: "All services", or a category / service name.
        $applies = trim((string) ($promo['applies_to'] ?? 'All services'));
        if ($applies === '' || preg_match('/^all\b/i', $applies)) {
            return true;
        }
        foreach ([$category, $serviceName] as $needle) {
            if ($needle !== null && trim($needle) !== '' && stripos($applies, trim($needle)) !== false) {
                return true;
            }
        }

        return false;
    }
}
