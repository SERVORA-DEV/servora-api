<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Stamps every response with the server's clock (milliseconds since the
// epoch). The web and mobile apps read it to work out how far the device's
// own clock is off, and use the server's time for "now" and "today" — so a
// laptop or phone whose clock was changed still shows the right day, the
// right free times and the right countdowns. Nothing is ever *stored* from a
// device's clock; this is only so what is shown agrees with what is stored.
class ServerTime
{
    public const HEADER = 'X-Server-Time';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set(self::HEADER, (string) (int) round(microtime(true) * 1000));

        return $response;
    }
}
