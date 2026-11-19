<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How many rows a list answers with: what the caller asked for (`per_page`), kept between 1
 * and MAX. No screen needs more than a page of 100; a list that keeps growing (visits, bills,
 * clients, logs) is paged and filtered by the API, never sent whole.
 */
final class PerPage
{
    public const MAX = 100;

    public static function from(Request $request, int $default = 15, int $max = self::MAX): int
    {
        $asked = filter_var($request->input('per_page'), FILTER_VALIDATE_INT);

        return max(1, min($max, $asked === false ? $default : (int) $asked));
    }
}
