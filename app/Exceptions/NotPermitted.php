<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// Not this person's to do: their role, their branch, or their plan doesn't allow it. 403.
// (The other kinds of refusal are listed on ActionRefused.)
class NotPermitted extends AccessDeniedHttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
    }
}
