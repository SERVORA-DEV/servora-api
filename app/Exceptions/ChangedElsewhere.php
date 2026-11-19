<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// What the person was looking at changed in another session since they opened it; they reload
// and decide again. 409. (The other kinds of refusal are listed on ActionRefused.)
class ChangedElsewhere extends ConflictHttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
    }
}
