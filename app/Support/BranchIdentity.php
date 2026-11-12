<?php

namespace App\Support;

use App\Models\SpaBranch;

// What tells one of a business's branches from another: its name and its area together. Two branches
// may share a name ("Serenity Spa") when they are in different areas ("Pilar", "Manila"), and two may
// share an area when their names differ; only the same name in the same area (or both with no area) is
// the same branch twice. Names and areas are compared without regard to case or surrounding spaces.
final class BranchIdentity
{
    /** Whether the business already has a (not deleted) branch with this name in this area. */
    public static function taken(?int $businessId, ?string $name, ?string $area, ?string $exceptUuid = null): bool
    {
        $name = trim((string) $name);
        $area = trim((string) $area);
        if (! $businessId || $name === '') {
            return false;
        }

        return SpaBranch::where('spa_business_id', $businessId)
            ->whereRaw('lower(trim(branch_name)) = ?', [mb_strtolower($name)])
            ->when(
                $area === '',
                fn ($q) => $q->where(fn ($w) => $w->whereNull('code')->orWhereRaw("trim(code) = ''")),
                fn ($q) => $q->whereRaw('lower(trim(code)) = ?', [mb_strtolower($area)]),
            )
            ->when($exceptUuid, fn ($q) => $q->where('uuid', '!=', $exceptUuid))
            ->exists();
    }

    /** How a branch is shown in lists: "Name · Area" (just the name without an area). */
    public static function title(?string $name, ?string $area): string
    {
        $area = trim((string) $area);

        return trim((string) $name) . ($area !== '' ? ' · ' . $area : '');
    }

    /** What to tell the owner when it is. */
    public static function message(?string $area): string
    {
        return trim((string) $area) === ''
            ? 'You already have a branch with this name. Give this one an area to tell them apart.'
            : 'You already have a branch with this name in this area.';
    }
}
