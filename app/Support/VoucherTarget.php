<?php

namespace App\Support;

// Which services a voucher is about. A voucher carries two of these in its
// `values`: what it can be used on (prefix `usable`) and, for "after N
// visits", which visits count toward it (prefix `earn`):
//
//   usableKind      all | category | services
//   usableCategory  "Massage"                    (kind = category)
//   usableServices  service uuids, comma-joined  (kind = services)
//   usableOn        the readable form, written on save: "a Massage service"
//
// Pure rules over plain arrays, so they can be tested without a database.
// A service is ['uuid' => …, 'category' => …]; a bill line adds 'price'.
final class VoucherTarget
{
    public const ALL = 'all';
    public const CATEGORY = 'category';
    public const SERVICES = 'services';
    public const KINDS = [self::ALL, self::CATEGORY, self::SERVICES];

    // How many chosen services are named before "or N others".
    private const NAMED = 3;

    /** @return array{kind: string, category: string, services: string[]} */
    public static function of(?array $values, string $prefix): array
    {
        $values ??= [];
        $kind = (string) ($values[$prefix . 'Kind'] ?? self::ALL);
        $category = trim((string) ($values[$prefix . 'Category'] ?? ''));
        $services = self::uuids($values[$prefix . 'Services'] ?? '');

        // Anything half-set reads as "all": a voucher never ends up usable
        // on nothing because of a missing key.
        if (! in_array($kind, self::KINDS, true)
            || ($kind === self::CATEGORY && $category === '')
            || ($kind === self::SERVICES && ! $services)) {
            $kind = self::ALL;
        }

        return [
            'kind' => $kind,
            'category' => $kind === self::CATEGORY ? $category : '',
            'services' => $kind === self::SERVICES ? $services : [],
        ];
    }

    /** @return string[] */
    public static function uuids($joined): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $joined)))));
    }

    public static function isAll(array $target): bool
    {
        return ($target['kind'] ?? self::ALL) === self::ALL;
    }

    public static function matches(array $target, ?string $serviceUuid, ?string $category): bool
    {
        return match ($target['kind'] ?? self::ALL) {
            self::CATEGORY => $category !== null && strcasecmp(trim($category), $target['category']) === 0,
            self::SERVICES => $serviceUuid !== null && in_array($serviceUuid, $target['services'], true),
            default => true,
        };
    }

    /**
     * The line a voucher comes off: the dearest matching one. Lines with no
     * price are skipped (nothing to take off).
     *
     * @param  array<int, array{uuid: ?string, category: ?string, price: float|int|string}>  $lines
     */
    public static function priciest(array $target, array $lines): ?array
    {
        $best = null;
        foreach ($lines as $line) {
            $price = (float) ($line['price'] ?? 0);
            if ($price <= 0 || ! self::matches($target, $line['uuid'] ?? null, $line['category'] ?? null)) {
                continue;
            }
            if ($best === null || $price > (float) $best['price']) {
                $best = $line;
            }
        }

        return $best;
    }

    /**
     * How many visits count: one per visit with at least one matching
     * service, however many it had.
     *
     * @param  array<int, array<int, array{uuid: ?string, category: ?string}>>  $visits  each visit's services
     */
    public static function visits(array $target, array $visits): int
    {
        if (self::isAll($target)) {
            return count($visits);
        }
        $count = 0;
        foreach ($visits as $services) {
            foreach ($services as $service) {
                if (self::matches($target, $service['uuid'] ?? null, $service['category'] ?? null)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * What a voucher takes off the line it applies to.
     * $deal is the form's "₱ off" | "% off" | "Free service".
     */
    public static function amountOff(string $deal, float $value, float $price): float
    {
        return round(match ($deal) {
            'Free service' => $price,
            '% off' => $price * min(max($value, 0), 100) / 100,
            default => min(max($value, 0), $price),
        }, 2);
    }

    /**
     * "a Massage service", "Swedish Massage or Hilot", "all services".
     *
     * @param  string[]  $names  the chosen services' names, in the order to show
     */
    public static function phrase(array $target, array $names = []): string
    {
        return match ($target['kind'] ?? self::ALL) {
            self::CATEGORY => 'a ' . $target['category'] . ' service',
            self::SERVICES => self::anyOf($names),
            default => 'All services',
        };
    }

    // The same without the article, for "Free …": "Massage service".
    public static function freePhrase(array $target, array $names = []): string
    {
        return ($target['kind'] ?? self::ALL) === self::CATEGORY ? $target['category'] . ' service' : self::phrase($target, $names);
    }

    private static function anyOf(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names)));
        if (! $names) {
            return 'chosen services';
        }
        if (count($names) > self::NAMED) {
            $rest = count($names) - (self::NAMED - 1);
            $names = [...array_slice($names, 0, self::NAMED - 1), $rest . ' others'];
        }
        $last = array_pop($names);

        return $names ? implode(', ', $names) . ' or ' . $last : $last;
    }
}
