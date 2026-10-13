<?php

namespace App\Support;

// Add-ons: small extras put on a service during the visit — hot stones on a
// massage, a scalp treatment on a haircut. An add-on is its own thing on the
// owner's menu (App\Models\Addon), not a service: a name, a price, the time it
// adds, and where it is available —
//
//   categories    the service categories it goes with (Ventosa is for
//                 Massage, never for Nails);
//   all services  every service in those categories, or only the ones the
//                 owner ticked.
//
// On a visit an add-on is a line of its own under its main service: charged
// at its own price, and it rides with the main — same therapist and room,
// starts and completes with it, and its time is added to the main's.
//
// Pure — tested in tests/Unit/ServiceAddonTest; mirrored on the site in
// app/utils/serviceAddon.ts.
class ServiceAddon
{
    /** Is this category one of the add-on's? (Compared loosely: case and spaces don't matter.) */
    public static function inCategories(array $categories, ?string $category): bool
    {
        $wanted = self::tidy($category);
        if ($wanted === '') {
            return false;
        }

        return in_array($wanted, array_map(fn ($c) => self::tidy(is_string($c) ? $c : null), $categories), true);
    }

    /**
     * Does this add-on go with a service of this category and id?
     *
     * @param  array{categories?: array, all_services?: bool, service_ids?: int[]}  $addon
     */
    public static function appliesTo(array $addon, ?string $serviceCategory, ?int $serviceId): bool
    {
        if (! self::inCategories($addon['categories'] ?? [], $serviceCategory)) {
            return false;
        }
        if ($addon['all_services'] ?? true) {
            return true;
        }

        return $serviceId !== null && in_array((int) $serviceId, array_map('intval', $addon['service_ids'] ?? []), true);
    }

    /**
     * Why this add-on can't be put on this service of the visit, or null.
     *
     * @param  array{status: ?string, is_addon: bool, service_id: ?int, category: ?string, name?: ?string}  $main
     *         the visit line it would go on
     * @param  array{id: ?int, name?: ?string, is_active?: bool, categories?: array, all_services?: bool, service_ids?: int[]}  $addon
     * @param  int[]  $alreadyOn  the add-ons already on that line
     * @param  ?string  $visitStatus  the appointment's status
     */
    public static function problem(array $main, array $addon, array $alreadyOn, ?string $visitStatus, bool $billed): ?string
    {
        $name = $addon['name'] ?? 'This add-on';
        $onto = $main['name'] ?? 'this service';

        if (in_array($visitStatus, ['cancelled', 'no_show', 'completed'], true)) {
            return 'This visit is closed — nothing can be added to it.';
        }
        if ($billed) {
            return 'The bill has already been made — an add-on can no longer be added.';
        }
        if ($main['is_addon'] ?? false) {
            return 'An add-on can\'t have add-ons of its own.';
        }
        if (! in_array($main['status'] ?? null, ['Pending', 'In Progress'], true)) {
            return "{$onto} is already finished or removed — add-ons go on a service that is waiting or in progress.";
        }
        if (! ($addon['is_active'] ?? true)) {
            return "{$name} is switched off.";
        }
        if (! self::appliesTo($addon, $main['category'] ?? null, $main['service_id'] ?? null)) {
            return "{$name} isn't offered with {$onto}.";
        }
        if (($addon['id'] ?? null) !== null && in_array((int) $addon['id'], array_map('intval', $alreadyOn), true)) {
            return "{$name} is already on {$onto}.";
        }

        return null;
    }

    /**
     * A main service's whole length: its own plus its add-ons'.
     *
     * @param  array<int, ?int>  $addonMinutes
     */
    public static function totalMinutes(?int $own, array $addonMinutes): int
    {
        return max(0, (int) $own) + self::addedMinutes($addonMinutes);
    }

    /** @param  array<int, ?int>  $addonMinutes */
    public static function addedMinutes(array $addonMinutes): int
    {
        return (int) array_sum(array_map(fn ($m) => max(0, (int) $m), $addonMinutes));
    }

    /**
     * Of the services the owner ticked, the ones that are not in the add-on's
     * categories (so the form can say which, instead of quietly dropping them).
     *
     * @param  array<int, ?string>  $categoryByServiceId
     * @return int[]
     */
    public static function outsideCategories(array $categories, array $categoryByServiceId): array
    {
        $outside = [];
        foreach ($categoryByServiceId as $id => $category) {
            if (! self::inCategories($categories, $category)) {
                $outside[] = (int) $id;
            }
        }

        return $outside;
    }

    private static function tidy(?string $text): string
    {
        return mb_strtolower(trim((string) $text));
    }
}
