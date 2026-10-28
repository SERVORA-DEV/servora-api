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
                    $errors[$field] = [Emoji::message($field)];
                }

                // A password and its confirmation are one complaint, not two.
                $said = array_values(array_unique(array_map(fn ($messages) => $messages[0], $errors)));

                return response()->json([
                    'message' => $said[0] . (count($said) > 1 ? ' (and ' . (count($said) - 1) . ' more)' : ''),
                    'errors' => $errors,
                ], 422);
            }
        }

        return $next($request);
    }
}
