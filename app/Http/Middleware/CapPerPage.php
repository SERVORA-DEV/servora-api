<?php

namespace App\Http\Middleware;

use App\Support\PerPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No list answers with more than PerPage::MAX rows, whatever `per_page` asks for: a list that
 * keeps growing is paged and filtered by the API, never sent whole. Every API request, so a new
 * endpoint gets it without having to remember.
 */
class CapPerPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $asked = $request->query('per_page');
        if ($asked !== null && is_numeric($asked) && (int) $asked > PerPage::MAX) {
            $request->query->set('per_page', (string) PerPage::MAX);
        }

        return $next($request);
    }
}
