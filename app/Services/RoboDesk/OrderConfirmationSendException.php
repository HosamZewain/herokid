<?php

namespace App\Services\RoboDesk;

use RuntimeException;

class OrderConfirmationSendException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
