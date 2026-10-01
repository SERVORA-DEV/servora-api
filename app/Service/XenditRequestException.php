<?php

namespace App\Service;

use RuntimeException;

// A Xendit API call that came back with an error — keeps Xendit's error code
// so callers can tell "card declined" from "our request was wrong".
class XenditRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
