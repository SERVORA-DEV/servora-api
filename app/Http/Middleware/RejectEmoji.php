<?php

namespace App\Http\Middleware;

use App\Support\Emoji;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Anything a person sends to be kept is checked for emoji before it goes any
// further; see App\Support\Emoji for what counts and the one field allowed.
class RejectEmoji
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $fields = Emoji::fieldsIn($request->input());
            if ($fields) {
                $errors = [];
                foreach ($fields as $field) {
                    $label = str_replace('_', ' ', preg_replace('/\.\d+$/', '', (string) \Illuminate\Support\Arr::last(explode('.', $field))));
                    $errors[$field] = ["The {$label} can't contain emoji."];
                }

                return response()->json([
                    'message' => $errors[$fields[0]][0] . (count($fields) > 1 ? ' (and ' . (count($fields) - 1) . ' more)' : ''),
                    'errors' => $errors,
                ], 422);
            }
        }

        return $next($request);
    }
}
