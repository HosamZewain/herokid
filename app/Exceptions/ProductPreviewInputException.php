<?php

namespace App\Exceptions;

use RuntimeException;

class ProductPreviewInputException extends RuntimeException
{
    public function __construct(public readonly ?int $itemId, public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
