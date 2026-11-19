<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The request is understood, but the spa's rules say no: the visit is closed, the slot is taken,
 * the bill is already made. Answered as 422 with the message for the person to read.
 *
 * The refusals of the API, by kind (each answers as `{"message": …}` with its status):
 *   ActionRefused          422  a rule says no
 *   NotPermitted           403  not this person's to do (role, branch, plan)
 *   NotFound               404  not there, or no longer
 *   ChangedElsewhere       409  changed in another session since it was opened; reload
 *   NotSetUp               503  a part of Servora this server isn't set up for yet
 *   PaymentProviderFailed  502  the payment provider didn't answer as it should
 * They are Symfony HTTP exceptions underneath, so Laravel renders them as it rendered abort().
 */
class ActionRefused extends UnprocessableEntityHttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
    }
}
