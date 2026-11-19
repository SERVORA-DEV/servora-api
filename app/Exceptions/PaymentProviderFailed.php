<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

// The payment provider (Xendit) didn't answer as it should, so the step can't go on; trying again
// later may work. 502. (The other kinds of refusal are listed on ActionRefused.)
class PaymentProviderFailed extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(502, $message);
    }
}
