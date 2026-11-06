<?php

namespace App\Http\Middleware;

use App\Support\NameCase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Whatever is sent to be kept, a person's name gets its capital letters
// (App\Support\NameCase) before it is validated or saved: the web app, the
// mobile app and anything else all end up with "Juan Dela Cruz".
class CapitalizeNames
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            // The body only (JSON or form fields): never the query string, never files.
            $bag = $request->isJson() ? $request->json() : $request->request;
            $before = $bag->all();
            $after = NameCase::applyTo($before);
            if ($after !== $before) {
                $bag->replace($after);
            }
        }

        return $next($request);
    }
}
