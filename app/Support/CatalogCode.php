<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

// The code a service or a package gets when it is created: SVC-0001, SVC-0002… and PKG-0001, PKG-0002…,
// counted per business. It is given automatically and never typed or changed by the owner; a code that
// was typed before this (a "BM") is left as it is and never reused as a number.
final class CatalogCode
{
    public const SERVICE = 'SVC';
    public const PACKAGE = 'PKG';

    /**
     * The next code for a business: one more than the highest number of its codes with this prefix
     * (deleted ones included, so a code is never handed out twice).
     *
     * @param  class-string<Model>  $model  Service or Package
     */
    public static function next(string $model, int $businessId, string $prefix): string
    {
        $length = strlen($prefix) + 1;
        $highest = $model::withTrashed()
            ->where('spa_business_id', $businessId)
            ->where('code', 'like', $prefix . '-%')
            ->pluck('code')
            ->map(fn (string $code) => ctype_digit(substr($code, $length)) ? (int) substr($code, $length) : 0)
            ->max();

        return $prefix . '-' . str_pad((string) (($highest ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }
}
