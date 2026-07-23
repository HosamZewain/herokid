<?php

namespace App\Exceptions;

use RuntimeException;

class GptImageException extends RuntimeException
{
    public function __construct(
        string $safeMessage,
        public readonly ?string $errorCode = null,
        public readonly ?string $providerRequestId = null,
        public readonly bool $providerMayHaveBilled = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, previous: $previous);
    }
}
