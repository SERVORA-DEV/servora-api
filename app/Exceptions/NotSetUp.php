<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

// A part of Servora this server isn't set up for yet: its tables haven't been migrated, or
// payments are still on the demo lock. 503, with no Retry-After (it won't fix itself).
// (The other kinds of refusal are listed on ActionRefused.)
class NotSetUp extends ServiceUnavailableHttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(null, $message);
    }
}
