<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Not there, or no longer there (or not visible to this person). 404.
// (The other kinds of refusal are listed on ActionRefused.)
class NotFound extends NotFoundHttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
    }
}
