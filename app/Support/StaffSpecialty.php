<?php

namespace App\Support;

// What a member of staff does. "Staff" is the general word for everyone who
// performs services (the role saved as 'therapist'); a specialty says which
// kind — a nail technician, a hair stylist, a massage therapist. A person can
// have several; the first is their main one, shown beside their name.
//
// A label only: which services someone may be given is set per person
// (staff_services), not by this.
//
// The list is fixed, the same for every business. Pure — tested in
// tests/Unit/StaffSpecialtyTest; mirrored on the site in
// app/utils/staff-specialties.ts (keep the two in step).
class StaffSpecialty
{
    public const ALL = [
        'Therapist',
        'Nail Technician',
        'Hair Stylist',
        'Beautician',
        'Makeup Artist',
        'Lash Technician',
        'Brow Technician',
        'Nail Artist',
        'Hair Colorist',
        'Spa Attendant',
        'Skincare Specialist',
        'Massage Therapist',
        'Waxing Specialist',
        'Hair Treatment Specialist',
        'Body Treatment Specialist',
    ];

    /** What someone is until they are given a specialty of their own. */
    public const FALLBACK = 'Therapist';

    /** The role that has specialties: the people who perform services. */
    public const ROLE = 'therapist';

    /**
     * The specialties in a list that are real ones, each once, in the order
     * given (the first is the main one). Spelling is forgiven on case and
     * spaces; anything unknown is dropped.
     *
     * @return string[]
     */
    public static function clean(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $known = [];
        foreach (self::ALL as $name) {
            $known[self::tidy($name)] = $name;
        }

        $kept = [];
        foreach ($list as $item) {
            $name = is_string($item) ? ($known[self::tidy($item)] ?? null) : null;
            if ($name !== null && ! in_array($name, $kept, true)) {
                $kept[] = $name;
            }
        }

        return $kept;
    }

    /** The main one: the first. */
    public static function main(mixed $list): ?string
    {
        return self::clean($list)[0] ?? null;
    }

    /** "Nail Technician", or "Nail Technician +1" with more than one. */
    public static function label(mixed $list): string
    {
        $kept = self::clean($list);
        if (! $kept) {
            return '';
        }

        return $kept[0] . (count($kept) > 1 ? ' +' . (count($kept) - 1) : '');
    }

    /**
     * What to save for someone of this role: nothing for a manager or the
     * front desk; for staff, what was given — or what they had, or the
     * fallback, when nothing was.
     *
     * @return string[]
     */
    public static function forRole(?string $role, mixed $given, mixed $had = null): array
    {
        if ($role !== self::ROLE) {
            return [];
        }

        return self::clean($given) ?: (self::clean($had) ?: [self::FALLBACK]);
    }

    private static function tidy(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));
    }
}
